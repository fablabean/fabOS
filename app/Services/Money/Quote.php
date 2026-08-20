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
    ) {}

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
