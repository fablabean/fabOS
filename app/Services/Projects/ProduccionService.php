<?php

namespace App\Services\Projects;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Money\QuoteService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Programar producción: el laboratorio operando su máquina para un encargo.
 *
 * Es una reserva, con otro sentido pero el mismo efecto físico. Una pieza de
 * seis horas ocupa la impresora seis horas, la pida un estudiante o la esté
 * fabricando el propio laboratorio, así que va a la misma tabla y la misma
 * restricción de PostgreSQL impide que dos cosas coincidan. Un calendario
 * aparte sería un calendario que miente.
 *
 * Lo que **no** hereda de una reserva normal, y a propósito:
 *
 *  · **No pide certifab.** No hay nadie aprendiendo: hay un trabajo que sale.
 *  · **No exige jornada atendida.** Una impresión de seis horas empieza a las
 *    seis de la tarde y termina de madrugada; obligarla a caber en el horario
 *    de atención sería obligar al laboratorio a producir peor.
 *  · **No cobra.** No hay a quién: el costo va al costeo del proyecto, que es
 *    donde tiene sentido leerlo.
 *
 * Sí se cotiza, con la misma tarifa interna que cualquier reserva, porque el
 * tiempo de máquina de un proyecto es capacidad que el laboratorio dejó de
 * tener disponible para otros. Ignorarla haría parecer gratis lo que ocupó la
 * impresora tres días.
 */
class ProduccionService
{
    public function __construct(private QuoteService $cotizador) {}

    /**
     * Reserva la máquina para producir, y deja el equipo declarado en el
     * proyecto si todavía no lo estaba: programar producción con algo es la
     * prueba más clara de que el proyecto lo usa.
     *
     * @throws ProjectException si el rango no vale o la máquina ya está ocupada
     */
    public function programar(
        Project $proyecto,
        Asset $equipo,
        User $quien,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?string $que = null,
    ): Reservation {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new ProjectException('La hora de fin debe ser posterior a la de inicio.');
        }

        $minutos = (int) $desde->diffInMinutes($hasta);

        // La tarifa interna, aunque no se le cobre a nadie: es lo que permite
        // decir cuánto costó el proyecto de verdad.
        $cotizacion = $this->cotizador->cotizar($quien, $equipo, $minutos);

        try {
            return DB::transaction(function () use ($proyecto, $equipo, $quien, $desde, $hasta, $que, $cotizacion) {
                $reserva = Reservation::create([
                    'reservable_type' => Asset::class,
                    'reservable_id'   => $equipo->id,
                    'user_id'         => $quien->id,
                    'project_id'      => $proyecto->id,
                    'is_production'   => true,
                    // Confirmada desde el principio: si no bloqueara el equipo,
                    // no serviria para nada.
                    'status'          => 'confirmada',
                    'mode'            => 'directa',
                    'starts_at'       => $desde,
                    'ends_at'         => $hasta,
                    'purpose'         => $que ?: 'Producción de ' . $proyecto->code,
                    'estimated_cost_minor' => $cotizacion->totalMenor,
                ]);

                $proyecto->assets()->syncWithoutDetaching([$equipo->id]);

                return $reserva;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'reservations_sin_traslape')) {
                throw new ProjectException(
                    $equipo->name . ' ya está ocupada en ese rango. Mira el calendario del equipo y elige otro.',
                );
            }

            throw $e;
        }
    }

    /**
     * Cierra la producción. El costo real es el estimado salvo que se diga
     * otra cosa: una pieza que salió en la mitad de tiempo costó la mitad, y
     * el proyecto tiene derecho a que se le cobre lo que ocupó.
     */
    public function terminar(Reservation $produccion, ?CarbonInterface $cuando = null): Reservation
    {
        $fin = $cuando ?? now();

        $minutos = (int) $produccion->starts_at->diffInMinutes($fin);
        $planeados = (int) $produccion->starts_at->diffInMinutes($produccion->ends_at);

        $produccion->update([
            'status'            => 'completada',
            'checked_out_at'    => $fin,
            'actual_cost_minor' => $planeados > 0
                ? (int) round($produccion->estimated_cost_minor * min(1, max(0, $minutos / $planeados)))
                : 0,
        ]);

        return $produccion->refresh();
    }

    /** Se cayó la impresión, se cambió el plan. La máquina se suelta. */
    public function cancelar(Reservation $produccion, string $motivo = 'Cancelada'): Reservation
    {
        $produccion->update([
            'status'        => 'cancelada',
            'status_reason' => $motivo,
        ]);

        return $produccion->refresh();
    }
}
