<?php

namespace App\Services\Training;

use App\Models\CourseEdition;
use App\Models\CourseSession;
use App\Models\Enrollment;
use App\Models\SessionAttendance;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Quién vino a cada sesión (§9).
 *
 * El QR lo escanea cada persona con su teléfono y se identifica con el correo
 * con que se inscribió. Escanear dos veces no cuenta dos veces. Lo que el QR
 * no ve —alguien sin teléfono, un QR que no carga— lo anota el equipo a mano,
 * y queda dicho que fue a mano y por quién.
 */
class AsistenciaDeActividad
{
    /**
     * Registra la asistencia de quien escaneó.
     *
     * @return array{inscripcion: Enrollment, nueva: bool}
     *
     * @throws TrainingException
     */
    public function registrarPorQr(CourseSession $sesion, string $correo, ?Carbon $ahora = null): array
    {
        if (! $sesion->qrAbierto($ahora)) {
            $tz = config('fabos.lab.timezone');

            throw new TrainingException(
                'Este QR registra asistencia desde una hora antes de la sesión hasta una hora después de que termina ('
                . $sesion->starts_at->copy()->timezone($tz)->format('d/m H:i') . '). Si llegaste, pídele al equipo que te anote.'
            );
        }

        $inscripcion = $this->inscripcionDe($sesion->edition, $correo);

        if (! $inscripcion) {
            throw new TrainingException('No encontramos una inscripción con ese correo en esta actividad. Usa el mismo correo con que te inscribiste.');
        }

        if ($inscripcion->enEspera()) {
            throw new TrainingException('Estás en la lista de espera de esta actividad, sin cupo asignado. Habla con el equipo.');
        }

        $existente = SessionAttendance::where('course_session_id', $sesion->id)
            ->where('enrollment_id', $inscripcion->id)
            ->first();

        // Ya estaba: no se toca. Si el equipo lo había marcado como ausente
        // y aparece escaneando, es que vino.
        if ($existente && $existente->status === 'asistio') {
            return ['inscripcion' => $inscripcion, 'nueva' => false];
        }

        SessionAttendance::updateOrCreate(
            ['course_session_id' => $sesion->id, 'enrollment_id' => $inscripcion->id],
            ['status' => 'asistio', 'method' => 'qr', 'marked_by' => null, 'marked_at' => $ahora ?? now()],
        );

        return ['inscripcion' => $inscripcion, 'nueva' => true];
    }

    /**
     * Anota o corrige a mano: vino, no vino, o se borra la marca.
     *
     * @param  string|null  $estado  asistio · no_asistio · null para quitar la marca
     */
    public function marcar(CourseSession $sesion, Enrollment $inscripcion, ?string $estado, User $porQuien, ?string $nota = null): ?SessionAttendance
    {
        if ($inscripcion->course_edition_id !== $sesion->course_edition_id) {
            throw new TrainingException('Esa inscripción no es de esta actividad.');
        }

        if ($estado === null) {
            SessionAttendance::where('course_session_id', $sesion->id)->where('enrollment_id', $inscripcion->id)->delete();

            return null;
        }

        return SessionAttendance::updateOrCreate(
            ['course_session_id' => $sesion->id, 'enrollment_id' => $inscripcion->id],
            [
                'status'    => $estado,
                'method'    => 'manual',
                'marked_by' => $porQuien->id,
                'marked_at' => now(),
                'note'      => trim((string) $nota) ?: null,
            ],
        );
    }

    /**
     * Pasar lista de una vez: los marcados vinieron, los demás no.
     *
     * Solo toca a quien tiene cupo. A quien ya escaneó el QR no se le cambia
     * a «no asistió» por no estar marcado: la casilla vacía en una lista larga
     * es más a menudo un descuido que una ausencia.
     *
     * @param  list<int>  $presentes  ids de inscripción
     * @return array{asistieron: int, ausentes: int}
     */
    public function pasarLista(CourseSession $sesion, array $presentes, User $porQuien): array
    {
        $presentes = array_map('intval', $presentes);
        $asistieron = 0;
        $ausentes = 0;

        $conCupo = $sesion->edition->enrollments()->whereNotIn('status', Enrollment::SIN_CUPO)->get();

        foreach ($conCupo as $inscripcion) {
            $actual = SessionAttendance::where('course_session_id', $sesion->id)->where('enrollment_id', $inscripcion->id)->first();

            if (in_array($inscripcion->id, $presentes, true)) {
                if ($actual?->status !== 'asistio') {
                    $this->marcar($sesion, $inscripcion, 'asistio', $porQuien);
                }
                $asistieron++;

                continue;
            }

            if ($actual?->status === 'asistio' && $actual->method === 'qr') {
                $asistieron++;

                continue;
            }

            $this->marcar($sesion, $inscripcion, 'no_asistio', $porQuien);
            $ausentes++;
        }

        return ['asistieron' => $asistieron, 'ausentes' => $ausentes];
    }

    /**
     * Cómo le fue a cada persona en la edición: cuántas sesiones vino, y si
     * canceló antes. Es la fila del informe de asistencia.
     *
     * @return array{sesiones: int, asistio: int, no_asistio: int, sin_marcar: int, estado: string}
     */
    public function resumen(Enrollment $inscripcion, ?int $sesiones = null): array
    {
        $sesiones ??= CourseSession::where('course_edition_id', $inscripcion->course_edition_id)->count();

        $marcas = $inscripcion->relationLoaded('attendances')
            ? $inscripcion->attendances
            : $inscripcion->attendances()->get();

        $asistio = $marcas->where('status', 'asistio')->count();
        $noAsistio = $marcas->where('status', 'no_asistio')->count();

        $estado = match (true) {
            $inscripcion->status === 'retirado' => 'Canceló',
            $inscripcion->enEspera()            => 'En lista de espera',
            $sesiones === 0                     => 'Sin sesiones',
            $asistio === $sesiones              => 'Asistió',
            $asistio > 0                        => 'Asistió a ' . $asistio . ' de ' . $sesiones,
            $noAsistio > 0                      => 'No asistió',
            default                             => 'Sin registro',
        };

        return [
            'sesiones'   => $sesiones,
            'asistio'    => $asistio,
            'no_asistio' => $noAsistio,
            'sin_marcar' => max(0, $sesiones - $asistio - $noAsistio),
            'estado'     => $estado,
        ];
    }

    /** La inscripción de ese correo en la edición, si la hay. */
    public function inscripcionDe(CourseEdition $edicion, string $correo): ?Enrollment
    {
        $correo = mb_strtolower(trim($correo));

        return $edicion->enrollments()
            ->whereNot('status', 'retirado')
            ->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [$correo]))
            ->first();
    }
}
