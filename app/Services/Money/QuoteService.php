<?php

namespace App\Services\Money;

use App\Models\Asset;
use App\Models\RateCard;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonInterface;

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
 *  - **Las horas incluidas del certifab se descuentan antes que nada.** Una
 *    tarifa puede incluir horas a la semana para quien está habilitado; esas
 *    salen del reloj antes de redondear, y si cubren el trabajo entero no hay
 *    montaje ni mínimo que cobrar: gratis es gratis.
 */
class QuoteService
{
    public function __construct(private HorasIncluidas $incluidas) {}

    /**
     * @param  array<int,array{tarifa:RateCard,cantidad:float,nombre?:string}>  $materiales
     * @param  Reservation|null  $reserva  la reserva que se recotiza, si ya existe: no se cuenta contra sí misma
     * @param  CarbonInterface|null  $cuando  cuándo ocurre el trabajo, para saber contra qué semana va
     */
    public function cotizar(
        User $usuario,
        Asset $activo,
        int $minutos,
        bool $conAcompanante = false,
        array $materiales = [],
        ?Reservation $reserva = null,
        ?CarbonInterface $cuando = null,
    ): Quote {
        $tarifa = RateCard::para($activo);

        if (! $tarifa) {
            return new Quote([], 0);
        }

        $factor = (float) ($usuario->category?->rate_factor ?? 1);

        // El cupo semanal, primero: sale del reloj antes de redondear, porque
        // ocho horas justas de cupo no deberían dejar un bloque de quince
        // minutos cobrado por un redondeo.
        $disponibles = $this->incluidas->disponibles($usuario, $activo, $tarifa, $cuando ?? $reserva?->starts_at, $reserva);
        $gratis = min($minutos, $disponibles);
        $restantes = $tarifa->included_weekly_minutes > 0 ? $disponibles - $gratis : null;

        $minutosCobrables = $this->redondear($minutos - $gratis, $tarifa->rounding_minutes);

        $lineas = [];
        $servicio = 0;

        if ($gratis > 0) {
            $lineas[] = [
                'concepto' => 'Horas incluidas con tu certifab',
                'detalle'  => $this->enHoras($gratis) . ' de las ' . $this->enHoras($tarifa->included_weekly_minutes)
                    . ' semanales · te quedan ' . $this->enHoras($restantes),
                'importe'  => 0,
            ];
        }

        // Cubierto entero por el cupo: no hay tiempo, montaje ni mínimo que
        // cobrar. El acompañamiento sí, porque es el tiempo de otra persona.
        $cubierto = $minutos > 0 && $minutosCobrables === 0;

        /*
         * Prestar una herramienta no se cobra, si así se decidió (§12).
         *
         * Las tarifas se pensaron para máquinas, y una herramienta heredaba la
         * de su familia de riesgo: un multímetro acababa cobrando la tarifa
         * base del laboratorio, más cara que una impresora. Prestar un
         * destornillador no ocupa una máquina ni gasta nada.
         *
         * Gratis es gratis: ni tiempo, ni montaje, ni mínimo, ni acompañante.
         * El material aparte, que ese sí se consume y no vuelve.
         */
        $prestamoSinCosto = $this->esPrestamoSinCosto($activo);

        if ($prestamoSinCosto) {
            $lineas[] = [
                'concepto' => 'Préstamo de herramienta',
                'detalle'  => $activo->name . ' · el laboratorio no cobra por prestarla',
                'importe'  => 0,
            ];
        }

        if ($tarifa->price_minor > 0 && ! $cubierto && ! $prestamoSinCosto) {
            $importe = $this->aplicar($tarifa->price_minor * $minutosCobrables / 60, $factor);
            $servicio += $importe;
            $lineas[] = [
                'concepto' => 'Tiempo de máquina',
                'detalle'  => $this->enHoras($minutosCobrables) . ' · ' . $activo->name,
                'importe'  => $importe,
            ];
        }

        if ($tarifa->setup_minor > 0 && ! $cubierto && ! $prestamoSinCosto) {
            $importe = $this->aplicar($tarifa->setup_minor, $factor);
            $servicio += $importe;
            $lineas[] = ['concepto' => 'Montaje y alistamiento', 'detalle' => null, 'importe' => $importe];
        }

        if ($conAcompanante && $tarifa->supervision_hour_minor > 0 && ! $prestamoSinCosto) {
            // Sobre el tiempo entero, no sobre el que queda tras el cupo: el
            // acompañante está ahí todo el rato, lo tenga incluido o no.
            $acompanados = $this->redondear($minutos, $tarifa->rounding_minutes);
            $importe = $this->aplicar($tarifa->supervision_hour_minor * $acompanados / 60, $factor);
            $servicio += $importe;
            $lineas[] = [
                'concepto' => 'Acompañamiento',
                'detalle'  => 'Alguien del equipo reserva ese mismo tiempo',
                'importe'  => $importe,
            ];
        }

        // El mínimo protege trabajos cortos que igual ocupan a alguien montando
        // y desmontando. Se compara contra el servicio, nunca contra el material.
        //
        // La tarifa lleva decimales —un cm² vale menos que la unidad menor—,
        // pero lo que se cobra es un entero: el piso se redondea aquí, una vez.
        $minimo = $prestamoSinCosto ? 0 : (int) round($tarifa->minimum_minor);

        if ($servicio > 0 && $servicio < $minimo) {
            $lineas[] = [
                'concepto' => 'Ajuste al cobro mínimo',
                'detalle'  => 'El trabajo ocupa el equipo aunque dure poco',
                'importe'  => $minimo - $servicio,
            ];
            $servicio = $minimo;
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

        return new Quote(
            $lineas,
            (int) $total,
            $prestamoSinCosto ? 0 : (int) round($tarifa->deposit_minor),
            $supuesta,
            $gratis,
            $restantes,
        );
    }

    /**
     * Si esto es un préstamo de herramienta que no se cobra.
     *
     * Con la excepción por tarifa **propia**: ponérsela a un equipo es lo que
     * significa «este sí cuesta» —las gafas de realidad virtual, el robot—.
     * Lo heredado no cuenta, o no habría forma de distinguir lo que alguien
     * decidió de lo que le cayó por su familia de riesgo, que es justo el
     * problema que esto viene a resolver.
     */
    private function esPrestamoSinCosto(Asset $activo): bool
    {
        return $activo->esHerramienta()
            && Settings::prestamoDeHerramientasGratis()
            && RateCard::propiaDe($activo) === null;
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
