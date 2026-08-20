<?php

namespace App\Services\Booking;

use RuntimeException;

/**
 * No se pudo reservar. Su mensaje está escrito para mostrarse tal cual a la
 * persona, y `faltantes` lleva el camino de formación cuando corresponde (§10).
 */
class BookingException extends RuntimeException
{
    /** @param array<int,string> $faltantes */
    public function __construct(string $message, public readonly array $faltantes = [])
    {
        parent::__construct($message);
    }
}
