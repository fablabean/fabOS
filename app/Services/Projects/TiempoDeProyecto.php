<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Support\Secciones;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Apartar el tiempo de alguien para una tarea de proyecto (§10, §11).
 *
 * Quien lleva un proyecto necesita horas seguidas para hacerlo, y en esas
 * horas no puede estar en una asesoría ni acompañando una sala. El sistema ya
 * sabe decir «está ocupado»: es lo que hace una asesoría, que reserva el
 * tiempo de quien atiende. Un bloque de proyecto es exactamente eso, con un
 * proyecto detrás y una tarea delante.
 *
 * Por eso el bloque es una reserva de la persona, en la misma tabla, y todo
 * lo que hoy pregunta «¿está libre?» —el reparto de asesorías, la búsqueda de
 * acompañante, los acompañantes de una sala— lo respeta sin haberle enseñado
 * nada. Y sale en su cuenta y en su calendario junto a lo demás.
 *
 * Dos reglas que no son de código sino de trato:
 *
 *  · **El bloque no gana a lo que ya había.** Si esa persona ya tiene una
 *    asesoría a esa hora, no se le monta un bloque encima en silencio: se
 *    dice, y alguien decide qué mover.
 *  · **Se aparta el tiempo propio, o el del equipo si se lleva el proyecto.**
 *    Que un miembro cualquiera pueda apartarle la tarde a otro es como se
 *    acaban las agendas que nadie reconoce.
 */
class TiempoDeProyecto
{
    public const MODO = 'proyecto';

    /**
     * @throws BookingException
     */
    public function apartar(
        ProjectTask $tarea,
        User $paraQuien,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        User $porQuien,
    ): Reservation {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new BookingException('La hora de fin debe ser posterior a la de inicio.');
        }

        $proyecto = $tarea->project;

        if (! $proyecto) {
            throw new BookingException('Esa tarea ya no tiene proyecto.');
        }

        if (! $proyecto->estaEnElEquipo($paraQuien) && $tarea->assigned_to !== $paraQuien->id) {
            throw new BookingException($paraQuien->name . ' no está en el equipo de ' . $proyecto->code . '.');
        }

        if (! $this->puedeApartarPara($proyecto, $paraQuien, $porQuien)) {
            throw new BookingException(
                'El tiempo de otra persona lo aparta quien lleva el proyecto. El tuyo lo apartas tú.'
            );
        }

        // Lo que ya tenía, gana. Se dice QUÉ es, para que se pueda mover.
        $choque = $this->loQueTieneAEsaHora($paraQuien, $desde, $hasta)->first();

        if ($choque) {
            throw new BookingException(
                $paraQuien->name . ' ya tiene ' . $this->comoSeLlama($choque) . ' a esa hora ('
                . $choque->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i') . '–'
                . $choque->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i')
                . '). Muévela primero, o elige otra franja.'
            );
        }

        try {
            return Reservation::create([
                'reservable_type' => User::class,
                'reservable_id'   => $paraQuien->id,
                'user_id'         => $paraQuien->id,
                'project_id'      => $proyecto->id,
                'project_task_id' => $tarea->id,
                'mode'            => self::MODO,
                'status'          => 'confirmada',
                'starts_at'       => $desde,
                'ends_at'         => $hasta,
                'purpose'         => $proyecto->code . ' · ' . $tarea->title,
                'status_reason'   => 'Tiempo apartado por ' . $porQuien->name,
            ]);
        } catch (QueryException $e) {
            // La base es la última palabra: entre comprobar y grabar pudo
            // colarse otra reserva de esa persona.
            if (str_contains($e->getMessage(), 'sin_traslape')) {
                throw new BookingException('Alguien acaba de tomar ese tiempo. Prueba otra franja.');
            }

            throw $e;
        }
    }

    /** Suelta un bloque: el tiempo vuelve a estar disponible. */
    public function soltar(Reservation $bloque, User $porQuien): Reservation
    {
        if ($bloque->mode !== self::MODO) {
            throw new BookingException('Eso no es un bloque de proyecto.');
        }

        $bloque->update([
            'status'        => 'cancelada',
            'status_reason' => 'Bloque soltado por ' . $porQuien->name,
        ]);

        return $bloque->refresh();
    }

    /**
     * Los bloques que vienen, de una tarea o de una persona.
     *
     * @return Collection<int,Reservation>
     */
    public function bloquesDe(ProjectTask|User $de): Collection
    {
        $q = Reservation::query()
            ->where('mode', self::MODO)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at');

        return $de instanceof ProjectTask
            ? $q->where('project_task_id', $de->id)->get()
            : $q->where('reservable_type', User::class)->where('reservable_id', $de->id)->with('project')->get();
    }

    /**
     * Lo que esa persona ya tiene a esa hora: como quien atiende (asesorías,
     * acompañamientos, otros bloques) o como quien reservó (una máquina).
     *
     * @return Collection<int,Reservation>
     */
    public function loQueTieneAEsaHora(User $persona, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return Reservation::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('reservable_type', User::class)->where('reservable_id', $persona->id))
                ->orWhere('user_id', $persona->id))
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->orderBy('starts_at')
            ->get();
    }

    private function puedeApartarPara(Project $proyecto, User $paraQuien, User $porQuien): bool
    {
        if ($porQuien->id === $paraQuien->id) {
            return true;
        }

        return $proyecto->loLidera($porQuien)
            || $porQuien->puedeEnLaSeccion('editar', Secciones::claveDe(\App\Filament\Resources\Projects\ProjectResource::class));
    }

    private function comoSeLlama(Reservation $r): string
    {
        return match (true) {
            $r->esAsesoria()          => 'una asesoría',
            $r->mode === self::MODO   => 'un bloque de ' . ($r->project?->code ?? 'otro proyecto'),
            $r->reservable_type === User::class => 'un acompañamiento',
            default                   => 'una reserva de ' . ($r->reservable?->name ?? 'un equipo'),
        };
    }
}
