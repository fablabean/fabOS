@extends('layouts.publico')
@section('title', 'Firmar el reporte de horas extras · ' . config('fabos.lab.name'))

@php
    $tz = config('fabos.lab.timezone');
    $h = fn (int $min) => rtrim(rtrim(number_format($min / 60, 2, ',', '.'), '0'), ',') ?: '0';
    $esTrabajador = $parte === 'trabajador';
    $firmante = $esTrabajador ? $reporte->user : $reporte->coordinador;
    $abierto = $reporte->estado === 'por_firmar' && ! $reporte->estaAnulado() && ! $yaFirmo
        && ($esTrabajador || $reporte->firmo('trabajador'));
    $enc = $reporte->datos['encabezado'] ?? [];
@endphp

@section('styles')
    .nom-pagina{max-width:60rem;margin:0 auto;padding:1.6rem 1rem 3rem}
    .nom-pagina h1{font-size:1.5rem;margin:0 0 .3rem}
    .nom-hoja{background:var(--surface);padding:1rem;border:1px solid var(--rule);border-radius:6px;overflow-x:auto;margin:1.2rem 0}
    .nom-hoja table{width:100%;border-collapse:collapse;font-size:.9rem;min-width:40rem}
    .nom-hoja th,.nom-hoja td{padding:.45rem .5rem;border-bottom:1px solid var(--rule);text-align:left;vertical-align:top}
    .nom-hoja th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
    .nom-hoja td.n,.nom-hoja th.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    .nom-datos{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.4rem 1.2rem;margin:0 0 1rem;font-size:.93rem}
    .nom-datos dt{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
    .nom-datos dd{margin:0 0 .3rem}
    .nom-aviso{padding:.8rem 1rem;border-radius:6px;margin:1rem 0;font-size:.95rem}
    .nom-aviso.ok{background:color-mix(in srgb,#0D6E63 13%,transparent)}
    .nom-aviso.error{background:color-mix(in srgb,#9B2C2C 13%,transparent)}
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
<main class="nom-pagina">
    <p class="rotulo">Autorización trabajo horas extras · ADF-404-f4</p>
    <h1>Horas extras de {{ $reporte->user->name }}</h1>
    <p style="margin:0;color:var(--muted)">
        {{ $firmante?->name }}, firmas como <strong>{{ $esTrabajador ? 'colaborador' : 'jefe inmediato' }}</strong>.
        Corte del {{ $reporte->corte->format('d/m/Y') }}.
    </p>

    @if (session('firmado'))
        <div class="nom-aviso ok">
            <strong>Firma recibida.</strong>
            @if ($reporte->estaFirmadoPorTodos())
                El reporte ya tiene las dos firmas: falta enviarlo a novedades de nómina, desde «Mis horas extras».
            @else
                Le llegó a {{ $reporte->coordinador?->name }} el enlace para firmarlo como jefe inmediato.
            @endif
        </div>
    @endif

    @if ($errors->any())
        <div class="nom-aviso error">{{ $errors->first() }}</div>
    @endif

    <div class="nom-hoja">
        <dl class="nom-datos">
            <div><dt>Colaborador</dt><dd>{{ $enc['nombre'] ?? $reporte->user->name }}</dd></div>
            <div><dt>Documento</dt><dd>{{ $enc['documento'] ?? '—' }}</dd></div>
            <div><dt>Proceso o área</dt><dd>{{ $enc['area'] ?? '—' }}</dd></div>
            <div><dt>Jefe inmediato</dt><dd>{{ $reporte->coordinador?->name ?? ($enc['jefe'] ?? '—') }}</dd></div>
        </dl>

        <table>
            <thead>
                <tr><th>Fecha</th><th>Horario laboral</th><th>Horario extra</th><th class="n">Horas</th><th>Actividades realizadas</th></tr>
            </thead>
            <tbody>
                @foreach ($filas as $f)
                    @php $p = $f['partes']; $dia = \Illuminate\Support\Carbon::parse($f['fecha'], $tz); @endphp
                    <tr>
                        <td style="white-space:nowrap">
                            {{ ucfirst($dia->locale('es')->isoFormat('ddd D [de] MMM YYYY')) }}
                            @if (\App\Services\Staffing\FormatoAdf404::esFestivo($dia))
                                <div style="color:var(--muted);font-size:.8rem">Festivo</div>
                            @endif
                        </td>
                        <td style="white-space:nowrap">{{ $f['laboral_inicio'] }} a {{ $f['laboral_fin'] }}</td>
                        <td style="white-space:nowrap">{{ $f['extra_inicio'] }} a {{ $f['extra_fin'] }}</td>
                        <td class="n">
                            {{ $h(array_sum($p)) }}
                            <div style="color:var(--muted);font-size:.78rem">
                                {{ collect([
                                    $p['diurnas'] ? $h($p['diurnas']) . ' diurnas' : null,
                                    $p['nocturnas'] ? $h($p['nocturnas']) . ' nocturnas' : null,
                                    $p['diurnas_festivas'] ? $h($p['diurnas_festivas']) . ' diurnas fest.' : null,
                                    $p['nocturnas_festivas'] ? $h($p['nocturnas_festivas']) . ' nocturnas fest.' : null,
                                ])->filter()->implode(' · ') }}
                            </div>
                        </td>
                        <td>{{ $f['actividades'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th colspan="3">Total</th><th class="n">{{ $h($reporte->minutos) }} h</th><th></th></tr>
            </tfoot>
        </table>

        <p style="margin:.8rem 0 0;font-size:.9rem">
            <a href="{{ route('nomina.firmar.archivo', $token) }}">Descargar el Excel tal como va</a>
        </p>
    </div>

    @if ($abierto)
        <form method="POST" action="{{ route('nomina.firmar.guardar', $token) }}" class="firmar" id="form-firma">
            @csrf
            <h2>Tu firma</h2>
            <p style="margin:0 0 .6rem;color:var(--muted);font-size:.92rem">
                Dibújala en el recuadro, con el dedo o con el mouse. Es la que sale en el formato, en la casilla
                «{{ $esTrabajador ? 'Firma colaborador' : 'Nombre y firma del jefe inmediato' }}».
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
                    @if ($esTrabajador)
                        Revisé el reporte. Confirmo que trabajé estas horas extras, en las fechas y horarios indicados, y que mis datos son correctos.
                    @else
                        Revisé el reporte. Como jefe inmediato, autorizo las horas extras de {{ $reporte->user->name }} en las fechas y horarios indicados.
                    @endif
                </span>
            </label>

            <button type="submit" class="btn">Firmar el reporte</button>
        </form>

        <script>
            // El mismo lienzo de las actas: sin librerías, y solo viaja si de verdad se dibujó algo.
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
    @elseif ($reporte->estaAnulado())
        <div class="nom-aviso error"><strong>Este reporte fue anulado.</strong> Ya no hay nada que firmar.</div>
    @elseif ($yaFirmo)
        <div class="nom-aviso ok">
            <strong>Ya firmaste este reporte</strong> el {{ $reporte->{"{$parte}_firmo_at"}->timezone($tz)->format('d/m/Y \a \l\a\s H:i') }}.
            @if ($reporte->estado === 'enviado')
                Se envió a novedades de nómina el {{ $reporte->enviado_a_nomina_at->timezone($tz)->format('d/m/Y') }}.
            @elseif ($reporte->estaFirmadoPorTodos())
                Ya tiene las dos firmas: falta enviarlo a novedades de nómina.
            @else
                Falta la firma de {{ $esTrabajador ? $reporte->coordinador?->name : $reporte->user->name }}.
            @endif
        </div>
    @elseif (! $esTrabajador && ! $reporte->firmo('trabajador'))
        <div class="nom-aviso">Primero lo firma {{ $reporte->user->name }}. Te llega un correo cuando sea tu turno.</div>
    @endif
</main>
@endsection
