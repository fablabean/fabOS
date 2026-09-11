<?php

namespace App\Filament\Resources\Reservations\Widgets;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Quién del equipo tiene qué encima, sobre el listado de reservas (§10).
 *
 * La tabla dice qué reservas hay; no dice cómo está repartido el trabajo.
 * Saber que una persona lleva once acompañamientos y otra ninguno obligaba a
 * filtrar de a uno, y por eso no se miraba nunca.
 *
 * **Una persona se cuenta por tres vías distintas, y ninguna sobra.** Su
 * tiempo puede estar reservado como asesoría —ella es el recurso—, puede
 * figurar como quien acompaña o recibe (`supervisor_id`), o puede estar en la
 * lista de acompañantes de un espacio. En el laboratorio real se reparten así:
 * hay quien casi siempre asesora y quien casi siempre supervisa. Contar una
 * sola vía dibujaba a media plantilla sin trabajo.
 *
 * Solo administradores y superadmins: son quienes atienden. Practicantes y
 * consultores no aparecen porque no tienen nada a su cargo, y una fila de
 * ceros no informa, estorba.
 *
 * Y fuera las cuentas de sistema. Un superadmin sin jornada y sin nada a su
 * cargo no es alguien que trabaje en el laboratorio: es la cuenta con la que
 * se instalo. Se reconoce por eso mismo -ni turno ni trabajo- y no por su
 * nombre, que cambia. Quien tenga jornada sale aunque este en ceros: saber
 * quien esta libre tambien es dato.
 */
class CargaDelEquipo extends Widget
{
    protected string $view = 'filament.reservas.carga-del-equipo';

    /*
     * Sin pereza: es lo primero que se mira al abrir la pantalla, y un hueco
     * que se rellena después hace leer la cifra dos veces para creérsela.
     */
    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    /**
     * @return list<array{persona:User,nombre:string,apellidos:?string,activas:int,futuras:int,cerradas:int}>
     */
    public function getTarjetas(): array
    {
        $equipo = User::role([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN])
            ->where('status', 'activo')
            ->withCount('workSchedules')
            ->orderBy('name')
            ->get();

        if ($equipo->isEmpty()) {
            return [];
        }

        $ids = $equipo->pluck('id')->all();

        /*
         * Todo de una consulta y se reparte en memoria: una por persona y por
         * casilla serían dieciocho para pintar seis tarjetas.
         *
         * Y solo las reservas MADRE. El bloque de tiempo del acompañante
         * cuelga de la reserva que acompaña, así que contarlo también sería
         * contar dos veces la misma tarde.
         */
        $reservas = Reservation::query()
            ->atendidaPor($ids)
            ->with('companions:id')
            ->get();

        $ahora = Carbon::now();

        return $equipo->map(function (User $persona) use ($reservas, $ahora) {
            $suyas = $reservas->filter(fn (Reservation $r) => $this->esDe($r, $persona));
            $partido = $persona->nombreYApellidos();

            return [
                'persona'   => $persona,
                'nombre'    => $partido[0] ?? $persona->name,
                'apellidos' => $partido[1] ?? null,
                // Corriendo ahora mismo: alguien está en el laboratorio.
                'activas'   => $suyas->filter(fn (Reservation $r) => $r->status === 'en_curso'
                    || ($r->status === 'confirmada'
                        && $r->starts_at->lessThanOrEqualTo($ahora)
                        && $r->ends_at->greaterThanOrEqualTo($ahora)))->count(),
                // Por venir, decidido o esperando decisión: es lo que tiene por delante.
                'futuras'   => $suyas->filter(fn (Reservation $r) => in_array($r->status, ['solicitada', 'confirmada'], true)
                    && $r->starts_at->greaterThan($ahora))->count(),
                // Terminadas. Lo cancelado y lo rechazado no entra: no ocurrió,
                // y sumarlo al trabajo de alguien sería contarle lo que no hizo.
                'cerradas'  => $suyas->filter(fn (Reservation $r) => in_array($r->status, ['completada', 'no_show'], true))->count(),
                // A la lista, ya filtrada por esta persona: leer «once» y
                // tener que rehacer a mano el filtro que uno acaba de leer es
                // lo que hace que nadie vuelva a mirar la tarjeta.
                'enlace'    => ReservationResource::getUrl('index', [
                    'tableFilters' => ['atiende' => ['value' => $persona->id]],
                ]),
            ];
        })
            // Fuera la cuenta con la que se instalo: sin jornada y sin nada a
            // su cargo. Quien tiene jornada se queda aunque este en ceros.
            ->reject(fn (array $t) => $t['persona']->work_schedules_count === 0
                && $t['activas'] === 0 && $t['futuras'] === 0 && $t['cerradas'] === 0)
            ->values()->all();
    }

    /** Las tres maneras de tener una reserva a cargo. */
    private function esDe(Reservation $reserva, User $persona): bool
    {
        return (int) $reserva->supervisor_id === $persona->id
            || ($reserva->reservable_type === User::class && (int) $reserva->reservable_id === $persona->id)
            || $reserva->companions->contains('id', $persona->id);
    }
}
