<?php

namespace App\Services\Money;

/**
 * Una cotización: el desglose de lo que costaría un trabajo.
 *
 * Se guarda por líneas y no como un total, porque quien reserva tiene derecho a
 * saber por qué paga lo que paga: tanto de máquina, tanto de montaje, tanto de
 * material. Un número solo genera desconfianza y preguntas al mostrador.
 */
class Quote
{
    /** @param array<int,array{concepto:string,detalle:?string,importe:int}> $lineas */
    public function __construct(
        public readonly array $lineas,
        public readonly int $totalMenor,
        public readonly int $depositoMenor = 0,
        public readonly bool $esSupuesta = false,
        /** Minutos de este trabajo que salieron gratis por el cupo semanal del certifab. */
        public readonly int $minutosIncluidos = 0,
        /** Lo que le queda del cupo esta semana, ya descontado este trabajo. Nulo si no hay cupo. */
        public readonly ?int $minutosIncluidosRestantes = null,
    ) {}

    /**
     * Si hay algo que explicar aunque el total sea cero.
     *
     * Las horas incluidas del certifab, o un préstamo de herramienta que no se
     * cobra: en los dos casos el total es cero y hay algo que decir. Esconder
     * el desglose ahí dejaba la pantalla sin explicar **por qué** no cuesta,
     * que es justo lo que da confianza.
     */
    public function tieneDesglose(): bool
    {
        return $this->totalMenor > 0 || $this->minutosIncluidos > 0 || $this->lineas !== [];
    }

    public function total(): float
    {
        return $this->totalMenor / config('fabos.currency.minor_units');
    }

    public function deposito(): float
    {
        return $this->depositoMenor / config('fabos.currency.minor_units');
    }

    /** Lo que se compromete al reservar: el depósito si lo hay, si no el total. */
    public function comprometidoMenor(): int
    {
        return $this->depositoMenor > 0 ? $this->depositoMenor : $this->totalMenor;
    }
}
