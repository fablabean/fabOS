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
                     retiene. En una impresora es el dato que da el laminador. --}}
                @if ($tarifaMaterial)
                    @php
                        $unidadMaterial = $tarifaMaterial->unit ?: 'unidades';
                        $reglaMaterial = \App\Services\Money\MaterialDelEquipo::regla($tarifaMaterial);
                    @endphp
                    <div class="agenda-campo agenda-material">
                    <label for="material">Material que vas a usar <span style="text-transform:none;letter-spacing:0">({{ $unidadMaterial }})</span></label>
                    <input id="material" name="material" type="number" required min="1" max="100000" step="any" inputmode="decimal"
                           placeholder="60" value="{{ old('material') }}"
                           data-precio="{{ $tarifaMaterial->price_minor }}"
                           data-por-cada="{{ \App\Services\Money\MaterialDelEquipo::porCada($tarifaMaterial) }}"
                           data-unidad="{{ $unidadMaterial }}"
                           data-base="{{ $cotizacion->totalMenor }}"
                           data-saldo="{{ $saldo }}"
                           data-cobra="{{ \App\Support\Settings::cobrosActivos() ? 1 : 0 }}"
                           data-pesos="{{ (int) config('fabos.currency.peso_rate') }}">
                    <p class="help" style="margin:.35rem 0 0">
                        {{ $reglaMaterial }}, redondeado al {{ config('fabos.currency.name') }} siguiente.
                        Escribe lo que te indica el laminador; al cerrar confirmas lo que gastaste de verdad.
                    </p>
                    <p id="material-cuenta" class="help" style="margin:.35rem 0 0;font-weight:600" aria-live="polite"></p>
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
    @if ($cotizacion->tieneDesglose())
        @php
            $moneda = config('fabos.currency.code');
            $unidades = config('fabos.currency.minor_units');
            $horas = intdiv($minutosCotizados, 60);
        @endphp

        <div class="panel">
            <h2 style="margin-top:0">Cuánto cuesta</h2>
            <p class="help" style="margin-top:0">
                Estimado para {{ $horas ? $horas . ($horas > 1 ? ' horas' : ' hora') : $minutosCotizados . ' minutos' }}
                de uso. Al cerrar se cobra el tiempo real y la diferencia vuelve a tu saldo.
            </p>

            <table>
                <tbody>
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
                @if ($tarifaMaterial ?? null)
                    <tr>
                        <th style="font-weight:500">
                            Material
                            <div class="quien"><span id="desglose-material-detalle">según lo que escribas arriba</span>
                                · {{ \App\Services\Money\MaterialDelEquipo::regla($tarifaMaterial) }}</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap" id="desglose-material">—</td>
                    </tr>
                @endif
                <tr>
                    <th>Total estimado</th>
                    <td style="text-align:right;white-space:nowrap;font-weight:700">
                        <span id="desglose-total">{{ number_format($cotizacion->total(), 2, ',', '.') }}</span> {{ $moneda }}
                    </td>
                </tr>
                @if ($tarifaMaterial ?? null)
                    <tr>
                        <th style="font-weight:500">
                            Se retiene al reservar
                            <div class="quien">al cerrar se ajusta a lo que gastaste; lo que sobre vuelve a tu saldo</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap" id="desglose-retiene">—</td>
                    </tr>
                @endif
                @if ($cotizacion->depositoMenor > 0)
                    <tr>
                        <th style="font-weight:500">
                            Se retiene al reservar
                            <div class="quien">garantía; el resto se cobra al cerrar</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">
                            {{ number_format($cotizacion->deposito(), 2, ',', '.') }}
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>

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
    @endif

    @if ($tarifaMaterial ?? null)
        <script>
            // La cuenta del material, mientras se escribe. Es la misma del
            // servidor —proporcional y al FabCoin siguiente—; la que manda es
            // la de allá.
            (function () {
                const campo = document.getElementById('material');
                if (! campo) return;

                const d = campo.dataset;
                const UNIDADES = {{ (int) config('fabos.currency.minor_units') }};
                const MONEDA = @json(config('fabos.currency.code'));
                const SIMBOLO = @json(config('fabos.money.symbol'));
                const cuenta = document.getElementById('material-cuenta');
                const fbc = (menor) => (menor / UNIDADES).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const poner = (id, texto) => { const e = document.getElementById(id); if (e) e.textContent = texto; };

                function calcular() {
                    const cantidad = parseFloat(String(campo.value).replace(',', '.'));
                    const base = parseInt(d.base, 10) || 0;

                    if (! (cantidad > 0)) {
                        cuenta.textContent = '';
                        poner('desglose-material', '—');
                        poner('desglose-material-detalle', 'según lo que escribas arriba');
                        poner('desglose-total', fbc(base));
                        poner('desglose-retiene', '—');
                        return;
                    }

                    const exacto = parseFloat(d.precio) * cantidad / parseFloat(d.porCada) / UNIDADES;
                    const material = Math.ceil(Math.round(exacto * 1e6) / 1e6) * UNIDADES;
                    const total = base + material;
                    const falta = total - (parseInt(d.saldo, 10) || 0);
                    const cuanto = cantidad.toLocaleString('es-CO', { maximumFractionDigits: 2 }) + ' ' + d.unidad;

                    poner('desglose-material', fbc(material));
                    poner('desglose-material-detalle', cuanto);
                    poner('desglose-total', fbc(total));
                    poner('desglose-retiene', fbc(total));

                    if (d.cobra === '1' && falta > 0) {
                        const pesos = Math.round(falta / UNIDADES * parseInt(d.pesos, 10));
                        cuenta.style.color = '#f87171';
                        cuenta.textContent = cuanto + ' son ' + fbc(material) + ' ' + MONEDA + '. Te faltan ' + fbc(falta) + ' ' + MONEDA
                            + ' (' + SIMBOLO + pesos.toLocaleString('es-CO') + '): recárgalos en el laboratorio antes de reservar.';
                    } else {
                        cuenta.style.color = '';
                        cuenta.textContent = cuanto + ' son ' + fbc(material) + ' ' + MONEDA
                            + (d.cobra === '1' ? '. Se retienen ' + fbc(total) + ' ' + MONEDA + ' al reservar.' : '.');
                    }
                }

                campo.addEventListener('input', calcular);
                calcular();
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
                    {{ $activo->autonomous_minutes
                        ? intdiv($activo->autonomous_minutes, 60) . ' h ' . ($activo->autonomous_minutes % 60 ?: '') . ' min'
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
