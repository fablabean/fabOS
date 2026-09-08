<?php

namespace App\Services\Training;

use App\Models\Enrollment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingService;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La prueba practica de un curso, agendada (§9).
 *
 * Quien aprobaba el examen teorico se quedaba con una frase —«falta la
 * evaluacion presencial»— y sin forma de pedirla: tenia que escribirle a
 * alguien, y ese alguien buscar un hueco a mano.
 *
 * Ahora es como una asesoria: el sistema ofrece las horas en que alguien
 * del area del curso puede verla, elige a quien le toca con el mismo turno,
 * y reserva su tiempo. La coordinacion tambien puede citar a la persona a
 * una hora concreta con un evaluador concreto.
 *
 * Y al firmarla en el panel, si ya no falta nada, el certifab sale solo: la
 * firma ES la decision, y pedir otro clic para «aprobar» era pedir lo mismo
 * dos veces.
 */
class PracticaService
{
    public function __construct(
        private AsesoriaService $asesorias,
        private BookingService $reservas,
        private NotificationService $avisos,
    ) {}

    public function minutos(): int
    {
        return (int) config('fabos.formacion.practica_minutos', 60);
    }

    /**
     * Las horas en que alguien del area del curso puede ver la practica.
     *
     * @return Collection<int,array{inicio:CarbonInterface,fin:CarbonInterface,cuantos:int}>
     */
    public function franjas(Enrollment $inscripcion): Collection
    {
        $area = $inscripcion->edition?->course?->area;

        if (! $area) {
            return collect();
        }

        return $this->asesorias->franjasDisponibles(
            $area,
            $inscripcion->user,
            (int) config('fabos.asesorias.dias_vista', 7),
            $this->minutos(),
        );
    }

    /**
     * Las horas en que un evaluador concreto puede ver la practica, para
     * ofrecerlas al citar desde el panel: se elige una, no se adivina.
     *
     * @return array<string,string>  «2026-09-08 17:00» => «Mar 08/09 · 17:00–18:00»
     */
    public function horasDe(User $evaluador, Enrollment $inscripcion, int $dias = 7): array
    {
        return $this->asesorias
            ->franjasDe($evaluador, $inscripcion->user, $dias, $this->minutos())
            ->mapWithKeys(fn (array $f) => [
                $f['inicio']->format('Y-m-d H:i') => ucfirst(rtrim($f['inicio']->locale('es')->isoFormat('ddd'), '.'))
                    . ' ' . $f['inicio']->format('d/m')
                    . ' · ' . $f['inicio']->format('H:i') . '–' . $f['fin']->format('H:i'),
            ])
            ->all();
    }

    /**
     * La persona pide hora, y el sistema elige quien la ve.
     *
     * @throws TrainingException
     */
    public function agendar(Enrollment $inscripcion, CarbonInterface $desde, ?CarbonInterface $hasta = null): Reservation
    {
        $this->exigirQueSePuedaAgendar($inscripcion);

        $hasta ??= $desde->copy()->addMinutes($this->minutos());
        $area = $inscripcion->edition->course->area;

        if (! $area) {
            throw new TrainingException('Este curso no tiene área: no hay a quién asignarle la práctica.');
        }

        $reserva = DB::transaction(function () use ($inscripcion, $area, $desde, $hasta) {
            if ($this->asesorias->tieneAlgoALaMismaHora($inscripcion->user, $desde, $hasta)) {
                throw new TrainingException('Ya tienes algo reservado a esa hora.');
            }

            $evaluador = $this->asesorias->elegir($area, $desde, $hasta, $inscripcion->user);

            if (! $evaluador) {
                throw new TrainingException('Justo esa hora acaba de ocuparse. Elige otra de la lista.');
            }

            return $this->reservar($inscripcion, $evaluador, $desde, $hasta);
        });

        $this->avisar($reserva, 'practica.agendada');

        return $reserva;
    }

    /**
     * La coordinacion cita a la persona: hora y evaluador concretos.
     *
     * @throws TrainingException
     */
    public function citar(
        Enrollment $inscripcion,
        User $evaluador,
        CarbonInterface $desde,
        ?CarbonInterface $hasta = null,
        ?string $nota = null,
    ): Reservation {
        $this->exigirQueSePuedaAgendar($inscripcion);

        $hasta ??= $desde->copy()->addMinutes($this->minutos());

        if ($desde->isPast()) {
            throw new TrainingException('Esa hora ya pasó.');
        }

        if ($evaluador->id === $inscripcion->user_id) {
            throw new TrainingException('Nadie se evalúa a sí mismo.');
        }

        if ($ocupado = $this->reservas->porQueNoEstaLibre($evaluador, $desde, $hasta)) {
            throw new TrainingException($ocupado . ' Elige a otra persona u otra hora.');
        }

        $reserva = DB::transaction(fn () => $this->reservar($inscripcion, $evaluador, $desde, $hasta, $nota));

        $this->avisar($reserva, 'practica.citada', $nota);

        return $reserva;
    }

    /**
     * La persona no vino a la practica. Lo dice quien la tenia asignada.
     *
     * La cita queda como no presentada —con quien lo dijo— y la persona
     * puede pedir otra hora. Sin esto, una practica sin firmar se quedaba
     * en el aire: ni aprobada ni cerrada.
     *
     * @throws TrainingException
     */
    public function noVino(Enrollment $inscripcion, User $quien, ?string $nota = null): Reservation
    {
        $practica = $inscripcion->practicaSinValidar() ?? $inscripcion->practicaAgendada();

        if (! $practica) {
            throw new TrainingException('No hay una práctica agendada que cerrar.');
        }

        if (! $inscripcion->puedeEvaluarLaPractica($quien)) {
            throw new TrainingException(
                'Eso lo dice ' . ($inscripcion->evaluadorAsignado()?->name ?? 'quien la evaluaba') . ', que era quien la esperaba.'
            );
        }

        if ($practica->starts_at->isFuture()) {
            throw new TrainingException('Esa práctica todavía no ha empezado.');
        }

        $practica->update([
            'status'        => 'no_show',
            'status_reason' => trim('No se presentó a la práctica, según ' . $quien->name . ($nota ? '. ' . $nota : '')),
        ]);

        if ($inscripcion->user) {
            $tz = config('fabos.lab.timezone');

            $this->avisos->enviar('practica.no_asistio', $inscripcion->user, [
                'curso'     => $inscripcion->edition?->course?->name ?? 'el curso',
                'fecha'     => $practica->starts_at->timezone($tz)->format('d/m/Y'),
                'inicio'    => $practica->starts_at->timezone($tz)->format('H:i'),
                'evaluador' => $quien->name,
                'nota'      => $nota ?: '',
            ], $practica);
        }

        return $practica->refresh();
    }

    /**
     * Firmada la practica, la reserva que la sostenia se cierra: la persona
     * vino, y quien evaluo ya no tiene esa hora pendiente.
     */
    public function cerrarAlFirmar(Enrollment $inscripcion): void
    {
        $inscripcion->practicas()
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->get()
            ->each(fn (Reservation $r) => $r->update([
                'status'        => 'completada',
                'checked_in_at' => $r->checked_in_at ?? $r->starts_at,
                'status_reason' => 'Práctica firmada',
            ]));
    }

    // ---------------------------------------------------------------- dentro

    /**
     * @throws TrainingException
     */
    private function exigirQueSePuedaAgendar(Enrollment $inscripcion): void
    {
        $inscripcion->loadMissing('edition.course', 'user');
        $curso = $inscripcion->edition?->course;

        if ($inscripcion->status !== 'inscrito') {
            throw new TrainingException('Esta inscripción está ' . mb_strtolower(Enrollment::ESTADOS[$inscripcion->status] ?? $inscripcion->status) . '.');
        }

        if (! $curso?->requires_practical) {
            throw new TrainingException('Este curso no tiene prueba práctica.');
        }

        if (! $inscripcion->teoriaLista()) {
            throw new TrainingException('Primero hay que aprobar el examen teórico: la práctica se evalúa sobre eso.');
        }

        if ($inscripcion->practicaAprobada()) {
            throw new TrainingException('La práctica ya está firmada.');
        }

        if ($inscripcion->practicaAgendada()) {
            throw new TrainingException('Ya hay una práctica agendada. Cancélala antes de pedir otra hora.');
        }
    }

    private function reservar(Enrollment $inscripcion, User $evaluador, CarbonInterface $desde, CarbonInterface $hasta, ?string $nota = null): Reservation
    {
        $curso = $inscripcion->edition->course;

        try {
            return Reservation::create([
                'reservable_type'  => User::class,
                'reservable_id'    => $evaluador->id,
                'user_id'          => $inscripcion->user_id,
                'enrollment_id'    => $inscripcion->id,
                'advisory_area_id' => $curso->area_id,
                'mode'             => 'practica',
                'status'           => 'confirmada',
                'starts_at'        => $desde,
                'ends_at'          => $hasta,
                'purpose'          => trim('Evaluación práctica de ' . $curso->name . ($nota ? '. ' . $nota : '')),
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'reservations_sin_traslape')) {
                throw new TrainingException($evaluador->name . ' ya tiene algo a esa hora. Elige otra.');
            }

            throw $e;
        }
    }

    /** Dos avisos, a dos personas: a quien la presenta y a quien la ve. */
    private function avisar(Reservation $reserva, string $claveEstudiante, ?string $nota = null): void
    {
        $tz = config('fabos.lab.timezone');
        $inscripcion = $reserva->enrollment;
        $curso = $inscripcion?->edition?->course;
        $evaluador = $reserva->reservable;

        $datos = [
            'curso'  => $curso?->name ?? 'el curso',
            'fecha'  => $reserva->starts_at->timezone($tz)->format('d/m/Y'),
            'inicio' => $reserva->starts_at->timezone($tz)->format('H:i'),
            'fin'    => $reserva->ends_at->timezone($tz)->format('H:i'),
            'lugar'  => config('fabos.lab.name'),
            'nota'   => $nota ?: '',
        ];

        if ($reserva->user) {
            $this->avisos->enviar($claveEstudiante, $reserva->user, $datos + [
                'evaluador' => $evaluador?->name ?? 'alguien del equipo',
            ], $reserva);
        }

        if ($evaluador instanceof User) {
            $this->avisos->enviar('practica.asignada', $evaluador, $datos + [
                'estudiante' => $reserva->user?->name ?? 'alguien',
                'nivel'      => $curso?->level ?? '',
            ], $reserva);
        }
    }
}
