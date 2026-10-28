<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipos · {{ $partida->nombre }}</title>
    {{-- Una tarjeta por equipo: el QR grande abre su celular; el pequeño lleva
         el código en JSON para que las gafas se emparejen al verlo. --}}
    <style>
        body{font-family:system-ui,"Segoe UI",Arial,sans-serif;margin:0;color:#111;background:#fff}
        .barra{padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;border-bottom:1px solid #ddd}
        .rejilla{display:grid;grid-template-columns:repeat(auto-fill,minmax(17rem,1fr));gap:1rem;padding:1rem}
        .tarjeta{border:2px solid #ccc;border-radius:12px;padding:1rem;text-align:center;break-inside:avoid}
        .tarjeta h2{margin:.2rem 0 .4rem;font-size:1.3rem}
        .codigo{font-size:1.8rem;font-weight:800;letter-spacing:.2em;font-family:ui-monospace,Consolas,monospace;margin:.3rem 0}
        .muted{color:#666;font-size:.85rem}
        .gafas{margin-top:.6rem;padding-top:.6rem;border-top:1px dashed #ccc}
        .cartel{text-align:center;padding:2rem 1rem;border-bottom:1px solid #ddd;break-after:page}
        .cartel h1{font-size:2.4rem;margin:0}
        @media print{.barra,.solo-pantalla{display:none}}
    </style>
</head>
<body>
    <div class="barra">
        <strong>{{ $partida->nombre }}</strong>
        <span class="muted">{{ $partida->circuito->nombre }} · entrar a mano: {{ route('juego.unirse') }}</span>
        <button onclick="window.print()">Imprimir</button>
    </div>

    @if ($partida->esPorJornadas())
        {{-- El cartel de la entrada: con este QR se inscribe cada equipo que llega. --}}
        <div class="cartel">
            <h1>{{ $partida->nombre }}</h1>
            <p style="font-size:1.3rem;margin:.2rem 0 1rem">Escanea para inscribir a tu equipo</p>
            {!! $qr->svg($partida->urlDeInscripcion(), 320) !!}
            <p class="muted">{{ $partida->urlDeInscripcion() }}</p>
            @foreach ($partida->horarios() as $j)
                <p style="margin:.2rem 0">{{ ucfirst(\App\Services\Recorrido\Juego::fecha($j['inicio'])) }} a {{ $j['fin']->copy()->timezone(config('fabos.lab.timezone'))->format('g:i a') }}</p>
            @endforeach
        </div>
    @endif

    <div class="rejilla">
        @forelse ($partida->equipos as $e)
            <div class="tarjeta" style="border-color:{{ $e->color }}">
                <h2>{{ $e->nombre }}</h2>
                {!! $qr->svg($e->urlDelCelular(), 180) !!}
                <div class="muted">Escaneen para abrir el juego en el celular del equipo</div>
                <div class="codigo">{{ $e->codigo }}</div>
                <div class="muted">Código del equipo y de sus gafas</div>
                <div class="gafas">
                    {!! $qr->svg($e->qrParaGafas(), 120) !!}
                    <div class="muted">Para las gafas: míralo con ellas y se emparejan solas</div>
                </div>
                @if ($e->integrantes->isNotEmpty())
                    <p class="muted">{{ $e->integrantes->pluck('nombre')->implode(', ') }}</p>
                @endif
                <p class="solo-pantalla"><a href="{{ route('juego.visor', $e) }}" target="_blank">Abrir visor web</a></p>
            </div>
        @empty
            <p>Esta partida todavía no tiene equipos.</p>
        @endforelse
    </div>
</body>
</html>
