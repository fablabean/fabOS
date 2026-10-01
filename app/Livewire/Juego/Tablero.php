<?php

namespace App\Livewire\Juego;

use App\Models\Recorrido\Partida;
use App\Services\Recorrido\Juego;
use Livewire\Component;

/** Dónde va cada equipo, en vivo: para proyectar durante el recorrido. */
class Tablero extends Component
{
    public int $partidaId;

    public function render(Juego $juego)
    {
        $partida = Partida::with('circuito')->findOrFail($this->partidaId);

        return view('livewire.juego.tablero', [
            'partida' => $partida,
            'equipos' => $juego->clasificacion($partida),
        ]);
    }
}
