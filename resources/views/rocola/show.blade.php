@extends('layouts.app')
@section('title', 'Rocola · ' . config('fabos.lab.name'))

@php
    $moneda = config('fabos.currency.code');
    $unidades = config('fabos.currency.minor_units');
    $alcanza = $saldo >= $precio;
@endphp

@section('content')
    <h1>Rocola del laboratorio</h1>
    <p class="help">
        Pon una canción en los parlantes del laboratorio. Cada una cuesta
        <strong>{{ number_format($precio / $unidades, 0, ',', '.') }} {{ $moneda }}</strong>
        y entra a la cola de lo que está sonando. Tu saldo:
        <strong>{{ number_format($saldo / $unidades, 2, ',', '.') }} {{ $moneda }}</strong>.
    </p>

    @if (! $abierta)
        <div class="panel">
            <p style="margin:0">La rocola está cerrada: el parlante del laboratorio no está tocando Spotify en este momento.</p>
        </div>
    @else
        <div class="panel">
            <form method="GET" action="{{ route('rocola') }}" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
                <div style="flex:1;min-width:14rem">
                    <label for="q">Busca una canción o un artista</label>
                    <input id="q" name="q" type="search" value="{{ $texto }}" maxlength="100" placeholder="Soda Stereo, Café Tacvba…" autofocus>
                </div>
                <button type="submit">Buscar</button>
            </form>
            <p class="help" style="margin:.6rem 0 0">Las canciones con contenido explícito no aparecen: el laboratorio es un espacio de la universidad.</p>
        </div>

        @if ($aviso)
            <div class="msg error">{{ $aviso }}</div>
        @endif

        @if ($texto !== '' && ! $aviso)
            <div class="panel">
                @forelse ($resultados as $c)
                    <div class="rocola-fila">
                        @if ($c['portada'])
                            <img src="{{ $c['portada'] }}" alt="" width="56" height="56" loading="lazy">
                        @else
                            <span class="rocola-sin-portada" aria-hidden="true">♪</span>
                        @endif
                        <div class="rocola-datos">
                            <strong>{{ $c['titulo'] }}</strong>
                            <span class="help">{{ $c['artista'] }} · {{ intdiv(intdiv($c['duracion_ms'], 1000), 60) }}:{{ str_pad((string) (intdiv($c['duracion_ms'], 1000) % 60), 2, '0', STR_PAD_LEFT) }}</span>
                        </div>
                        <form method="POST" action="{{ route('rocola.pedir') }}">
                            @csrf
                            <input type="hidden" name="cancion" value="{{ $c['id'] }}">
                            <button type="submit" @disabled(! $alcanza) title="{{ $alcanza ? '' : 'No te alcanzan los FabCoins' }}">
                                Ponerla · {{ number_format($precio / $unidades, 0, ',', '.') }} {{ $moneda }}
                            </button>
                        </form>
                    </div>
                @empty
                    <p style="margin:0">No encontramos canciones con «{{ $texto }}».</p>
                @endforelse

                @unless ($alcanza)
                    <p class="help" style="margin:.8rem 0 0">No te alcanzan los FabCoins para poner una canción. Se recargan en el laboratorio.</p>
                @endunless
            </div>
        @endif
    @endif

    {{-- Lo que suena y lo que viene, con su hora. Se refresca solo cada
         20 segundos sin recargar la página (y sin borrar lo que se busca). --}}
    @if ($abierta)
        <div class="panel" id="rocola-estado" data-url="{{ route('rocola.estado') }}">
            @include('rocola._estado', ['estado' => $estado])
        </div>
        <script>
            (function () {
                var caja = document.getElementById('rocola-estado');
                setInterval(function () {
                    if (document.hidden) { return; }
                    fetch(caja.dataset.url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
                        .then(function (r) { return r.ok ? r.text() : null; })
                        .then(function (html) { if (html) { caja.innerHTML = html; } })
                        .catch(function () {});
                }, 20000);
            })();
        </script>
    @endif

    <style>
        .rocola-fila{display:flex;align-items:center;gap:.8rem;padding:.55rem 0;border-top:1px solid var(--rule)}
        .rocola-fila:first-of-type{border-top:0}
        .rocola-fila img{border-radius:4px;flex:none;object-fit:cover}
        .rocola-sin-portada{width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;background:var(--rule);border-radius:4px;flex:none}
        .rocola-datos{flex:1;min-width:0;display:flex;flex-direction:column}
        .rocola-datos strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .rocola-fila form{margin:0;flex:none}
        .rocola-fila button{white-space:nowrap}
    </style>
@endsection
