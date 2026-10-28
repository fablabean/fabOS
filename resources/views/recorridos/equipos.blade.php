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
        /* Tarjetas de ancho fijo y centradas: con pocos equipos no se pegan a la izquierda. */
        .rejilla{display:grid;grid-template-columns:repeat(auto-fit,minmax(17rem,19rem));justify-content:center;gap:1rem;padding:1rem}
        .tarjeta{border:2px solid #ccc;border-radius:12px;padding:1rem;text-align:center;break-inside:avoid}
        .tarjeta h2{margin:.2rem 0 .4rem;font-size:1.3rem}
        .codigo{font-size:1.8rem;font-weight:800;letter-spacing:.2em;font-family:ui-monospace,Consolas,monospace;margin:.3rem 0}
        .muted{color:#666;font-size:.85rem}
        .gafas{margin-top:.6rem;padding-top:.6rem;border-top:1px dashed #ccc}
        .cartel{text-align:center;padding:2rem 1rem;border-bottom:1px solid #ddd;break-after:page}
        .cartel h1{font-size:2.4rem;margin:0}
        /* Un solo QR legible a la vez: con varios en pantalla, el lector (o las
           gafas) agarra el que quiere. Los demás quedan borrosos, que es lo que
           de verdad impide leerlos; un QR solo atenuado se sigue leyendo. */
        .qr{position:relative;display:inline-block;border:0;background:none;padding:0;cursor:pointer;line-height:0}
        .qr svg{transition:filter .2s,opacity .2s}
        .qr:not(.activo) svg{filter:blur(9px);opacity:.25}
        .qr span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
                 font:600 .9rem/1.2 system-ui,"Segoe UI",Arial,sans-serif;color:#333;padding:.5rem}
        .qr.activo span{display:none}
        .qr.activo{outline:3px solid #30A46C;outline-offset:6px;border-radius:4px}
        @media print{.barra,.solo-pantalla{display:none}.qr:not(.activo) svg{filter:none;opacity:1}.qr span{display:none}.qr.activo{outline:0}}
    </style>
</head>
<body>
    <div class="barra">
        <strong>{{ $partida->nombre }}</strong>
        <span class="muted">{{ $partida->circuito->nombre }} · entrar a mano: {{ route('juego.unirse') }}</span>
        <button onclick="window.print()">Imprimir</button>
        <span class="muted">Solo se lee el QR activo; toca otro para activarlo, o el activo para apagarlo.</span>
    </div>

    @if ($partida->esPorJornadas())
        {{-- El cartel de la entrada: con este QR se inscribe cada equipo que llega. --}}
        <div class="cartel">
            <h1>{{ $partida->nombre }}</h1>
            <p style="font-size:1.3rem;margin:.2rem 0 1rem">Escanea para inscribir a tu equipo</p>
            <button type="button" class="qr">{!! $qr->svg($partida->urlDeInscripcion(), 320) !!}<span>Toca para activar</span></button>
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
                <button type="button" class="qr">{!! $qr->svg($e->urlDelCelular(), 180) !!}<span>Toca para activar</span></button>
                <div class="muted">Escaneen para abrir el juego en el celular del equipo</div>
                <div class="codigo">{{ $e->codigo }}</div>
                <div class="muted">Código del equipo y de sus gafas</div>
                <div class="gafas">
                    <button type="button" class="qr">{!! $qr->svg($e->qrParaGafas(), 120) !!}<span>Toca para activar</span></button>
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
    <script>
        // Arranca activo el primero: el de inscripción si la partida es por
        // jornadas, si no el del celular del primer equipo.
        (() => {
            const qrs = [...document.querySelectorAll('.qr')];
            qrs[0]?.classList.add('activo');
            qrs.forEach(q => q.addEventListener('click', () => {
                const prender = !q.classList.contains('activo');
                qrs.forEach(o => o.classList.remove('activo'));
                if (prender) q.classList.add('activo');
            }));
        })();
    </script>
</body>
</html>
