<?php

namespace App\Livewire\Juego;

use App\Models\Recorrido\Equipo;
use App\Services\Recorrido\Juego;
use App\Services\Recorrido\JuegoException;
use Livewire\Component;

/**
 * El celular del equipo: dice qué hacer en cada paso, muestra la prueba al
 * escanear el lugar correcto y, resuelta, la secuencia que hay que llevarle
 * al líder. Se refresca solo mientras espera algo que pasa en otra parte —el
 * inicio, las gafas—.
 */
class Celular extends Component
{
    public int $equipoId;

    public string $texto = '';

    public ?int $opcion = null;

    /** @var array{x:float,y:float}|null */
    public ?array $punto = null;

    /** @var array<int,int|string> izquierda => derecha elegida */
    public array $enlaces = [];

    public ?int $lider = null;

    public ?string $aviso = null;

    public bool $avisoBueno = true;

    public function mount(int $equipoId, ?string $aviso = null): void
    {
        $this->equipoId = $equipoId;
        $this->aviso = $aviso;
        $this->avisoBueno = $aviso === null || str_starts_with($aviso, '¡');
        $this->lider = $this->equipo()->avanceActual()?->lider_id;
    }

    public function equipo(): Equipo
    {
        return Equipo::with(['partida', 'integrantes'])->findOrFail($this->equipoId);
    }

    public function updatedLider(Juego $juego): void
    {
        try {
            $juego->elegirLider($this->equipo(), $this->lider ?: null);
        } catch (JuegoException $e) {
            $this->avisar($e->getMessage(), false);
        }
    }

    public function responder(Juego $juego): void
    {
        $equipo = $this->equipo();
        $estacion = $equipo->estacionActual();

        $respuesta = match ($estacion?->tipo_respuesta) {
            'texto'   => $this->texto,
            'opcion'  => $this->opcion,
            'ubicar'  => $this->punto,
            'enlazar' => $this->enlaces,
            default   => null,
        };

        try {
            $bien = $juego->responder($equipo, $respuesta);
        } catch (JuegoException $e) {
            $this->avisar($e->getMessage(), false);

            return;
        }

        if ($bien) {
            $this->reset('texto', 'opcion', 'punto', 'enlaces');
            $this->avisar('¡Correcto! Lleven esta secuencia a su líder.', true);
        } else {
            $this->avisar('No es correcta. Suma penalización: piénsenlo y vuelvan a intentar.', false);
        }
    }

    private function avisar(string $texto, bool $bueno): void
    {
        $this->aviso = $texto;
        $this->avisoBueno = $bueno;
    }

    public function render()
    {
        $equipo = $this->equipo();
        $estacion = $equipo->estado === 'resolviendo' ? $equipo->estacionActual() : null;

        // La columna derecha de «enlazar», mezclada siempre igual para este
        // equipo: si cambiara en cada refresco, nadie podría responder.
        $derecha = [];

        if ($estacion?->tipo_respuesta === 'enlazar') {
            $derecha = collect($estacion->datos_respuesta['pares'] ?? [])->values()
                ->map(fn ($p, $i) => ['i' => $i, 'texto' => $p['derecha'] ?? ''])
                ->sortBy(fn ($p) => crc32($equipo->id . '-' . $estacion->id . '-' . $p['i']))
                ->values()->all();
        }

        return view('livewire.juego.celular', [
            'equipo' => $equipo,
            'estacion' => $estacion,
            'derecha' => $derecha,
        ]);
    }
}
