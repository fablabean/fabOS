@extends('layouts.app')
@section('title', 'Mi cuenta · fabOS')

@php $tz = config('fabos.lab.timezone'); @endphp

@section('content')
    {{-- La foto o las iniciales, y desde aqui mismo se cambia: es el
         circulo que sale en la barra de todo el sitio. --}}
    <div class="saludo">
        {{-- El círculo con la cámara encima: pulsarla elige la foto y la sube. --}}
        <form method="POST" action="{{ route('cuenta.foto') }}" enctype="multipart/form-data" class="foto-form">
            @csrf
            <label class="circulo-foto" title="{{ $usuario->photo_path ? 'Cambiar la foto' : 'Poner una foto' }}">
                <x-avatar :usuario="$usuario" tamano="4.6rem"/>
                <span class="camara" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>
                    </svg>
                </span>
                <input type="file" name="foto" accept="image/*" onchange="this.form.submit()">
                <span class="sr-only">{{ $usuario->photo_path ? 'Cambiar la foto' : 'Poner una foto' }}</span>
            </label>
        </form>
        <div>
            <h1 style="margin:0">Hola, {{ $usuario->name }}</h1>
            <p class="help" style="margin:.2rem 0 0">
                <span class="who">{{ $usuario->email }}</span>
                · Categoría <strong>{{ $usuario->category?->name ?? 'sin asignar' }}</strong>
                @unless ($usuario->category_confirmed)
                    <span class="pill warn" style="margin-left:.4rem">pendiente de confirmar</span>
                @endunless
            </p>

            {{-- Editar perfil: por ahora el nombre y la foto. --}}
            <details class="plegable perfil" @if ($errors->has('name') || $errors->has('foto')) open @endif>
                <summary>Editar perfil</summary>
                <form method="POST" action="{{ route('cuenta.perfil') }}">
                    @csrf
                    <label for="perfil-nombre">Nombre</label>
                    <input id="perfil-nombre" name="name" type="text" value="{{ old('name', $usuario->name) }}" required maxlength="255">
                    @error('name') <p class="msg error" style="margin:.2rem 0 0">{{ $message }}</p> @enderror
                    <button type="submit">Guardar</button>
                </form>
                @if ($usuario->photo_path)
                    <form method="POST" action="{{ route('cuenta.foto.quitar') }}" style="margin-top:.4rem">
                        @csrf
                        <button type="submit" class="foto-quitar">Quitar la foto</button>
                    </form>
                @endif
            </details>
            @error('foto') <p class="msg error" style="margin:.4rem 0 0">{{ $message }}</p> @enderror
        </div>
    </div>
    <style>
        .saludo{display:flex;gap:1rem;align-items:flex-start;margin-bottom:1.4rem}
        .saludo .avatar{font-size:1.5rem}
        .foto-form{margin:0;flex:none}
        .circulo-foto{position:relative;display:inline-block;cursor:pointer;line-height:0}
        .circulo-foto input{display:none}
        .circulo-foto .camara{
            position:absolute;right:-.15rem;bottom:-.15rem;width:1.6rem;height:1.6rem;border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;background:var(--surface);
            color:var(--ink);border:1px solid var(--rule);box-shadow:0 1px 4px rgba(0,0,0,.15);
        }
        .circulo-foto:hover .camara{color:var(--accent);border-color:var(--accent)}
        .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
        .perfil{margin-top:.5rem}
        .perfil > summary{font-size:.85rem;color:var(--link);cursor:pointer}
        .foto-quitar{background:none;border:0;padding:0;margin:0;font:inherit;font-size:.82rem;color:var(--muted);cursor:pointer;text-decoration:underline}
    </style>

    {{-- ---------------------------------------------------- saldo --}}
    @php
        $moneda = config('fabos.currency.code');
        $unidades = config('fabos.currency.minor_units');
        $cobrosActivos = \App\Support\Settings::cobrosActivos();
    @endphp

    <h2 id="saldo">Mi saldo</h2>
    <div class="panel">
        <p style="margin:0;font-size:2rem;font-weight:700;letter-spacing:-.02em">
            {{ number_format($saldo / $unidades, 2, ',', '.') }}
            <span style="font-size:1rem;font-weight:500;color:var(--muted)">{{ $moneda }}</span>
        </p>

        @unless ($cobrosActivos)
            <p class="help" style="margin:.6rem 0 0">
                Los cobros de reservas todavía están apagados: reservar no descuenta saldo. Las
                reservas sí guardan lo que habrían costado, para poder revisarlo antes de encenderlo.
                @if (\App\Support\Settings::cobrosEnTienda())
                    La tienda sí descuenta.
                @endif
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

                            {{-- Qué falta y por dónde seguir. Sin esto, quien
                                 aprueba el examen y no recibe el certifab no
                                 tiene forma de saber que espera una práctica. --}}
                            @if (! $inscripcion->aprobada() && $inscripcion->status !== 'retirado')
                                @php $curso = $inscripcion->edition?->course; @endphp

                                @if ($curso?->lessons?->isNotEmpty())
                                    <div class="quien" style="margin-top:.3rem">
                                        <a href="{{ route('formacion.teoria', $inscripcion) }}">Ver la teoría</a>
                                        @if ($curso->tieneExamen())
                                            ·
                                            <a href="{{ route('formacion.examen', $inscripcion) }}">
                                                {{ $inscripcion->teoriaAprobada() ? 'Repetir el examen' : 'Hacer el examen' }}
                                            </a>
                                        @endif
                                    </div>
                                @endif

                                @if ($falta = $inscripcion->queFaltaParaAprobar())
                                    <div class="quien">{{ $falta }}</div>
                                @endif

                                {{-- La práctica se pide aquí, no por correo: el
                                     sistema ofrece las horas en que alguien del
                                     área puede verla. --}}
                                @if ($practica = $inscripcion->practicaAgendada())
                                    <div class="quien" style="margin-top:.3rem">
                                        <strong>Práctica agendada:</strong>
                                        {{ $practica->starts_at->timezone($tz)->format('d/m/Y H:i') }}
                                        con {{ $practica->reservable?->name ?? 'el equipo' }}
                                        · <a href="{{ route('calendario.reserva', $practica) }}">Añadir a mi calendario</a>
                                        <form method="POST" action="{{ route('reservas.cancel', $practica) }}" style="display:inline"
                                              onsubmit="return confirm('¿Cancelar la práctica? Podrás pedir otra hora.')">
                                            @csrf
                                            <button type="submit" class="secundario" style="margin:0 0 0 .3rem;padding:.15rem .5rem;font-size:.78rem">Cancelar</button>
                                        </form>
                                    </div>
                                @elseif ($inscripcion->puedeAgendarPractica())
                                    <div class="quien" style="margin-top:.3rem">
                                        <a href="{{ route('formacion.practica', $inscripcion) }}"><strong>Agendar la prueba práctica →</strong></a>
                                    </div>
                                @endif
                            @endif
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
                            {{-- De los hechos y no de la etapa: «Idea» no
                                 significa nada para quien ya aceptó. --}}
                            @php $estado = $proyecto->estadoParaElCliente(); @endphp
                            {{ $estado['titulo'] }}
                            @if ($estado['detalle'])
                                <div class="quien">{{ $estado['detalle'] }}</div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('proyectos.propuesta', $proyecto) }}">
                                {{ $proyecto->proposal_sent_at ? 'Ver la propuesta' : 'Ver el proyecto' }} →
                            </a>
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

            @error('asesoria') <p class="msg error">{{ $message }}</p> @enderror

            <table>
                <thead><tr><th>Equipo</th><th>Te atiende</th><th>Cuándo</th><th></th></tr></thead>
                <tbody>
                @foreach ($asesorias as $a)
                    @php $tol = $a->starts_at->copy()->addMinutes(config('fabos.checkin.tolerancia')); @endphp
                    <tr>
                        <td>
                            {{-- Una general no tiene máquina: decir «—» obligaba a
                                 adivinar de qué iba. --}}
                            {{ $a->sobreQue() ?? '—' }}
                            {{-- El area debajo, salvo que ya este dicha arriba. --}}
                            @if (($area = $a->areaDeLoQueAtiende()) && ! str_contains($a->sobreQue() ?? '', $area->name))
                                <br><span class="help" style="margin:0;font-size:.82rem">{{ $area->name }}</span>
                            @endif
                        </td>
                        <td>{{ $a->reservable?->name ?? '—' }}</td>
                        <td>{{ $a->starts_at->timezone($tz ?? config('fabos.lab.timezone'))->format('d/m/Y H:i') }}</td>
                        <td style="text-align:right;white-space:nowrap">
                            @if ($a->checked_in_at)
                                <span class="pill ok">Atendida</span>
                            @elseif ($a->status === 'confirmada' && now()->greaterThan($tol))
                                {{-- La otra cara de la validación: si quien atiende
                                     no ha dicho nada pasada la tolerancia, quien
                                     pidió puede decir que no lo atendieron. --}}
                                <form method="POST" action="{{ route('asesoria.no_me_atendieron', $a) }}" style="display:inline"
                                      onsubmit="return confirm('¿Nadie te atendió? Queda anotado con tu nombre y el de quien debía atenderte.')">
                                    @csrf
                                    <button type="submit" class="secundario">No me atendieron</button>
                                </form>
                            @elseif ($a->status === 'solicitada')
                                <span class="pill warn">Pendiente</span>
                            @endif
                            @if (in_array($a->status, ['confirmada', 'en_curso'], true) && $a->ends_at->isFuture())
                                <br><a href="{{ route('calendario.reserva', $a) }}">Añadir a mi calendario</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Para quien es del equipo: lo que le proponen y lo que le toca atender. --}}
    @if ($traspasosRecibidos->isNotEmpty())
        <h2>Me proponen atender</h2>

        <div class="panel">
            <p class="help" style="margin-top:0">
                Alguien del equipo quiere pasarte una atención suya. Sigue a su nombre hasta que
                aceptes: si no puedes, recházala y se queda como estaba.
            </p>

            @error('traspaso') <p class="msg error">{{ $message }}</p> @enderror

            <table>
                <thead><tr><th>Qué</th><th>Quién te la pasa</th><th>Cuándo</th><th></th></tr></thead>
                <tbody>
                @foreach ($traspasosRecibidos as $t)
                    @php $r = $t->reservation; @endphp
                    <tr>
                        <td>
                            {{ $r->queAtiende() }}
                            @if ($area = $r->areaDeLoQueAtiende())
                                <br><span class="help" style="margin:0;font-size:.82rem">{{ $area->name }}</span>
                            @endif
                            <br><span class="help" style="margin:0;font-size:.82rem">Para {{ $r->user?->name ?? '—' }}</span>
                        </td>
                        <td>
                            {{ $t->from?->name ?? '—' }}
                            @if ($t->note)
                                <br><span class="help" style="margin:0;font-size:.82rem">«{{ $t->note }}»</span>
                            @endif
                        </td>
                        <td>
                            {{ $r->starts_at->timezone($tz)->format('d/m/Y H:i') }}
                            — {{ $r->ends_at->timezone($tz)->format('H:i') }}
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <form method="POST" action="{{ route('traspaso.aceptar', $t) }}" style="display:inline">
                                @csrf
                                <button type="submit" style="margin-top:0;padding:.35rem .7rem;font-size:.85rem">Acepto</button>
                            </form>
                            <form method="POST" action="{{ route('traspaso.rechazar', $t) }}" style="display:inline"
                                  onsubmit="return confirm('¿No puedes? Se queda a nombre de quien te la propuso, y se le avisa.')">
                                @csrf
                                <button type="submit" class="secundario" style="margin-top:0;padding:.35rem .7rem;font-size:.85rem">No puedo</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($asesoriasQueAtiendo->isNotEmpty())
        <h2>Asesorías que voy a atender</h2>

        <div class="panel">
            <p class="help" style="margin-top:0">
                Una asesoría no tiene QR: la llegada la validas tú. Si se te olvidó, se puede
                validar hasta {{ \App\Services\Booking\AsistenciaDeAsesoria::DIAS_PARA_VALIDAR }} días
                después; si la persona no vino, dilo aquí para que quede anotado. Si ese día no
                puedes, pásasela a alguien del equipo: queda a su nombre cuando acepte.
            </p>

            @error('asesoria') <p class="msg error">{{ $message }}</p> @enderror
            @if ($traspasosRecibidos->isEmpty()) @error('traspaso') <p class="msg error">{{ $message }}</p> @enderror @endif

            <table>
                <thead><tr><th>Sobre qué</th><th>Quién la pidió</th><th>Cuándo</th><th></th></tr></thead>
                <tbody>
                @foreach ($asesoriasQueAtiendo as $a)
                    @php
                        $abre = $a->starts_at->copy()->subMinutes(config('fabos.checkin.antes'));
                        $tol  = $a->starts_at->copy()->addMinutes(config('fabos.checkin.tolerancia'));
                    @endphp
                    <tr>
                        <td>
                            @if ($a->esPractica())
                                <span class="pill warn" style="margin:0 .3rem 0 0">Práctica</span>
                            @endif
                            {{ $a->sobreQue() ?? '—' }}
                            {{-- El area debajo, salvo que ya este dicha arriba. --}}
                            @if (($area = $a->areaDeLoQueAtiende()) && ! str_contains($a->sobreQue() ?? '', $area->name))
                                <br><span class="help" style="margin:0;font-size:.82rem">{{ $area->name }}</span>
                            @endif
                        </td>
                        <td>
                            {{ $a->user?->name ?? '—' }}
                            @if ($a->purpose && ! $a->esPractica())
                                <br><span class="help" style="margin:0;font-size:.82rem">«{{ $a->purpose }}»</span>
                            @endif
                        </td>
                        <td>
                            {{ $a->starts_at->timezone($tz)->format('d/m/Y H:i') }}
                            — {{ $a->ends_at->timezone($tz)->format('H:i') }}
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            @if ($a->checked_in_at)
                                <span class="pill ok">{{ $a->esPractica() ? 'Firmada' : 'Validada' }}</span>
                            @elseif ($a->esPractica() && $a->status === 'confirmada' && now()->greaterThanOrEqualTo($abre))
                                {{-- Una practica no se valida aqui: la firma la
                                     coordinacion en el panel, y esa firma da el
                                     certifab. --}}
                                <span class="help" style="margin:0;font-size:.82rem">Se firma en el panel, en la edición del curso</span>
                            @elseif ($a->status === 'confirmada' && now()->greaterThanOrEqualTo($abre))
                                <form method="POST" action="{{ route('asesoria.llego', $a) }}" style="display:inline">
                                    @csrf
                                    <button type="submit">Llegó</button>
                                </form>
                                @if (now()->greaterThan($tol))
                                    <form method="POST" action="{{ route('asesoria.no_vino', $a) }}" style="display:inline"
                                          onsubmit="return confirm('¿No vino? Queda como no presentada, con tu nombre.')">
                                        @csrf
                                        <button type="submit" class="secundario">No vino</button>
                                    </form>
                                @endif
                                {{-- Ya se puede validar la llegada, pero todavía no
                                     empezó: hasta ese momento se puede pasar. --}}
                                @if ($a->ends_at->isFuture() && ($a->traspasoPendiente || $candidatos->has($a->id)))
                                    <br>@include('cuenta._pasar', ['reserva' => $a, 'candidatos' => $candidatos->get($a->id)])
                                @endif
                            @elseif ($a->status === 'solicitada')
                                <span class="pill warn">Pendiente</span>
                            @elseif ($a->traspasoPendiente || $candidatos->has($a->id))
                                {{-- Confirmada y todavía lejos: es el momento de
                                     pasarla si ese día no se puede. --}}
                                @include('cuenta._pasar', ['reserva' => $a, 'candidatos' => $candidatos->get($a->id)])
                            @else
                                <span class="help">Desde las {{ $abre->timezone($tz ?? config('fabos.lab.timezone'))->format('H:i') }}</span>
                            @endif
                            @if (in_array($a->status, ['confirmada', 'en_curso'], true) && $a->ends_at->isFuture())
                                <br><a href="{{ route('calendario.reserva', $a) }}">Añadir a mi calendario</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Y los acompañamientos: una máquina que exige a alguien al lado, o
         un espacio donde se apuntó a acompañar. --}}
    @if ($acompanamientos->isNotEmpty())
        <h2>Acompañamientos que voy a hacer</h2>

        <div class="panel">
            <p class="help" style="margin-top:0">
                Te toca estar ahí. Si ese día no puedes, pásaselo a alguien del equipo: sigue a
                tu nombre hasta que acepte. Donde solo te toca <strong>recibir</strong> son unos
                minutos al empezar: ubicar a la persona y darle lo que necesite; no te ocupa la hora.
            </p>

            @if ($traspasosRecibidos->isEmpty() && $asesoriasQueAtiendo->isEmpty()) @error('traspaso') <p class="msg error">{{ $message }}</p> @enderror @endif

            <table>
                <thead><tr><th>Dónde</th><th>A quién</th><th>Cuándo</th><th></th></tr></thead>
                <tbody>
                @foreach ($acompanamientos as $r)
                    <tr>
                        <td>
                            {{ $r->reservable?->name ?? '—' }}
                            @if ($area = $r->areaDeLoQueAtiende())
                                <br><span class="help" style="margin:0;font-size:.82rem">{{ $area->name }}</span>
                            @elseif ($r->esRecorrido())
                                <br><span class="help" style="margin:0;font-size:.82rem">Recorrido</span>
                            @endif
                            @if ($r->laRecibe(auth()->user()) && ! $r->companions->contains('id', auth()->id()))
                                <br><span class="pill">Recibir · {{ \App\Services\Booking\EspacioBookingService::MINUTOS_RECIBIR }} min</span>
                            @endif
                        </td>
                        <td>
                            {{ $r->user?->name ?? '—' }}
                            @if ($r->purpose)
                                <br><span class="help" style="margin:0;font-size:.82rem">«{{ $r->purpose }}»</span>
                            @endif
                        </td>
                        <td>
                            {{ $r->starts_at->timezone($tz)->format('d/m/Y H:i') }}
                            — {{ $r->ends_at->timezone($tz)->format('H:i') }}
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            @if ($r->status === 'en_curso')
                                <span class="pill ok">En curso</span>
                            @else
                                @include('cuenta._pasar', ['reserva' => $r, 'candidatos' => $candidatos->get($r->id)])
                            @endif
                            <br><a href="{{ route('calendario.reserva', $r) }}">Añadir a mi calendario</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ------------------------------------------- tiempo de proyecto --}}
    @if ($bloques->isNotEmpty())
        <h2>Tiempo apartado para proyectos</h2>

        <div class="panel">
            <p class="help" style="margin-top:0">
                En estas horas no se te asignan asesorías ni acompañamientos. Se aparta desde la
                tarea, en el proyecto.
            </p>

            <table>
                <thead><tr><th>Proyecto</th><th>Tarea</th><th>Cuándo</th></tr></thead>
                <tbody>
                @foreach ($bloques as $b)
                    <tr>
                        <td>{{ $b->project?->code ?? '—' }}</td>
                        <td>{{ $b->task?->title ?? '—' }}</td>
                        <td>
                            {{ $b->starts_at->timezone($tz ?? config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                            — {{ $b->ends_at->timezone($tz ?? config('fabos.lab.timezone'))->format('H:i') }}
                        </td>
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
                <thead><tr><th>Qué</th><th>Cuándo</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                @foreach ($reservas as $r)
                    @php $esEspacio = $r->reservable_type === \App\Models\Space::class; @endphp
                    <tr>
                        <td>
                            {{ $r->reservable?->name ?? '—' }}
                            <br><span class="help" style="margin:0;font-size:.82rem">
                                {{ $esEspacio ? ($r->esRecorrido() ? 'Recorrido' : 'Espacio') : 'Equipo' }}
                                @if ($esEspacio && $r->participants > 1) · {{ $r->participants }} personas @endif
                            </span>
                            @if ($esEspacio && $r->supervisor)
                                <br><span class="help" style="margin:0;font-size:.82rem">Te recibe {{ $r->supervisor->name }}</span>
                            @endif
                        </td>
                        <td>
                            {{ $r->starts_at->timezone($tz)->format('d/m/Y H:i') }}
                            — {{ $r->ends_at->timezone($tz)->format('H:i') }}
                        </td>
                        <td>
                            <span class="pill {{ in_array($r->status, ['confirmada', 'en_curso'], true) ? 'ok' : 'warn' }}">
                                {{ \App\Models\Reservation::ESTADOS[$r->status] ?? $r->status }}
                            </span>
                            @if ($r->status === 'solicitada')
                                <br><span class="help" style="margin:0;font-size:.82rem">Esperando decisión de la coordinación</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            {{-- Validar la llegada desde aquí: hasta ahora había
                                 que salir a buscar la cámara del teléfono. --}}
                            @if (in_array($r->status, ['confirmada', 'en_curso'], true))
                                <a href="{{ route('escaneo.camara') }}"><strong>Validar mi llegada</strong></a>
                                ·
                            @endif
                            <a href="{{ route('calendario.reserva', $r) }}">Añadir a mi calendario</a>
                            {{-- Cancelar desde aquí: sin esto, quien pedía una
                                 sala y quería cambiarla volvía a pedirla. --}}
                            @if (in_array($r->status, ['solicitada', 'confirmada'], true) && $r->starts_at->isFuture())
                                <form method="POST" action="{{ route('reservas.cancel', $r) }}" style="display:inline"
                                      onsubmit="return confirm('¿Cancelar? Esa hora queda libre para alguien más.')">
                                    @csrf
                                    <button type="submit" class="secundario" style="margin:0 0 0 .4rem;padding:.15rem .5rem;font-size:.78rem">Cancelar</button>
                                </form>
                                {{-- Cuántas personas, sin cancelar y volver a
                                     pedir: reservar para diez y ser dos es lo
                                     normal. --}}
                                @if ($esEspacio && ! $r->esRecorrido())
                                    <br>
                                    <details class="plegable" style="margin-top:.3rem">
                                        <summary style="font-size:.85rem">Cambiar personas</summary>
                                        <form method="POST" action="{{ route('reservas.personas', $r) }}">
                                            @csrf
                                            <label for="personas-{{ $r->id }}">Cuántas van</label>
                                            <input id="personas-{{ $r->id }}" name="participantes" type="number" min="1" max="500" required value="{{ $r->participants }}">
                                            <button type="submit">Guardar</button>
                                        </form>
                                    </details>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ------------------------------------------------ mi calendario --}}
    <h2>Mi calendario</h2>
    <div class="panel">
        <p class="help" style="margin-top:0">
            El enlace de cada reserva se descarga y se guarda: es una <strong>foto</strong>. Si
            luego cambia la hora, esa copia no se entera.
        </p>

        @if (auth()->user()->calendar_token)
            <p style="margin:.8rem 0 .3rem"><strong>Tu calendario, siempre al día</strong></p>
            <p class="help" style="margin-top:0">
                Pega esta dirección en Outlook —<em>Agregar calendario → Suscribirse desde
                web</em>— y tus reservas y asesorías aparecen solas y se actualizan.
            </p>
            <input type="text" readonly onclick="this.select()"
                   value="{{ route('calendario.suscripcion', auth()->user()->calendar_token) }}"
                   style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:.8rem">
            <p class="help">
                Es <strong>secreta</strong>: quien la tenga ve tu agenda del laboratorio. No la
                publiques.
            </p>
            <form method="POST" action="{{ route('calendario.suscribirme') }}">
                @csrf
                <button type="submit" class="secundario">Cambiar la dirección</button>
            </form>
            <p class="help">
                Cambiarla deja de servir la anterior, por si la compartiste sin querer.
            </p>
        @else
            <p class="help">
                También puedes suscribir tu Outlook y que aparezcan solas, sin descargar nada
                cada vez.
            </p>
            <form method="POST" action="{{ route('calendario.suscribirme') }}">
                @csrf
                <button type="submit">Crear mi dirección de calendario</button>
            </form>
        @endif
    </div>

    {{-- ------------------------------------ mi agenda de fuera --}}
    <div class="panel">
        <p style="margin:0 0 .3rem"><strong>Tu calendario de la Universidad</strong></p>
        <p class="help" style="margin-top:0">
            Si pegas aquí tu calendario publicado de Outlook, fabOS mira si ya tienes algo a
            esa hora y <strong>deja de ofrecer esa franja</strong>: ni asesorías, ni
            acompañamientos, ni traspasos de otra persona. Es de solo lectura: no escribe nada
            en tu calendario, ni podría.
        </p>

        @if (auth()->user()->external_calendar_url)
            {{-- Decir si funciona. Una dirección mal copiada y una buena se ven
                 igual hasta que alguien nota, semanas después, que sus horas
                 ocupadas se seguían ofreciendo. --}}
            @if ($agenda['ok'])
                <p class="msg" style="margin:.8rem 0">
                    <strong>Se está leyendo.</strong>
                    {{ $agenda['cuantos'] . ' ' . ($agenda['cuantos'] === 1 ? 'compromiso' : 'compromisos') }}
                    en las próximas semanas; esas horas no se ofrecen para asesorías.
                    @if ($agenda['leido'])
                        <span class="help">
                            Última lectura {{ $agenda['leido']->timezone($tz)->format('d/m H:i') }}.
                        </span>
                    @endif
                </p>
            @else
                <p class="msg error" style="margin:.8rem 0">
                    <strong>No se pudo leer.</strong> Comprueba que sea el enlace <em>ICS</em> de
                    un calendario <strong>publicado</strong> —no el de compartir con alguien— y
                    que siga publicado en Outlook.
                </p>
            @endif
        @endif

        <form method="POST" action="{{ route('calendario.agenda') }}">
            @csrf
            <label for="agenda">Dirección del calendario publicado</label>
            <input type="url" id="agenda" name="url" maxlength="2000"
                   value="{{ auth()->user()->external_calendar_url }}"
                   placeholder="https://outlook.office365.com/owa/calendar/…/reachcalendar.ics">
            <p class="help">
                En Outlook web: <em>Configuración → Calendario → Calendarios compartidos →
                Publicar calendario</em>. Copia el enlace <strong>ICS</strong>. Déjalo vacío
                para quitarlo.
            </p>
            <button type="submit">Guardar mi calendario</button>
        </form>

        @if (auth()->user()->external_calendar_url)
            {{-- Volver a leerlo ahora: se guarda media hora, y quien acaba de
                 arreglar algo en Outlook no quiere esperar media hora para
                 saber si ya sirve. --}}
            <form method="POST" action="{{ route('calendario.comprobar') }}" style="margin-top:.6rem">
                @csrf
                <button type="submit" class="secundario">Comprobar ahora</button>
            </form>
        @endif

        <p class="help">
            Dos cosas que conviene saber, y no son fallos: Outlook regenera ese enlace
            <strong>cada pocas horas</strong>, así que una reunión de esta mañana puede tardar
            en aparecer; y según cómo lo publiques, los eventos llegan sin título —da igual,
            aquí solo hace falta saber cuándo—.
        </p>
    </div>

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
