<?php

namespace App\Services\Money;

use App\Models\Asset;
use App\Models\RateCard;
use App\Models\User;

/**
 * Cuánto cuesta un trabajo (§12).
 *
 * Dos decisiones de fondo:
 *
 *  - **El tiempo de máquina y el material se cobran distinto.** El tiempo lleva
 *    el factor de la categoría — un estudiante no paga lo que paga un externo —
 *    pero el material va a costo para todos: el filamento cuesta lo que cuesta.
 *  - **El reloj se redondea hacia arriba al bloque de facturación.** Cobrar al
 *    minuto exacto invita a discutir por dos minutos; el bloque es explicable.
 */
class QuoteService
{
    /**
     * @param  array<int,array{tarifa:RateCard,cantidad:float,nombre?:string}>  $materiales
     */
    public function cotizar(
        User $usuario,
        Asset $activo,
        int $minutos,
        bool $conAcompanante = false,
        array $materiales = [],
    ): Quote {
        $tarifa = RateCard::para($activo);

        if (! $tarifa) {
            return new Quote([], 0);
        }

        $factor = (float) ($usuario->category?->rate_factor ?? 1);
        $minutosCobrables = $this->redondear($minutos, $tarifa->rounding_minutes);

        $lineas = [];
        $servicio = 0;

        if ($tarifa->price_minor > 0) {
            $importe = $this->aplicar($tarifa->price_minor * $minutosCobrables / 60, $factor);
            $servicio += $importe;
            $lineas[] = [
                'concepto' => 'Tiempo de máquina',
                'detalle'  => $this->enHoras($minutosCobrables) . ' · ' . $activo->name,
                'importe'  => $importe,
            ];
        }

        if ($tarifa->setup_minor > 0) {
            $importe = $this->aplicar($tarifa->setup_minor, $factor);
            $servicio += $importe;
            $lineas[] = ['concepto' => 'Montaje y alistamiento', 'detalle' => null, 'importe' => $importe];
        }

        if ($conAcompanante && $tarifa->supervision_hour_minor > 0) {
            $importe = $this->aplicar($tarifa->supervision_hour_minor * $minutosCobrables / 60, $factor);
            $servicio += $importe;
            $lineas[] = [
                'concepto' => 'Acompañamiento',
                'detalle'  => 'Alguien del equipo reserva ese mismo tiempo',
                'importe'  => $importe,
            ];
        }

        // El mínimo protege trabajos cortos que igual ocupan a alguien montando
        // y desmontando. Se compara contra el servicio, nunca contra el material.
        if ($servicio > 0 && $servicio < $tarifa->minimum_minor) {
            $lineas[] = [
                'concepto' => 'Ajuste al cobro mínimo',
                'detalle'  => 'El trabajo ocupa el equipo aunque dure poco',
                'importe'  => $tarifa->minimum_minor - $servicio,
            ];
            $servicio = $tarifa->minimum_minor;
        }

        $total = $servicio;
        $supuesta = $tarifa->is_assumed;

        foreach ($materiales as $m) {
            $t = $m['tarifa'];
            $importe = (int) ceil($t->price_minor * $m['cantidad']);   // a costo, sin factor
            $total += $importe;
            $supuesta = $supuesta || $t->is_assumed;
            $lineas[] = [
                'concepto' => $m['nombre'] ?? $t->name,
                'detalle'  => rtrim(rtrim(number_format($m['cantidad'], 2, ',', '.'), '0'), ',') . ' ' . $t->unit,
                'importe'  => $importe,
            ];
        }

        return new Quote($lineas, $total, $tarifa->deposit_minor, $supuesta);
    }

    /** Redondea hacia arriba al bloque de facturación. */
    private function redondear(int $minutos, int $bloque): int
    {
        if ($bloque < 1) {
            return $minutos;
        }

        return (int) (ceil($minutos / $bloque) * $bloque);
    }

    private function aplicar(float $importeMenor, float $factor): int
    {
        return (int) round($importeMenor * $factor);
    }

    private function enHoras(int $minutos): string
    {
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return match (true) {
            $h && $m => "{$h} h {$m} min",
            (bool) $h => "{$h} h",
            default  => "{$m} min",
        };
    }
}
