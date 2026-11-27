{{-- La situación del procedimiento, contada por el equipo, con la plataforma de antes y la de ahora. --}}
@if (! empty($p['situacion']))
    <div class="situacion">
        <h4>Situación actual</h4>
        @foreach ($p['situacion'] as $linea)
            <p>{{ $linea }}</p>
        @endforeach
        @if (! empty($p['plataformas']) || ! empty($p['casos']))
            <div class="plat">
                @if (! empty($p['plataformas']['antes']))
                    <span><i>Antes</i><a href="{{ $p['plataformas']['antes'] }}" target="_blank" rel="noopener">Agendamiento por QR (Microsoft Bookings)</a></span>
                @endif
                @if (! empty($p['plataformas']['carpeta']))
                    <span><i>Registros anteriores</i><a href="{{ $p['plataformas']['carpeta'] }}" target="_blank" rel="noopener">Agendamientos laboratorios de innovación</a></span>
                @endif
                @if (! empty($p['plataformas']))
                    <span><i>Ahora</i><a href="{{ $reservar }}" target="_blank">Reserva en FabOS mediante QR</a></span>
                @endif
                @foreach ($p['casos'] ?? [] as [$nombre, $url])
                    <span><i>Caso</i><a href="{{ $url }}" target="_blank">{{ $nombre }}</a></span>
                @endforeach
            </div>
        @endif
    </div>
@endif
