<?php

namespace App\Jobs;

use App\Services\HistorialEnVivo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Copia a la base lo que hay en la caché de una sala en vivo. */
class GuardarSesionEnVivo implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $sala) {}

    public function handle(HistorialEnVivo $historial): void
    {
        $historial->guardar($this->sala);
    }
}
