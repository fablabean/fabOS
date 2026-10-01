<?php

namespace App\Services\Booking;

use RuntimeException;

/**
 * No se pudo reservar. Su mensaje está escrito para mostrarse tal cual a la
 * persona, y `faltantes` lleva el camino de formación cuando corresponde (§10).
 */
class BookingException extends RuntimeException
{
    /**
     * @param array<int,string> $faltantes
     * @param array<int,array{cuando:string,que:string,detalle:?string,url:?string}> $conflictos
     *        lo que ocupa a alguien a esa hora, para poder ir a ajustarlo
     */
    public function __construct(string $message, public readonly array $faltantes = [], public readonly array $conflictos = [])
    {
        parent::__construct($message);
    }
}
