@php
    // Las coordenadas viven junto a este campo, en datos_respuesta.
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.marcador');
    $registro = $getRecord();
    $imagen = $registro instanceof \App\Models\Recorrido\Estacion
        ? \App\Models\Recorrido\Estacion::urlDeImagen($registro->datos_respuesta['imagen'] ?? null)
        : null;
@endphp

<div>
    @if ($imagen)
        <p class="text-sm text-gray-500" style="margin-bottom:.4rem">Toca la imagen donde está la respuesta: llena horizontal y vertical sola.</p>
        <div x-data="{
                x: $wire.entangle('{{ $base }}.datos_respuesta.x'),
                y: $wire.entangle('{{ $base }}.datos_respuesta.y'),
                r: $wire.entangle('{{ $base }}.datos_respuesta.radio'),
             }"
             style="position:relative;max-width:28rem">
            <img src="{{ $imagen }}" alt="" style="width:100%;display:block;border-radius:8px;cursor:crosshair"
                 @click="const b = $el.getBoundingClientRect(); x = +((($event.clientX - b.left) / b.width) * 100).toFixed(1); y = +((($event.clientY - b.top) / b.height) * 100).toFixed(1)">
            <template x-if="x !== null && x !== '' && y !== null && y !== ''">
                <span :style="`position:absolute;left:${x}%;top:${y}%;width:${(r || 8) * 2}%;aspect-ratio:1;transform:translate(-50%,-50%);border-radius:50%;border:2px solid #E5484D;background:rgb(229 72 77 / .2);pointer-events:none`"></span>
            </template>
        </div>
    @else
        <p class="text-sm text-gray-500">Sube la imagen y guarda: después puedes tocar sobre ella para marcar dónde está la respuesta.</p>
    @endif
</div>
