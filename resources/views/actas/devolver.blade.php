@extends('layouts.publico')
@section('title', 'Devolución · ' . $acta->code . ' · ' . config('fabos.lab.name'))

@section('styles')
    .dev-pagina{max-width:52rem;margin:0 auto;padding:1.6rem 1rem 3rem}
    .dev-pagina h1{font-size:1.5rem;margin:0 0 .3rem}
    .dev-aviso{padding:.8rem 1rem;border-radius:6px;margin:1rem 0;font-size:.95rem}
    .dev-aviso.error{background:color-mix(in srgb,#9B2C2C 13%,transparent)}
    .dev-aviso.ok{background:color-mix(in srgb,#0D6E63 13%,transparent)}
    .dev-caja{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.2rem;margin-top:1.2rem}
    .dev-caja h2{margin-top:0}
    .dev-item{display:grid;grid-template-columns:auto 1fr;gap:.2rem .8rem;padding:.9rem 0;border-top:1px solid var(--rule)}
    .dev-item:first-of-type{border-top:0}
    .dev-item input[type=checkbox]{margin-top:.3rem;width:1.15rem;height:1.15rem}
    .dev-item .nombre{font-weight:600}
    .dev-item .datos{font-size:.85rem;color:var(--muted)}
    .dev-item .como{grid-column:2;display:grid;grid-template-columns:minmax(9rem,12rem) 1fr;gap:.6rem;margin-top:.5rem}
    .dev-item select,.dev-item input[type=text],.dev-caja input[type=date],.dev-caja textarea{
        width:100%;padding:.5rem .6rem;font:inherit;background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px}
    .dev-item label,.dev-caja label{font-size:.8rem;font-weight:600;display:block}
    @media (max-width:560px){.dev-item .como{grid-template-columns:1fr}}
    .dev-caja canvas{width:100%;height:auto;aspect-ratio:700/220;background:#fff;border:1px dashed #888;border-radius:6px;touch-action:none;cursor:crosshair;display:block}
    .dev-fila{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin:.6rem 0 1rem}
    .dev-secundario{background:transparent;color:var(--accent);border:1px solid var(--accent);padding:.45rem .9rem;border-radius:4px;cursor:pointer;font:inherit}
    .dev-anteriores{list-style:none;padding:0;margin:.4rem 0 0}
    .dev-anteriores li{padding:.35rem 0;font-size:.92rem}
@endsection

@section('content')
<main class="dev-pagina">
    <p class="rotulo">Devolución de activos · acta {{ $acta->code }}</p>
    <h1>Registrar lo que volvió</h1>
    <p style="margin:0;color:var(--muted)">
        {{ $acta->tipoLegible() }} · salió el {{ $acta->fecha->format('d/m/Y') }} · lo tenía <strong>{{ $acta->recibe_nombre }}</strong>.
        @if ($acta->fecha_retorno)
            <span style="{{ $acta->devolucionVencida() ? 'color:#b91c1c;font-weight:600' : '' }}">
                {{ $acta->devolucionVencida() ? 'Debía volver el' : 'Vuelve el' }} {{ $acta->fecha_retorno->format('d/m/Y') }}.
            </span>
        @endif
    </p>

    @if ($errors->any())
        <div class="dev-aviso error">{{ $errors->first() }}</div>
    @endif

    @if ($acta->devoluciones->isNotEmpty())
        <div class="dev-caja">
            <h2>Lo que ya volvió</h2>
            <ul class="dev-anteriores">
                @foreach ($acta->devoluciones as $d)
                    <li>
                        <a href="{{ route('actas.devolucion.ver', $d) }}">{{ $d->code }}</a>
                        · {{ $d->fecha->format('d/m/Y') }}
                        · {{ $d->items->pluck('descripcion')->implode(', ') }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($pendientes->isEmpty())
        <div class="dev-aviso ok"><strong>Ya volvió todo</strong> lo que había salido con esta acta.</div>
    @else
        <form method="POST" action="{{ route('actas.devolver.guardar', $acta) }}" id="form-devolucion">
            @csrf

            <div class="dev-caja">
                <h2>Qué vuelve hoy</h2>
                <p style="margin:0 0 .4rem;color:var(--muted);font-size:.92rem">
                    Marca lo que estás recibiendo y en qué estado llega. Lo que no marques queda pendiente en el acta.
                </p>

                @foreach ($pendientes as $item)
                    @php $marcado = old("items.{$item->id}.vuelve", $pendientes->count() === 1 ? '1' : null); @endphp
                    <div class="dev-item">
                        <input type="checkbox" name="items[{{ $item->id }}][vuelve]" value="1" id="vuelve-{{ $item->id }}" @checked($marcado)>
                        <label for="vuelve-{{ $item->id }}" style="font-size:1rem;font-weight:400">
                            <span class="nombre">{{ $item->descripcion }}</span>
                            <span class="datos">
                                {{ collect([$item->marca, $item->modelo, $item->placa ? 'placa ' . $item->placa : null, $item->serie])->filter()->implode(' · ') }}
                                · salió en estado {{ mb_strtolower(\App\Models\Acta::CONDICIONES[$item->estado] ?? '') }}
                            </span>
                        </label>
                        <div class="como">
                            <div>
                                <label for="estado-{{ $item->id }}">Cómo vuelve</label>
                                <select name="items[{{ $item->id }}][estado]" id="estado-{{ $item->id }}">
                                    @foreach (\App\Models\Acta::CONDICIONES as $clave => $texto)
                                        <option value="{{ $clave }}" @selected(old("items.{$item->id}.estado", $item->estado) === $clave)>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="nota-{{ $item->id }}">Qué se nota (opcional)</label>
                                <input type="text" name="items[{{ $item->id }}][nota]" id="nota-{{ $item->id }}" maxlength="300"
                                       value="{{ old("items.{$item->id}.nota") }}" placeholder="Rayón en la carcasa, le falta el cargador…">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="dev-caja">
                <label for="fecha">Fecha de devolución</label>
                <input type="date" name="fecha" id="fecha" required style="max-width:12rem"
                       value="{{ old('fecha', now(config('fabos.lab.timezone'))->toDateString()) }}"
                       max="{{ now(config('fabos.lab.timezone'))->toDateString() }}">

                <label for="observaciones" style="margin-top:1rem">Observaciones (opcional)</label>
                <textarea name="observaciones" id="observaciones" rows="2" maxlength="1000">{{ old('observaciones') }}</textarea>
            </div>

            <div class="dev-caja">
                <h2>Tu firma</h2>
                <p style="margin:0 0 .6rem;color:var(--muted);font-size:.92rem">
                    {{ auth()->user()->name }}, firmas como quien recibe los activos en el laboratorio.
                </p>

                <canvas id="lienzo-firma" width="700" height="220" aria-label="Recuadro para dibujar la firma"></canvas>

                <div class="dev-fila">
                    <button type="button" class="dev-secundario" id="borrar-firma">Borrar y volver a firmar</button>
                    <span id="estado-firma" style="color:var(--muted);font-size:.9rem">Todavía no has firmado.</span>
                </div>

                <input type="hidden" name="firma" id="campo-firma">

                <button type="submit" class="btn" style="padding:.8rem 1.4rem;font-size:1rem">Registrar la devolución</button>
            </div>
        </form>

        <script>
            (function () {
                const lienzo = document.getElementById('lienzo-firma');
                const ctx = lienzo.getContext('2d');
                const campo = document.getElementById('campo-firma');
                const estado = document.getElementById('estado-firma');
                let trazando = false, hayFirma = false;

                function limpiar() {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, lienzo.width, lienzo.height);
                    ctx.strokeStyle = '#111827';
                    ctx.lineWidth = 2.6;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    hayFirma = false;
                    campo.value = '';
                    estado.textContent = 'Todavía no has firmado.';
                    estado.style.color = '';
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
                    estado.style.color = '';
                });
                ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
                    lienzo.addEventListener(ev, function () { trazando = false; });
                });

                document.getElementById('borrar-firma').addEventListener('click', limpiar);

                document.getElementById('form-devolucion').addEventListener('submit', function (e) {
                    const algo = document.querySelector('.dev-item input[type=checkbox]:checked');

                    if (! algo || ! hayFirma) {
                        e.preventDefault();
                        estado.textContent = ! algo
                            ? 'Marca al menos un activo de los que vuelven.'
                            : 'Dibuja tu firma en el recuadro antes de registrar.';
                        estado.style.color = '#9B2C2C';
                        (algo ? lienzo : document.querySelector('.dev-item')).scrollIntoView({ block: 'center' });
                        return;
                    }

                    campo.value = lienzo.toDataURL('image/png');
                });

                limpiar();
            })();
        </script>
    @endif
</main>
@endsection
