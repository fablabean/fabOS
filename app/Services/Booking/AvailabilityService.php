<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Services\Staffing\CoverageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Estado de los equipos en este momento, para el catálogo público (§10).
 *
 * Se resuelve en lote y no equipo por equipo: el catálogo tiene 82 activos y
 * preguntar uno a uno serían cientos de consultas por visita.
 */
class AvailabilityService
{
    public const LIBRE      = 'libre';
    public const OCUPADO    = 'ocupado';
    public const CERRADO    = 'cerrado';       // fuera de la franja de atención
    public const NO_OPERATIVO = 'no_operativo';
    public const ACCESORIO  = 'accesorio';   // se inventaría, no se agenda

    public function __construct(private CoverageService $coverage) {}

    /**
     * Estado de una colección de equipos ahora mismo.
     *
     * @param  Collection<int,Asset>  $equipos
     * @return array<int,array{estado:string,hasta:?Carbon,etiqueta:string}>
     */
    public function estadoAhora(Collection $equipos): array
    {
        $ahora = now();
        $tz    = config('fabos.lab.timezone');
        $abierto = $this->coverage->franjaAtendida($ahora->copy()->setTimezone($tz)) !== null;

        // Una sola consulta para todas las reservas que ocupan algo ahora.
        $ocupadas = Reservation::query()
            ->where('reservable_type', Asset::class)
            ->whereIn('reservable_id', $equipos->pluck('id'))
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('starts_at', '<=', $ahora)
            ->where('ends_at', '>', $ahora)
            ->get()
            ->keyBy('reservable_id');

        return $equipos->mapWithKeys(function (Asset $a) use ($ocupadas, $abierto, $tz) {
            return [$a->id => $this->resolver($a, $ocupadas->get($a->id), $abierto, $tz)];
        })->all();
    }

    /** @return array{estado:string,hasta:?Carbon,etiqueta:string} */
    private function resolver(Asset $a, ?Reservation $ocupacion, bool $abierto, string $tz): array
    {
        if (! $a->is_reservable) {
            // Un punto verde junto a «no se reserva» se contradice: los
            // accesorios llevan estado propio, neutro.
            return ['estado' => self::ACCESORIO, 'hasta' => null, 'etiqueta' => 'No se reserva'];
        }

        if ($a->status !== 'operativo') {
            return [
                'estado'   => self::NO_OPERATIVO,
                'hasta'    => null,
                'etiqueta' => Asset::ESTADOS[$a->status] ?? $a->status,
            ];
        }

        if ($ocupacion) {
            return [
                'estado'   => self::OCUPADO,
                'hasta'    => $ocupacion->ends_at,
                'etiqueta' => 'Ocupado hasta las ' . $ocupacion->ends_at->timezone($tz)->format('H:i'),
            ];
        }

        // Un equipo desatendido puede estar corriendo un trabajo aunque el
        // laboratorio ya haya cerrado, así que «cerrado» solo aplica a lo demás.
        if (! $abierto && ! $a->unattended_use) {
            return ['estado' => self::CERRADO, 'hasta' => null, 'etiqueta' => 'Fuera de horario'];
        }

        return ['estado' => self::LIBRE, 'hasta' => null, 'etiqueta' => 'Libre ahora'];
    }

    /**
     * Cuántos equipos de la lista están libres. Sirve de resumen en la portada:
     * es el dato que le interesa a quien pasa por el sitio.
     */
    public function contarLibres(Collection $equipos): int
    {
        return collect($this->estadoAhora($equipos))
            ->where('estado', self::LIBRE)
            ->count();
    }
}
