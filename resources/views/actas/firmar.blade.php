@extends('layouts.publico')
@section('title', 'Firmar el acta ' . $acta->code . ' · ' . config('fabos.lab.name'))

@php
    $papel = $acta->esUnaSolaPersona()
        ? 'quien entrega y quien recibe'
        : ($parte === 'entrega' ? 'quien entrega' : 'quien recibe');
    $nombre = $acta->{"{$parte}_nombre"};
    $abierta = $acta->status === 'por_firmar' && ! $yaFirmo;
@endphp

@section('styles')
    .acta-pagina{max-width:56rem;margin:0 auto;padding:1.6rem 1rem 3rem}
    .acta-pagina h1{font-size:1.5rem;margin:0 0 .3rem}
    .acta-hoja{background:#fff;padding:1rem;border:1px solid var(--rule);border-radius:6px;overflow-x:auto;margin:1.2rem 0}
    .acta-hoja .frf{min-width:40rem}
    .acta-aviso{padding:.8rem 1rem;border-radius:6px;margin:1rem 0;font-size:.95rem}
    .acta-aviso.ok{background:color-mix(in srgb,#0D6E63 13%,transparent)}
    .acta-aviso.error{background:color-mix(in srgb,#9B2C2C 13%,transparent)}
    .firmar{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.2rem}
    .firmar h2{margin-top:0}
    .firmar canvas{width:100%;height:auto;aspect-ratio:700/220;background:#fff;border:1px dashed #888;border-radius:6px;
                   touch-action:none;cursor:crosshair;display:block}
    .firmar .fila{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin:.6rem 0 1rem}
    .firmar label.casilla{display:flex;gap:.6rem;align-items:flex-start;font-size:.95rem;margin:0 0 1rem}
    .firmar label.casilla input{margin-top:.25rem;flex:none}
    .firmar button[type=submit]{padding:.8rem 1.4rem;font-size:1rem}
    .firmar .secundario{background:transparent;color:var(--accent);border:1px solid var(--accent);padding:.45rem .9rem;border-radius:4px;cursor:pointer;font:inherit}
@endsection

@section('content')
<main class="acta-pagina">
    <p class="rotulo">Control para el movimiento de activos · FRF-207-f7</p>
    <h1>Acta {{ $acta->code }}</h1>
    <p style="margin:0;color:var(--muted)">
        {{ $nombre }}, firmas como <strong>{{ $papel }}</strong>. Revisa que los activos y tus datos estén bien.
    </p>

    @if (session('firmada'))
        <div class="acta-aviso ok"><strong>Firma recibida.</strong> Gracias: ya puedes cerrar esta página.</div>
    @endif

    @if ($errors->any())
        <div class="acta-aviso error">{{ $errors->first() }}</div>
    @endif

    <div class="acta-hoja">
        @include('actas.formato', ['paraPdf' => false, 'soloCuerpo' => true])
    </div>

    @if ($abierta)
        <form method="POST" action="{{ route('actas.firmar.guardar', $token) }}" class="firmar" id="form-firma">
            @csrf
            <h2>Tu firma</h2>
            <p style="margin:0 0 .6rem;color:var(--muted);font-size:.92rem">
                Dibújala en el recuadro, con el dedo o con el mouse. Es la que sale en el formato.
            </p>

            <canvas id="lienzo-firma" width="700" height="220" aria-label="Recuadro para dibujar la firma"></canvas>

            <div class="fila">
                <button type="button" class="secundario" id="borrar-firma">Borrar y volver a firmar</button>
                <span id="estado-firma" style="color:var(--muted);font-size:.9rem">Todavía no has firmado.</span>
            </div>

            <input type="hidden" name="firma" id="campo-firma">

            <label class="casilla">
                <input type="checkbox" name="acepto" value="1" required>
                <span>
                    Revisé el acta. Confirmo que
                    {{ $acta->esUnaSolaPersona() ? 'entrego y recibo' : ($parte === 'entrega' ? 'entrego' : 'recibo') }}
                    los activos descritos, en el estado indicado, y que mis datos son correctos.
                </span>
            </label>

            <button type="submit" class="btn">Firmar el acta</button>
        </form>

        <script>
            // El mismo lienzo a mano alzada del formulario de proyectos: sin
            // librerías, y solo viaja si de verdad se dibujó algo.
            (function () {
                const lienzo = document.getElementById('lienzo-firma');
                const ctx = lienzo.getContext('2d');
                const campo = document.getElementById('campo-firma');
                const estado = document.getElementById('estado-firma');
                let trazando = false, hayFirma = false;

                function limpiar() {
                    // Fondo blanco: transparente se vería negro en el PDF.
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, lienzo.width, lienzo.height);
                    ctx.strokeStyle = '#111827';
                    ctx.lineWidth = 2.6;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    hayFirma = false;
                    campo.value = '';
                    estado.textContent = 'Todavía no has firmado.';
                }

                function punto(e) {
                    const caja = lienzo.getBoundingClientRect();
                    return { x: (e.clientX - caja.left) * (lienzo.width / caja.width),
                             y: (e.clientY - caja.top) * (lienzo.height / caja.height) };
                }

                lienzo.addEventListener('pointerdown', function (e) {
                    trazando = true;
                    lienzo.setPointerCapture(e.pointerId);
                    const p = punto(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                });
                lienzo.addEventListener('pointermove', function (e) {
                    if (! trazando) return;
                    const p = punto(e);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                    hayFirma = true;
                    estado.textContent = 'Firma lista.';
                });
                ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
                    lienzo.addEventListener(ev, function () { trazando = false; });
                });

                document.getElementById('borrar-firma').addEventListener('click', limpiar);

                document.getElementById('form-firma').addEventListener('submit', function (e) {
                    if (! hayFirma) {
                        e.preventDefault();
                        estado.textContent = 'Dibuja tu firma en el recuadro antes de enviar.';
                        estado.style.color = '#9B2C2C';
                        lienzo.scrollIntoView({ block: 'center' });
                        return;
                    }
                    campo.value = lienzo.toDataURL('image/png');
                });

                limpiar();
            })();
        </script>
    @elseif ($acta->status === 'anulada')
        <div class="acta-aviso error"><strong>Esta acta fue anulada.</strong> Ya no hay nada que firmar.</div>
    @elseif ($yaFirmo)
        <div class="acta-aviso ok">
            <strong>Ya firmaste esta acta</strong> el {{ $acta->{"{$parte}_firmo_at"}->timezone(config('fabos.lab.timezone'))->format('d/m/Y \a \l\a\s H:i') }}.
            @unless ($acta->estaFirmadaPorTodos()) Falta la firma de la otra parte. @endunless
        </div>
    @endif
</main>
@endsection
