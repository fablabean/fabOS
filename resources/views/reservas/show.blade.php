@extends('layouts.app')
@section('title', $activo->name . ' · fabOS')

@php
    use App\Services\Booking\Eligibility;

    $clase = match ($veredicto->resultado) {
        Eligibility::AUTONOMO        => 'ok',
        Eligibility::CON_ACOMPANANTE => 'warn',
        default                      => 'bad',
    };
    $tz = config('fabos.lab.timezone');
@endphp

@section('content')
    {{-- La miga de pan del camino por el que se llegó, como en Reservas. Si
         se entra directo, la del camino que le corresponde al equipo. --}}
    @php
        $modoMiga = in_array(request('modo'), ['autonomia', 'herramientas'], true)
            ? request('modo')
            : ($activo->esHerramienta() ? 'herramientas' : 'autonomia');
        $areaMiga = $modoMiga === 'autonomia' ? (request('area') ?: $activo->area?->slug) : null;
        $nombreMiga = ['autonomia' => 'Hago mi pieza', 'herramientas' => 'Herramientas'][$modoMiga];
        $areaNombre = $areaMiga ? (\App\Models\Area::where('slug', $areaMiga)->value('name') ?? $activo->area?->name) : null;
    @endphp
    <nav class="migas" aria-label="Dónde estás">
        <a href="{{ route('publico.reservas') }}">Reservas</a>
        <span aria-hidden="true">›</span>
        <a href="{{ route('publico.reservas', ['modo' => $modoMiga]) }}">{{ $nombreMiga }}</a>
        @if ($areaNombre)
            <span aria-hidden="true">›</span>
            <a href="{{ route('publico.reservas', ['modo' => $modoMiga, 'area' => $areaMiga]) }}">{{ $areaNombre }}</a>
        @endif
        <span aria-hidden="true">›</span>
        <strong>{{ $activo->name }}</strong>
    </nav>
    <style>
        .migas{display:flex;flex-wrap:wrap;gap:.4rem;align-items:baseline;font-size:.82rem;color:var(--muted);
               margin:0 0 .6rem;font-family:ui-monospace,Consolas,monospace;letter-spacing:.04em;text-transform:uppercase}
        .migas a{color:var(--muted);text-decoration:none}
        .migas a:hover{color:var(--accent)}
        .migas strong{color:var(--ink-soft);font-weight:600}
    </style>

    <h1 style="margin-top:.6rem">{{ $activo->name }}</h1>
    <p class="help">
        {{ $activo->area?->name }}
        @if ($activo->riskFamily) · {{ $activo->riskFamily->name }} @endif
    </p>

    <div class="panel">
        <span class="pill {{ $clase }}">
            {{ match ($veredicto->resultado) {
                Eligibility::AUTONOMO        => 'Puedes reservar',
                Eligibility::CON_ACOMPANANTE => 'Con acompañamiento',
                default                      => 'Todavía no',
            } }}
        </span>
        <p style="margin:.3rem 0 0">{{ $veredicto->motivo }}</p>

        {{-- El «todavía no» no cierra la puerta: indica el camino (§10). --}}
        @if ($veredicto->faltantes)
            <p style="margin:.9rem 0 0;font-weight:600">Para habilitarte necesitas:</p>
            <ul class="falta">
                @foreach ($veredicto->faltantes as $f)
                    <li>{{ $f }}</li>
                @endforeach
            </ul>
            @if ($activo->advisors_count > 0)
                <p class="help" style="margin-top:.9rem">
                    Puedes empezar por una asesoría: alguien del equipo te acompaña y te
                    muestra cómo se usa. No necesitas el certifab para pedirla.
                </p>
                <p style="margin-top:.75rem">
                    <a class="btn" href="{{ route('asesoria.show', $activo) }}">Pedir asesoría</a>
                </p>
            @else
                {{-- Sin nadie declarado para asesorar, el sistema no tendria a quien
                     asignarla: se dice la verdad en vez de ofrecer un boton muerto. --}}
                <p class="help" style="margin-top:.9rem">
                    Escribe a la coordinación para agendar una asesoría de este equipo.
                    Al terminarla quedas habilitado y podrás reservar por tu cuenta.
                </p>
            @endif
        @endif

        @if ($veredicto->requierePresencia())
            <p class="help" style="margin:.9rem 0 0">
                Al reservar se asigna un colaborador certificado que esté en jornada,
                y se reserva también su tiempo.
            </p>
        @endif

        {{-- Estar habilitado no significa saberlo todo: una maquina que no se
             toca hace meses, un material raro o un acabado nuevo se resuelven
             antes preguntando que a base de intentos. Por eso la asesoria sigue
             ofreciendose a quien YA puede reservar. --}}
        @if ($veredicto->puedeReservar() && $activo->advisors_count > 0)
            <p class="help" style="margin:.9rem 0 0">
                ¿Dudas con este equipo? Puedes pedir una asesoría aunque ya estés
                habilitado: alguien del laboratorio te acompaña.
                <a href="{{ route('asesoria.show', $activo) }}">Pedir asesoría</a>
            </p>
        @endif
    </div>

    {{-- Lo que se necesita ademas de esta maquina.
         Hasta ahora las dependencias solo se usaban para bloquear —«no se puede
         usar mientras el compresor no este operativo»— y quien reservaba no
         sabia que existian hasta que algo fallaba. Dicho antes, se planifica. --}}
    @if ($activo->dependencies->isNotEmpty())
        <div class="panel">
            <h2 style="margin-top:0">No va solo</h2>
            <p class="help" style="margin-top:0">
                Este equipo trabaja junto con estos otros. Tenlo en cuenta al planear tu
                trabajo: si vas a necesitarlos, resérvalos aparte.
            </p>
            <ul class="falta">
                @foreach ($activo->dependencies as $dep)
                    <li>
                        <strong>{{ $dep->name }}</strong>
                        @if ($dep->pivot->note) — {{ $dep->pivot->note }} @endif
                        @if ($dep->status !== 'operativo')
                            <span style="color:#b45309">· {{ \App\Models\Asset::ESTADOS[$dep->status] ?? $dep->status }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($activo->photoUrl())
        <div class="panel" style="padding:0;overflow:hidden">
            <img src="{{ $activo->photoUrl() }}" alt="{{ $activo->name }}"
                 style="width:100%;max-height:340px;object-fit:cover;display:block">
        </div>
    @endif

    @if ($veredicto->puedeReservar())
        <div class="panel">
            <h2 style="margin-top:0">Elegir horario</h2>

            <form method="POST" action="{{ route('reservas.store', $activo) }}" class="agenda">
                @csrf

                <div class="agenda-campo">
                <label for="fecha">Fecha</label>
                <input id="fecha" name="fecha" type="date" required
                       min="{{ now($tz)->format('Y-m-d') }}"
                       value="{{ old('fecha', now($tz)->format('Y-m-d')) }}">
                </div>

                <div class="agenda-campo">
                <label for="inicio">Hora de inicio</label>
                @php $horario = \App\Support\HorarioDeAutoservicio::class; @endphp
                <input id="inicio" name="inicio" type="time" required step="900"
                       @if ($horario::activo('maquinas')) min="{{ $horario::desde() }}" max="{{ $horario::hasta() }}" @endif
                       value="{{ old('inicio', $horario::activo('maquinas') ? $horario::desde() : ($franjaHoy ? substr($franjaHoy[0], 0, 5) : '09:00')) }}">
                @include('reservas._horario', ['tipo' => 'maquinas'])
                </div>

                <div class="agenda-campo">
                <label for="duracion">Duración</label>
                <select id="duracion" name="duracion" required>
                    @foreach ([30, 60, 90, 120, 180, 240, 360, 480, 720] as $min)
                        @if ($min >= $activo->min_minutes && $min <= $activo->max_minutes)
                            <option value="{{ $min }}" @selected(old('duracion') == $min)>
                                {{-- Los minutos sueltos importan: con intdiv a secas,
                                     90 minutos se mostraba como «1 hora» y la lista
                                     parecia tener la misma opcion dos veces. --}}
                                @php
                                    $h = intdiv($min, 60);
                                    $m = $min % 60;
                                @endphp
                                {{ $h === 0
                                    ? $m . ' minutos'
                                    : $h . ' hora' . ($h > 1 ? 's' : '') . ($m ? ' ' . $m . ' min' : '') }}
                                @if ($veredicto->maxMinutos && $min > $veredicto->maxMinutos)
                                    — requiere visto bueno
                                @endif
                            </option>
                        @endif
                    @endforeach
                </select>
                </div>

                {{-- El material tarifado se dice aquí: de esto sale lo que se
                     retiene. En una impresora es obligatorio —el dato que da el
                     laminador—; en el láser es una opción: se trae o se compra. --}}
                @if ($tarifaMaterial)
                    @php $unidadMaterial = $tarifaMaterial->unit ?: 'unidades'; @endphp
                    <div class="agenda-campo agenda-material">
                    <label for="material">Material que vas a usar <span style="text-transform:none;letter-spacing:0">({{ $unidadMaterial }})</span></label>
                    <input id="material" name="material" type="number" required min="1" max="100000" step="any" inputmode="decimal"
                           placeholder="60" value="{{ old('material') }}">
                    <p class="help" style="margin:.35rem 0 0">
                        {{ \App\Services\Money\MaterialDelEquipo::regla($tarifaMaterial) }}, redondeado al {{ config('fabos.currency.name') }} siguiente.
                        Escribe lo que te indica el laminador; al cerrar confirmas lo que gastaste de verdad.
                    </p>
                    </div>
                @elseif ($materialesOfrecidos->isNotEmpty())
                    <div class="agenda-campo agenda-material">
                    <label for="material_tarifa">¿Compras material del laboratorio? <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                    <select id="material_tarifa" name="material_tarifa">
                        <option value="">No: traigo el mío</option>
                        @foreach ($materialesOfrecidos as $m)
                            @php $regalo = \App\Services\Money\MaterialDelEquipo::regalo($m); @endphp
                            <option value="{{ $m->id }}" @selected((string) old('material_tarifa') === (string) $m->id)
                                    data-unidad="{{ $m->unit ?: 'unidades' }}"
                                    data-superficie="{{ \App\Services\Money\MaterialDelEquipo::esSuperficie($m) ? 1 : 0 }}">
                                {{ $m->name }} — {{ \App\Services\Money\MaterialDelEquipo::regla($m) }}
                                @if ($regalo > 0)
                                    · {{ \App\Services\Money\MaterialDelEquipo::numero($regalo) }} {{ $m->unit }} de regalo
                                @endif
                            </option>
                        @endforeach
                    </select>

                    <div id="material-cuanto" style="display:none;margin-top:.6rem">
                        <div id="material-medidas" style="display:none;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem">
                            <input id="material-ancho" type="number" min="1" step="any" inputmode="decimal" placeholder="Ancho (cm)" style="max-width:9rem" aria-label="Ancho en centímetros">
                            <span>×</span>
                            <input id="material-alto" type="number" min="1" step="any" inputmode="decimal" placeholder="Alto (cm)" style="max-width:9rem" aria-label="Alto en centímetros">
                            <span class="help" style="margin:0">en centímetros</span>
                        </div>
                        <label for="material">Cuánto <span style="text-transform:none;letter-spacing:0">(<span id="material-unidad">unidades</span>)</span></label>
                        <input id="material" name="material" type="number" min="1" max="1000000" step="any" inputmode="decimal"
                               value="{{ old('material') }}">
                    </div>
                    <p class="help" style="margin:.35rem 0 0">
                        Lo que cuesta este equipo es el tiempo. El material lo puedes traer o comprarlo aquí;
                        al cerrar confirmas lo que gastaste de verdad.
                    </p>
                    </div>
                @endif

                <div class="agenda-campo agenda-ancho">
                <label for="proposito">¿Para qué? <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                <input id="proposito" name="proposito" type="text" maxlength="500"
                       placeholder="Prototipo de la clase de diseño" value="{{ old('proposito') }}">
                </div>

                <div class="agenda-campo agenda-boton">
                    <button type="submit">Reservar</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ------------------------------------------------- cuánto cuesta (§12) --}}
    {{-- También cuando sale gratis por las horas incluidas: a quien no le
         cuesta le interesa saber por qué, y cuánto cupo le queda. --}}
    @php $hayMaterial = $tarifaMaterial || $materialesOfrecidos->isNotEmpty(); @endphp
    @if ($cotizacion->tieneDesglose() || $hayMaterial)
        @php
            $moneda = config('fabos.currency.code');
            $unidades = config('fabos.currency.minor_units');
            $horas = intdiv($minutosCotizados, 60);
        @endphp

        <div class="panel" id="cuanto-cuesta" data-url="{{ route('reservas.cotizar', $activo) }}" data-moneda="{{ $moneda }}">
            <h2 style="margin-top:0">Cuánto cuesta</h2>
            <p class="help" style="margin-top:0">
                Estimado para <span id="desglose-tiempo">{{ $horas ? $horas . ($horas > 1 ? ' horas' : ' hora') : $minutosCotizados . ' minutos' }}</span>
                de uso. Al cerrar se cobra el tiempo real y la diferencia vuelve a tu saldo.
            </p>

            {{-- Lo pinta el servidor una vez, y la página lo repinta cada vez
                 que cambia la duración o el material: misma cuenta, mismo sitio. --}}
            <table>
                <tbody id="desglose-cuerpo">
                @foreach ($cotizacion->lineas as $l)
                    <tr>
                        <th style="font-weight:500">
                            {{ $l['concepto'] }}
                            @if ($l['detalle'])
                                <div class="quien">{{ $l['detalle'] }}</div>
                            @endif
                        </th>
                        <td style="text-align:right;white-space:nowrap">
                            {{ number_format($l['importe'] / $unidades, 2, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <th>Total estimado</th>
                    <td style="text-align:right;white-space:nowrap;font-weight:700">
                        {{ number_format($cotizacion->total(), 2, ',', '.') }} {{ $moneda }}
                    </td>
                </tr>
                @if ($cotizacion->comprometidoMenor() > 0)
                    <tr>
                        <th style="font-weight:500">
                            Se retiene al reservar
                            <div class="quien">{{ $cotizacion->depositoMenor > 0
                                ? 'garantía; el resto se cobra al cerrar'
                                : 'al cerrar se ajusta a lo que usaste; lo que sobre vuelve a tu saldo' }}</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">
                            {{ number_format($cotizacion->comprometidoMenor() / $unidades, 2, ',', '.') }}
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>

            {{-- Solo superadmin: la cuenta con la categoría de otro. No cambia
                 la reserva, que sigue siendo suya y con su precio. --}}
            @if ($verComo->isNotEmpty())
                <div style="margin-top:.9rem;padding:.7rem .9rem;border:1px dashed var(--rule);border-radius:6px">
                    <label for="ver-como" style="margin:0 0 .3rem">Ver el precio como <span style="text-transform:none;letter-spacing:0">(solo superadmin)</span></label>
                    <select id="ver-como" style="max-width:22rem;margin:0">
                        <option value="">Yo{{ auth()->user()->category ? ' — ' . auth()->user()->category->name : '' }}</option>
                        @foreach ($verComo as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} — paga {{ \App\Services\Money\MaterialDelEquipo::numero((float) $c->rate_factor * 100) }} % del tiempo</option>
                        @endforeach
                    </select>
                    <p class="help" style="margin:.3rem 0 0">Es solo para mirar la cuenta: si reservas, la reserva es tuya y con tu precio.</p>
                </div>
            @endif

            <p id="desglose-falta" class="help" style="display:none;margin:.9rem 0 0;font-weight:600;color:#dc2626" aria-live="polite"></p>

            <p class="foot" style="margin-top:.9rem">
                Tu saldo: <strong>{{ number_format($saldo / $unidades, 2, ',', '.') }} {{ $moneda }}</strong>.
                @unless (\App\Support\Settings::cobrosActivos())
                    Los cobros están apagados: por ahora reservar no descuenta nada.
                @endunless
                @if ($cotizacion->esSupuesta)
                    Esta tarifa es provisional, pendiente de aprobación de la coordinación.
                @endif
            </p>
        </div>

        <script>
            // La cuenta, cada vez que cambia algo del formulario. Se le pide
            // al servidor: lleva el cupo del certifab, el regalo del material
            // y el redondeo, y hacerla aquí otra vez sería tener dos.
            (function () {
                const panel = document.getElementById('cuanto-cuesta');
                const cuerpo = document.getElementById('desglose-cuerpo');
                const el = (id) => document.getElementById(id);
                const duracion = el('duracion');
                if (! panel || ! cuerpo || ! duracion) return;

                const material = el('material');
                const tarifa = el('material_tarifa');
                const ancho = el('material-ancho');
                const alto = el('material-alto');
                const falta = el('desglose-falta');
                const MONEDA = panel.dataset.moneda;
                let turno = 0;

                function fila(titulo, detalle, importe, fuerte) {
                    const tr = document.createElement('tr');
                    const th = document.createElement('th');
                    const td = document.createElement('td');

                    th.textContent = titulo;
                    if (! fuerte) th.style.fontWeight = '500';

                    if (detalle) {
                        const d = document.createElement('div');
                        d.className = 'quien';
                        d.textContent = detalle;
                        th.appendChild(d);
                    }

                    td.textContent = importe;
                    td.style.textAlign = 'right';
                    td.style.whiteSpace = 'nowrap';
                    if (fuerte) td.style.fontWeight = '700';

                    tr.append(th, td);
                    return tr;
                }

                // El material opcional: solo se pregunta cuánto si se compra, y
                // lo que va por superficie se dice en ancho por alto.
                function acomodar() {
                    if (! tarifa) return;

                    const opcion = tarifa.selectedOptions[0];
                    const compra = tarifa.value !== '';

                    el('material-cuanto').style.display = compra ? '' : 'none';
                    material.required = compra;

                    if (! compra) {
                        material.value = '';
                        return;
                    }

                    el('material-unidad').textContent = opcion.dataset.unidad;
                    el('material-medidas').style.display = opcion.dataset.superficie === '1' ? 'flex' : 'none';
                }

                function medir() {
                    const a = parseFloat(ancho.value), b = parseFloat(alto.value);

                    if (a > 0 && b > 0) {
                        material.value = Math.round(a * b * 100) / 100;
                    }
                }

                async function cotizar() {
                    const mio = ++turno;
                    const q = new URLSearchParams({ duracion: duracion.value });

                    if (el('fecha') && el('fecha').value) q.set('fecha', el('fecha').value);
                    if (el('inicio') && el('inicio').value) q.set('inicio', el('inicio').value);
                    if (material && parseFloat(material.value) > 0) q.set('material', material.value);
                    if (tarifa && tarifa.value) q.set('material_tarifa', tarifa.value);
                    if (el('ver-como') && el('ver-como').value) q.set('como', el('ver-como').value);

                    let d;

                    try {
                        const r = await fetch(panel.dataset.url + '?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        if (! r.ok) return;
                        d = await r.json();
                    } catch (e) {
                        return;   // sin red: se queda lo que había, y al reservar manda el servidor
                    }

                    if (mio !== turno) return;   // llegó tarde: ya hay otra en camino

                    el('desglose-tiempo').textContent = d.tiempo;
                    cuerpo.replaceChildren(
                        ...d.lineas.map((l) => fila(l.concepto, l.detalle, l.importe, false)),
                        fila('Total estimado', null, d.total + ' ' + MONEDA, true),
                        ...(d.retiene ? [fila('Se retiene al reservar', d.esGarantia
                            ? 'garantía; el resto se cobra al cerrar'
                            : 'al cerrar se ajusta a lo que usaste; lo que sobre vuelve a tu saldo', d.retiene, false)] : []),
                    );

                    falta.style.display = d.falta ? '' : 'none';
                    falta.textContent = d.falta
                        ? 'Te faltan ' + d.falta + ' ' + MONEDA + ' (' + d.faltaEnPesos + ') para esta reserva: recárgalos en el laboratorio antes de reservar.'
                        : '';
                }

                let espera;
                const pronto = () => { clearTimeout(espera); espera = setTimeout(cotizar, 250); };

                duracion.addEventListener('change', cotizar);
                ['fecha', 'inicio'].forEach((id) => el(id) && el(id).addEventListener('change', cotizar));
                if (material) material.addEventListener('input', pronto);
                if (el('ver-como')) el('ver-como').addEventListener('change', cotizar);
                if (tarifa) tarifa.addEventListener('change', () => { acomodar(); cotizar(); });
                [ancho, alto].forEach((c) => c && c.addEventListener('input', () => { medir(); pronto(); }));

                acomodar();
                cotizar();
            })();
        </script>
    @endif

    {{-- ------------------------------------------------- lista de espera --}}
    <div class="panel">
        <h2 style="margin-top:0">¿Está siempre lleno?</h2>

        @if ($miEspera)
            <p style="margin:0">
                Estás en la lista de espera para
                <strong>{{ $miEspera->wants_from->timezone($tz)->format('d/m/Y') }}</strong>
                a <strong>{{ $miEspera->wants_until->timezone($tz)->format('d/m/Y') }}</strong>.
                Te avisamos si alguien suelta una franja dentro de esas fechas.
            </p>

            <form method="POST" action="{{ route('reservas.espera.salir', $miEspera) }}">
                @csrf
                <button type="submit"
                        style="background:transparent;color:var(--muted);border:1px solid var(--rule)">
                    Ya no me interesa
                </button>
            </form>
        @else
            <p class="help" style="margin-top:0">
                Dinos entre qué fechas te sirve y te avisamos si alguien cancela. No reserva
                nada: el hueco queda para quien lo tome primero.
            </p>

            <form method="POST" action="{{ route('reservas.esperar', $activo) }}" class="agenda espera">
                @csrf

                {{-- Desde y hasta son los dos extremos de UN rango: juntos se
                     leen como lo que son, apilados parecen dos preguntas. --}}
                <div class="agenda-campo">
                    <label for="espera-desde">Desde</label>
                    <input id="espera-desde" name="desde" type="date" required
                           min="{{ now($tz)->format('Y-m-d') }}"
                           value="{{ now($tz)->format('Y-m-d') }}">
                </div>

                <div class="agenda-campo">
                    <label for="espera-hasta">Hasta</label>
                    <input id="espera-hasta" name="hasta" type="date" required
                           min="{{ now($tz)->format('Y-m-d') }}"
                           value="{{ now($tz)->addWeeks(2)->format('Y-m-d') }}">
                </div>

                <div class="agenda-campo agenda-ancho">
                    <label for="espera-nota">Algo que debamos saber <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                    <input id="espera-nota" name="nota" type="text" maxlength="255"
                           placeholder="Solo puedo en la mañana">
                </div>

                <div class="agenda-campo agenda-boton">
                    <button type="submit">Avísenme si se libera</button>
                </div>
            </form>
        @endif
    </div>

    <div class="panel">
        <h2 style="margin-top:0">Condiciones del equipo</h2>
        <table>
            <tr>
                <th>Reserva mínima</th>
                <td>{{ $activo->min_minutes }} minutos</td>
            </tr>
            <tr>
                <th>Sin visto bueno</th>
                <td>
                    @php
                        $h = intdiv((int) $activo->autonomous_minutes, 60);
                        $m = (int) $activo->autonomous_minutes % 60;
                    @endphp
                    {{ $activo->autonomous_minutes
                        ? trim(($h ? $h . ($h > 1 ? ' horas' : ' hora') : '') . ($m ? ' ' . $m . ' minutos' : ''))
                        : 'siempre requiere visto bueno' }}
                </td>
            </tr>
            <tr>
                <th>Máximo</th>
                <td>{{ intdiv($activo->max_minutes, 60) }} horas</td>
            </tr>
            @if ($activo->unattended_use)
                <tr>
                    <th>Uso desatendido</th>
                    <td>El trabajo puede correr sin que estés presente.</td>
                </tr>
            @endif
            @if ($activo->dependencies->isNotEmpty())
                <tr>
                    <th>Requiere operativo</th>
                    <td>{{ $activo->dependencies->pluck('name')->implode(', ') }}</td>
                </tr>
            @endif
            @if ($activo->riskFamily?->safety_notes)
                <tr>
                    <th>Seguridad</th>
                    <td>{{ $activo->riskFamily->safety_notes }}</td>
                </tr>
            @endif
        </table>
    </div>

    <style>
        /* En pantalla ancha, los tres datos de la reserva caben en una fila:
           fecha, hora y duracion son una sola decision, y separarlos en tres
           bloques obligaba a recorrer media pantalla para tomarla. */
        .agenda { display: grid; gap: .75rem 1rem; align-items: end; }
        .agenda-campo { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
        .agenda-campo > label { margin: 0; }
        .agenda-campo > input, .agenda-campo > select { margin: 0; width: 100%; }
        .agenda-boton { align-self: end; }
        .agenda-boton > button { margin: 0; width: 100%; }

        @media (min-width: 720px) {
            .agenda { grid-template-columns: repeat(3, 1fr); }
            .agenda-ancho { grid-column: span 2; }
            .agenda-material { grid-column: 1 / -1; }
            .agenda-material input { max-width: 14rem; }

            /* La espera solo tiene dos fechas: tres columnas la dejarian
               desequilibrada, con un hueco donde no falta nada. */
            .espera { grid-template-columns: repeat(2, 1fr); }
            .espera .agenda-ancho { grid-column: span 2; }
            .espera .agenda-boton { grid-column: span 2; }
        }
    </style>
@endsection
