<?php

namespace App\Livewire\Juego;

use App\Models\Recorrido\Equipo;
use App\Services\Recorrido\Juego;
use App\Services\Recorrido\JuegoException;
use Livewire\Component;

/**
 * Las gafas, en el navegador: la pista y el tablero de cuatro botones.
 *
 * Hace exactamente lo que hace la app del Quest, por el mismo camino (el
 * servicio del juego), así que sirve para probar un circuito de punta a punta
 * sin el visor, y de respaldo si uno se descarga en plena partida.
 */
class Visor extends Component
{
    public int $equipoId;

    public ?string $aviso = null;

    public bool $avisoBueno = true;

    public function marcar(array $botones, Juego $juego): void
    {
        $equipo = Equipo::with('partida')->findOrFail($this->equipoId);

        try {
            $bien = $juego->marcarSecuencia($equipo, $botones);
        } catch (JuegoException $e) {
            [$this->aviso, $this->avisoBueno] = [$e->getMessage(), false];

            return;
        }

        [$this->aviso, $this->avisoBueno] = $bien
            ? [$equipo->terminado() ? '¡Terminaron el recorrido!' : '¡Correcto! Nueva pista.', true]
            : ['Esa no es la secuencia. Suma penalización.', false];
    }

    public function render(Juego $juego)
    {
        return view('livewire.juego.visor', [
            'estado' => $juego->estado(Equipo::findOrFail($this->equipoId)),
        ]);
    }
}
