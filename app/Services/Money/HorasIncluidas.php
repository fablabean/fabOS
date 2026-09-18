<?php

namespace App\Services\Money;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\RateCard;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Las horas que una tarifa incluye a la semana para quien tiene certifab (§12).
 *
 * Responde una sola pregunta: de los minutos de este trabajo, ¿cuántos salen
 * gratis? Para eso mira tres cosas: que la tarifa incluya horas, que la
 * persona tenga certifab vigente sobre el equipo, y cuánto de esa semana ya
 * gastó en equipos de la misma tarifa.
 *
 * La semana es la del laboratorio —de lunes a domingo en su zona horaria— y
 * la semana de una reserva es la de su inicio: una impresión que arranca el
 * domingo a las diez de la noche cuenta entera contra ese domingo.
 */
class HorasIncluidas
{
    /** Estados en los que una reserva gasta cupo. Una solicitud todavía no. */
    private const GASTAN = ['confirmada', 'en_curso', 'completada'];

    /**
     * Cuántos minutos le quedan a la persona esta semana en esa tarifa.
     *
     * @param  Reservation|null  $excluir  la reserva que se está cotizando, si ya existe:
     *                                     al liquidarla no puede contarse contra sí misma
     */
    public function disponibles(
        User $persona,
        Asset $equipo,
        RateCard $tarifa,
        ?CarbonInterface $cuando = null,
        ?Reservation $excluir = null,
    ): int {
        if ($tarifa->included_weekly_minutes < 1 || ! $this->tieneCertifab($persona, $equipo)) {
            return 0;
        }

        return max(0, $tarifa->included_weekly_minutes - $this->usados($persona, $tarifa, $cuando, $excluir));
    }

    /** Lo ya gastado esa semana en los equipos que cobran con esta tarifa. */
    public function usados(User $persona, RateCard $tarifa, ?CarbonInterface $cuando = null, ?Reservation $excluir = null): int
    {
        [$desde, $hasta] = $this->semanaDe($cuando);

        return (int) Reservation::query()
            ->where('user_id', $persona->id)
            ->where('reservable_type', Asset::class)
            ->whereIn('reservable_id', $this->equiposDe($tarifa))
            ->whereIn('status', self::GASTAN)
            // Producir no es usar la máquina uno mismo: la pieza la opera el
            // asesor y se cobra aparte. No gasta el cupo de nadie.
            ->where('is_production', false)
            ->whereBetween('starts_at', [$desde, $hasta])
            ->when($excluir, fn ($q) => $q->whereKeyNot($excluir->id))
            ->get(['starts_at', 'ends_at', 'checked_in_at', 'checked_out_at'])
            ->sum(fn (Reservation $r) => $r->checked_in_at && $r->checked_out_at
                // Lo cerrado cuenta por el reloj real, igual que se cobró.
                ? $r->checked_in_at->diffInMinutes($r->checked_out_at)
                : $r->starts_at->diffInMinutes($r->ends_at));
    }

    /**
     * Lunes 00:00 y domingo 23:59:59 de esa semana, en hora del laboratorio,
     * devueltos en UTC porque así está guardada la columna: un límite en hora
     * de Bogotá comparado contra UTC corre la semana cinco horas.
     */
    private function semanaDe(?CarbonInterface $cuando): array
    {
        $tz = config('fabos.lab.timezone');
        $momento = Carbon::instance($cuando ?? now())->timezone($tz);

        return [
            $momento->copy()->startOfWeek(CarbonInterface::MONDAY)->utc(),
            $momento->copy()->endOfWeek(CarbonInterface::SUNDAY)->utc(),
        ];
    }

    /** Los ids de los equipos que cobran con esta tarifa, por su alcance. */
    private function equiposDe(RateCard $tarifa)
    {
        return match ($tarifa->rateable_type) {
            Asset::class      => [$tarifa->rateable_id],
            RiskFamily::class => Asset::where('risk_family_id', $tarifa->rateable_id)->pluck('id'),
            Area::class       => Asset::where('area_id', $tarifa->rateable_id)->pluck('id'),
            default           => Asset::pluck('id'),
        };
    }

    /** Certifab vigente sobre el equipo o su familia: el mismo criterio que abre la reserva. */
    private function tieneCertifab(User $persona, Asset $equipo): bool
    {
        return Certifab::query()
            ->vigente()
            ->where('user_id', $persona->id)
            ->where(fn ($q) => $q->where('asset_id', $equipo->id)
                ->when($equipo->risk_family_id, fn ($q) => $q->orWhere('risk_family_id', $equipo->risk_family_id)))
            ->exists();
    }
}
