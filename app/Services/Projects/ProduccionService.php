<?php

namespace App\Services\Projects;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\ReservationSupply;
use App\Models\Supply;
use App\Models\User;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockService;
use App\Services\Money\PricingService;
use App\Services\Money\QuoteService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Programar producción: alguien del laboratorio operando una máquina.
 *
 * Es una reserva, con otro sentido pero el mismo efecto físico. Una pieza de
 * seis horas ocupa la impresora seis horas, la esté fabricando el laboratorio
 * para un encargo o sea la pieza de un estudiante que pasó por asesoría, así
 * que va a la misma tabla y la misma restricción de PostgreSQL impide que dos
 * cosas coincidan. Un calendario aparte sería un calendario que miente.
 *
 * **El proyecto es opcional.** El caso más común no lo tiene: un estudiante
 * llega con un archivo, el asesor mira que se puede imprimir y programa las
 * seis horas. Esa pieza es suya —la reserva queda a su nombre, y le aparece en
 * su cuenta— aunque quien opere la máquina sea el asesor. Exigir un proyecto
 * habría obligado a inventar uno por cada pieza, y los proyectos inventados
 * ensucian el único sitio donde se mira si el laboratorio entrega.
 *
 * Lo que **no** hereda de una reserva normal, y a propósito:
 *
 *  · **No pide certifab.** No lo opera quien aprende: lo opera el laboratorio.
 *    Es justo lo que resuelve el caso del estudiante sin certificación.
 *  · **No exige jornada atendida.** Una impresión de seis horas empieza a las
 *    seis de la tarde y termina de madrugada; obligarla a caber en el horario
 *    de atención sería obligar al laboratorio a producir peor.
 *  · **No cobra al reservar.** Se cotiza y se liquida al cerrar, con lo que
 *    realmente duró y el material que realmente se gastó.
 *
 * Sí se cotiza siempre, con la misma tarifa de cualquier reserva, porque el
 * tiempo de máquina es capacidad que el laboratorio dejó de tener disponible
 * para otros. Ignorarla haría parecer gratis lo que ocupó la impresora tres
 * días.
 */
class ProduccionService
{
    public function __construct(
        private QuoteService $cotizador,
        private StockService $existencias,
        private PricingService $precios,
    ) {}

    /**
     * Reserva la máquina para producir.
     *
     * @param  User  $paraQuien  de quién es la pieza: la reserva queda a su
     *                           nombre y le aparece en su cuenta
     * @param  ?User $operador   quién del laboratorio la opera, si no es quien
     *                           la pidió —el asesor, casi siempre—
     *
     * @throws ProjectException si el rango no vale o la máquina ya está ocupada
     */
    public function programar(
        Asset $equipo,
        User $paraQuien,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?Project $proyecto = null,
        ?string $que = null,
        ?User $operador = null,
    ): Reservation {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new ProjectException('La hora de fin debe ser posterior a la de inicio.');
        }

        $minutos = (int) $desde->diffInMinutes($hasta);

        // Con la tarifa de quien recibe la pieza: un estudiante no paga lo que
        // paga una empresa, y eso vale igual aunque opere el asesor.
        $cotizacion = $this->cotizador->cotizar($paraQuien, $equipo, $minutos);

        try {
            return DB::transaction(function () use ($proyecto, $equipo, $paraQuien, $operador, $desde, $hasta, $que, $cotizacion) {
                $reserva = Reservation::create([
                    'reservable_type' => Asset::class,
                    'reservable_id'   => $equipo->id,
                    'user_id'         => $paraQuien->id,
                    'supervisor_id'   => $operador?->id,
                    'project_id'      => $proyecto?->id,
                    'is_production'   => true,
                    // Confirmada desde el principio: si no bloqueara el equipo,
                    // no serviria para nada.
                    'status'          => 'confirmada',
                    'mode'            => 'directa',
                    'starts_at'       => $desde,
                    'ends_at'         => $hasta,
                    'purpose'         => $que ?: ($proyecto
                        ? 'Producción de ' . $proyecto->code
                        : 'Producción para ' . $paraQuien->name),
                    'estimated_cost_minor' => $cotizacion->totalMenor,
                ]);

                // Programar producción con un equipo es la prueba más clara de
                // que el proyecto lo usa.
                $proyecto?->assets()->syncWithoutDetaching([$equipo->id]);

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
     * Cierra la producción y liquida lo que de verdad costó.
     *
     * Una pieza que salió en la mitad de tiempo costó la mitad: se cobra lo que
     * ocupó, no lo que se pidió. Al tiempo se le suma el material realmente
     * gastado, que es el otro número que nadie sabe hasta que la máquina para.
     *
     * @param  array<int,float>  $materiales  [id del insumo => cantidad]
     */
    public function terminar(
        Reservation $produccion,
        ?CarbonInterface $cuando = null,
        array $materiales = [],
    ): Reservation {
        $fin = $cuando ?? now();

        $minutos = (int) $produccion->starts_at->diffInMinutes($fin);
        $planeados = (int) $produccion->starts_at->diffInMinutes($produccion->ends_at);

        return DB::transaction(function () use ($produccion, $fin, $minutos, $planeados, $materiales) {
            $material = $this->registrarMaterial($produccion, $materiales);

            $tiempo = $planeados > 0
                ? (int) round($produccion->estimated_cost_minor * min(1, max(0, $minutos / $planeados)))
                : 0;

            $produccion->update([
                'status'            => 'completada',
                'checked_out_at'    => $fin,
                'actual_cost_minor' => $tiempo + $material,
            ]);

            return $produccion->refresh();
        });
    }

    /**
     * Descuenta del inventario lo que se gastó y congela su precio en la línea.
     *
     * Se hace al cerrar y no al programar: el material se consume cuando la
     * máquina corre. Descontarlo por adelantado dejaría el inventario mintiendo
     * durante las seis horas que dura la impresión, y peor aún si se cancela.
     *
     * @param  array<int,float>  $materiales
     *
     * @return int lo que costó, en unidades menores
     */
    private function registrarMaterial(Reservation $produccion, array $materiales): int
    {
        $total = 0;

        foreach ($materiales as $insumoId => $cantidad) {
            $cantidad = (float) $cantidad;

            if ($cantidad <= 0) {
                continue;
            }

            $insumo = Supply::find($insumoId);

            if (! $insumo) {
                continue;
            }

            try {
                $this->existencias->salida(
                    $insumo,
                    $cantidad,
                    'Producción #' . $produccion->id,
                    $produccion,
                    $produccion->user,
                );
            } catch (StockException $e) {
                throw new ProjectException($e->getMessage());
            }

            $precio = $this->precios->precioDe($insumo);

            ReservationSupply::create([
                'reservation_id'   => $produccion->id,
                'supply_id'        => $insumo->id,
                'quantity'         => $cantidad,
                'unit_price_minor' => $precio,
            ]);

            $total += (int) round($cantidad * $precio);
        }

        return $total;
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
