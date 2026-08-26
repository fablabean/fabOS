@extends('layouts.app')
@section('title', 'Mi cuenta · fabOS')

@php $tz = config('fabos.lab.timezone'); @endphp

@section('content')
    <h1>Hola, {{ $usuario->name }}</h1>
    <p class="help">
        <span class="who">{{ $usuario->email }}</span>
        · Categoría <strong>{{ $usuario->category?->name ?? 'sin asignar' }}</strong>
        @unless ($usuario->category_confirmed)
            <span class="pill warn" style="margin-left:.4rem">pendiente de confirmar</span>
        @endunless
    </p>

    {{-- ---------------------------------------------------- saldo --}}
    @php
        $moneda = config('fabos.currency.code');
        $unidades = config('fabos.currency.minor_units');
        $cobrosActivos = \App\Support\Settings::cobrosActivos();
    @endphp

    <h2>Mi saldo</h2>
    <div class="panel">
        <p style="margin:0;font-size:2rem;font-weight:700;letter-spacing:-.02em">
            {{ number_format($saldo / $unidades, 2, ',', '.') }}
            <span style="font-size:1rem;font-weight:500;color:var(--muted)">{{ $moneda }}</span>
        </p>

        @unless ($cobrosActivos)
            <p class="help" style="margin:.6rem 0 0">
                Los cobros todavía están apagados: reservar no descuenta saldo. Las reservas
                sí guardan lo que habrían costado, para poder revisarlo antes de encenderlo.
            </p>
        @else
            <p class="help" style="margin:.6rem 0 0">
                Al reservar se retiene el estimado y al cerrar se cobra lo que realmente usaste;
                la diferencia vuelve a tu saldo.
            </p>
        @endunless

        @if ($movimientos->isNotEmpty())
            <table style="margin-top:1rem">
                <thead><tr><th>Cuándo</th><th>Concepto</th><th style="text-align:right">Importe</th></tr></thead>
                <tbody>
                @foreach ($movimientos as $m)
                    <tr>
                        <td>{{ $m->transaction?->occurred_at?->timezone($tz)->format('d/m/Y H:i') }}</td>
                        <td>
                            {{ \App\Models\LedgerTransaction::TIPOS[$m->transaction?->kind] ?? $m->transaction?->kind }}
                            <div class="quien">{{ $m->transaction?->memo }}</div>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            {{ $m->esDebito() ? '−' : '+' }}
                            {{ number_format($m->amount_minor / $unidades, 2, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ---------------------------------------------------- certifabs --}}
    <h2>Lo que estoy habilitado a usar</h2>

    @if ($certifabs->isEmpty())
        <div class="panel">
            <p style="margin:0">Todavía no tienes ninguna habilitación.</p>
            <p class="help" style="margin:.6rem 0 0">
                Cada equipo pide un certifab. Entra al catálogo, elige el que te interesa
                y ahí verás qué necesitas para habilitarte.
            </p>
            <a href="{{ route('reservas.index') }}"><button type="button">Ver el catálogo</button></a>
        </div>
    @else
        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Habilita</th><th>Nivel</th><th>Vigencia</th>
                        <th>Otorgado por</th><th>Verificación</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($certifabs as $c)
                    @php $estado = $c->estado(); @endphp
                    <tr>
                        <td>
                            <strong>{{ $c->asset?->name ?? $c->riskFamily?->name }}</strong>
                            <div class="quien">
                                {{ $c->asset?->area?->name ?? $c->riskFamily?->area?->name }}
                                · {{ $c->asset_id ? 'equipo puntual' : 'toda la familia' }}
                            </div>
                        </td>
                        <td>{{ $c->level }}</td>
                        <td>
                            <span class="pill {{ $estado === 'vigente' ? 'ok' : 'bad' }}">{{ $estado }}</span>
                            @if ($c->expires_at)
                                <div class="quien">hasta {{ $c->expires_at->timezone($tz)->format('d/m/Y') }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $c->grantedBy?->name ?? '—' }}
                            <div class="quien">{{ $c->granted_at?->timezone($tz)->format('d/m/Y') }}</div>
                        </td>
                        <td>
                            {{-- El código es lo que le sirve a la persona para
                                 demostrar su habilitación fuera del sistema. --}}
                            <a href="{{ route('publico.verificar', $c->public_code) }}" target="_blank">
                                <span class="who">{{ $c->public_code }}</span>
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="foot" style="margin-top:.9rem">
                Comparte el código o el enlace para que cualquiera verifique tu habilitación
                sin tener que preguntarle al laboratorio.
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------- cursos --}}
    @if ($cursos->isNotEmpty())
        <h2>Mi formación</h2>
        <div class="panel">
            <table>
                <thead><tr><th>Curso</th><th>Cohorte</th><th>Estado</th><th>Certificado</th></tr></thead>
                <tbody>
                @foreach ($cursos as $inscripcion)
                    <tr>
                        <td>
                            <strong>{{ $inscripcion->edition?->course?->name }}</strong>
                            <div class="quien">nivel {{ $inscripcion->edition?->course?->level }}</div>
                        </td>
                        <td>
                            {{ $inscripcion->edition?->starts_on?->format('d/m/Y') }}
                            <div class="quien">{{ $inscripcion->edition?->code }}</div>
                        </td>
                        <td>
                            <span class="pill {{ $inscripcion->aprobada() ? 'ok' : ($inscripcion->status === 'reprobado' ? 'bad' : 'warn') }}">
                                {{ \App\Models\Enrollment::ESTADOS[$inscripcion->status] ?? $inscripcion->status }}
                            </span>
                        </td>
                        <td>
                            @if ($inscripcion->certificate_code)
                                <a href="{{ route('publico.verificar', $inscripcion->certificate_code) }}" target="_blank">
                                    <span class="who">{{ $inscripcion->certificate_code }}</span>
                                </a>
                            @elseif ($inscripcion->status === 'inscrito')
                                <form method="POST" action="{{ route('formacion.retirar', $inscripcion) }}">
                                    @csrf
                                    <button type="submit"
                                            style="margin:0;padding:.3rem .7rem;font-size:.78rem;
                                                   background:transparent;color:var(--muted);
                                                   border:1px solid var(--rule)">
                                        Liberar mi cupo
                                    </button>
                                </form>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="foot" style="margin-top:.9rem">
                El código del certificado sirve fuera del laboratorio: cualquiera puede
                verificarlo sin preguntarle a la Universidad.
            </p>
        </div>
    @endif

    {{-- --------------------------------------------------- proyectos --}}
    @if ($proyectos->isNotEmpty())
        <h2>Mis proyectos</h2>

        <div class="panel">
            <table>
                <thead><tr><th>Código</th><th>Proyecto</th><th>En qué va</th><th></th></tr></thead>
                <tbody>
                @foreach ($proyectos as $proyecto)
                    <tr>
                        <td class="quien">{{ $proyecto->code }}</td>
                        <td>
                            {{ $proyecto->name }}
                            @if ($proyecto->organization)
                                <div class="quien">{{ $proyecto->organization }}</div>
                            @endif
                        </td>
                        <td>
                            {{ \App\Models\Project::ETAPAS[$proyecto->stage] ?? $proyecto->stage }}
                            @if ($proyecto->proposal_sent_at)
                                <div class="quien">
                                    propuesta enviada el
                                    {{ $proyecto->proposal_sent_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
                                </div>
                            @elseif ($proyecto->stage === 'idea')
                                <div class="quien">en revisión</div>
                            @endif
                        </td>
                        <td>
                            @if ($proyecto->proposal_sent_at)
                                <a href="{{ route('proyectos.propuesta', $proyecto) }}">Ver la propuesta →</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- --------------------------------------------------- asesorías --}}
    @if ($asesorias->isNotEmpty())
        <h2>Mis próximas asesorías</h2>

        <div class="panel">
            <p class="help" style="margin-top:0">
                Alguien del laboratorio te acompaña. No reservan la máquina: si además vas a
                usarla, resérvala aparte.
            </p>

            <table>
                <thead><tr><th>Equipo</th><th>Te atiende</th><th>Cuándo</th></tr></thead>
                <tbody>
                @foreach ($asesorias as $a)
                    <tr>
                        <td>{{ $a->advisoryAsset?->name ?? '—' }}</td>
                        <td>{{ $a->reservable?->name ?? '—' }}</td>
                        <td>{{ $a->starts_at->timezone($tz ?? config('fabos.lab.timezone'))->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Para quien es del equipo: lo que le toca atender. --}}
    @if ($asesoriasQueAtiendo->isNotEmpty())
        <h2>Asesorías que voy a atender</h2>

        <div class="panel">
            <table>
                <thead><tr><th>Equipo</th><th>Quién la pidió</th><th>Cuándo</th></tr></thead>
                <tbody>
                @foreach ($asesoriasQueAtiendo as $a)
                    <tr>
                        <td>{{ $a->advisoryAsset?->name ?? '—' }}</td>
                        <td>{{ $a->user?->name ?? '—' }}</td>
                        <td>{{ $a->starts_at->timezone($tz ?? config('fabos.lab.timezone'))->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ---------------------------------------------------- reservas --}}
    <h2>Mis próximas reservas</h2>

    @if ($reservas->isEmpty())
        <div class="panel">
            <p style="margin:0">No tienes reservas próximas.</p>
            <a href="{{ route('reservas.index') }}"><button type="button">Reservar un equipo</button></a>
        </div>
    @else
        <div class="panel">
            <table>
                <thead><tr><th>Equipo</th><th>Cuándo</th><th>Estado</th></tr></thead>
                <tbody>
                @foreach ($reservas as $r)
                    <tr>
                        <td>{{ $r->reservable?->name ?? '—' }}</td>
                        <td>
                            {{ $r->starts_at->timezone($tz)->format('d/m/Y H:i') }}
                            — {{ $r->ends_at->timezone($tz)->format('H:i') }}
                        </td>
                        <td>
                            <span class="pill {{ $r->status === 'confirmada' ? 'ok' : 'warn' }}">
                                {{ \App\Models\Reservation::ESTADOS[$r->status] ?? $r->status }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ------------------------------------------------ como entro --}}
    <h2>Cómo entro</h2>
    <div class="panel">
        @if (auth()->user()->tieneSegundoFactor())
            <p>
                Entras con el código de tu <strong>aplicación de autenticación</strong>. No
                dependes del correo: el código lo genera tu teléfono.
            </p>
        @else
            <p>
                Ahora entras con un código que te llega al correo. Puedes usar una
                <strong>aplicación de autenticación</strong> en su lugar: el código lo genera
                tu teléfono, así que funciona aunque el correo tarde o no llegue.
            </p>
        @endif
        <p><a href="{{ route('cuenta.app') }}">Configurar la aplicación de autenticación →</a></p>
    </div>

    {{-- ---------------------------------------------------- avisos --}}
    @if ($avisos->isNotEmpty())
        <h2>Qué avisos quiero recibir</h2>
        <div class="panel">
            <form method="POST" action="{{ route('cuenta.avisos') }}">
                @csrf
                @foreach ($avisos as $fila)
                    <label style="display:flex;gap:.6rem;align-items:flex-start;text-transform:none;
                                  letter-spacing:0;font-family:inherit;font-size:.92rem;
                                  color:var(--ink);margin-bottom:.7rem">
                        <input type="checkbox" style="width:auto;margin-top:.25rem"
                               name="avisos[{{ $fila['plantilla']->key }}]" value="1"
                               @checked($fila['recibe'])>
                        <span>
                            {{ $fila['plantilla']->name }}
                            @if ($fila['plantilla']->description)
                                <span class="quien" style="display:block">{{ $fila['plantilla']->description }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach

                <button type="submit">Guardar</button>
            </form>

            <p class="foot" style="margin-top:.9rem">
                Hay avisos que no se pueden desactivar —que tu equipo entró a mantenimiento,
                que se liberó tu reserva—: enterarte tarde de eso te haría perder el viaje.
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------- carné --}}
    @if (\App\Support\Settings::carnetLoginEnabled())
        <h2>Carné digital</h2>
        <div class="panel">
            @if ($usuario->carnet_subject)
                <p style="margin:0">
                    Vinculado desde
                    {{ $usuario->carnet_linked_at?->timezone($tz)->format('d/m/Y') }}.
                    Ya puedes entrar escaneándolo.
                </p>
            @else
                <p class="help" style="margin:0 0 .4rem">
                    Vincula tu carné para entrar escaneándolo, sin esperar el código del correo.
                </p>
                <form method="POST" action="{{ route('carnet.link') }}">
                    @csrf
                    <label for="carnet">Enlace de tu carné</label>
                    <input id="carnet" name="carnet" type="text" required
                           placeholder="Pega aquí el enlace del carné digital">
                    <button type="submit">Vincular</button>
                </form>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('logout') }}" style="margin-top:2rem">
        @csrf
        <button type="submit" style="background:transparent;color:var(--muted);border:1px solid var(--rule)">
            Cerrar sesión
        </button>
    </form>
@endsection
