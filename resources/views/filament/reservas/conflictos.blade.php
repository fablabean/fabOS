{{-- Con qué chocó una reserva: uno por uno, con enlace cuando se ajusta aquí. --}}
<div style="display:flex;flex-direction:column;gap:.6rem">
    @forelse ($conflictos as $c)
        <div style="border:1px solid rgba(128,128,128,.3);border-radius:8px;padding:.65rem .8rem">
            <div style="font-size:.75rem;color:rgb(107 114 128);font-family:ui-monospace,Consolas,monospace">{{ $c['cuando'] }}</div>
            <div style="font-weight:600;margin-top:.15rem">{{ $c['que'] }}</div>
            @if ($c['detalle'])
                <div style="font-size:.85rem;color:rgb(107 114 128)">{{ $c['detalle'] }}</div>
            @endif
            @if ($c['url'])
                <a href="{{ $c['url'] }}" target="_blank" class="text-primary-600 hover:underline" style="font-size:.85rem">Abrir para ajustarlo →</a>
            @endif
        </div>
    @empty
        <p style="font-size:.9rem">Ya no aparece nada que choque: puede que lo hayan movido. Vuelve a intentar.</p>
    @endforelse
</div>
