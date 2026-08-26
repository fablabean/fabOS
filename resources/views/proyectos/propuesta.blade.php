@extends('layouts.app')
@section('title', $proyecto->code . ' · ' . $proyecto->name . ' · ' . config('fabos.lab.name'))

@php
    use App\Models\Project;

    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    $valor = $proyecto->valorDeReferencia();
@endphp

@section('content')
@php
    $respondida = $proyecto->proposal_sent_at !== null;
@endphp

    <p class="rotulo">
        {{ config('fabos.lab.name') }} ·
        {{ $respondida ? 'Propuesta' : 'Solicitud' }} {{ $proyecto->code }}
    </p>

    <h1 style="margin-top:.4rem">{{ $proyecto->name }}</h1>

    @if ($respondida)
        <p class="help">
            Esto es lo que proponemos para tu solicitud del
            {{ $proyecto->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}.
            @if ($proyecto->lead)
                Lo lleva {{ $proyecto->lead->name }}.
            @endif
        </p>
    @else
        {{-- Antes de responder, la página sigue sirviendo: quien pidió tiene
             derecho a ver lo que mandó y en qué va, sin tener que preguntar. --}}
        <p class="help">
            Recibimos tu solicitud el
            {{ $proyecto->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
            y está <strong>en revisión</strong>: alguien del laboratorio está mirando si
            cabe, con qué máquinas y cuánto tomaría. Cuando tengamos una propuesta te
            llega por correo y aparece aquí mismo.
            @if ($proyecto->lead)
                La lleva {{ $proyecto->lead->name }}.
            @endif
        </p>
    @endif

    @if ($proyecto->summary)
        <div class="panel">
            <h2 style="margin-top:0">Lo que nos contaste</h2>
            <div>{!! nl2br(e($proyecto->summary)) !!}</div>
        </div>
    @endif

    <div class="panel">
        <h2 style="margin-top:0">{{ $respondida ? 'Qué entregaríamos' : 'Qué pediste' }}</h2>

        @if ($proyecto->deliverables->isEmpty())
            <p class="help" style="margin:0">
                Todavía no hay una lista cerrada de entregables. Es lo primero que
                acordamos juntos.
            </p>
        @else
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($proyecto->deliverables as $entregable)
                    <li style="margin:.45rem 0">
                        {{ $entregable->title }}
                        @if ($entregable->detail)
                            <div class="quien">{{ $entregable->detail }}</div>
                        @endif
                        @if ($entregable->due_on)
                            <div class="quien">para el {{ $entregable->due_on->format('d/m/Y') }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($respondida || $proyecto->due_on)
    <div class="panel">
        <h2 style="margin-top:0">Tiempos y valor</h2>

        <table>
            <tbody>
                @if ($proyecto->starts_on)
                    <tr><th style="font-weight:500">Arranca</th>
                        <td>{{ $proyecto->starts_on->format('d/m/Y') }}</td></tr>
                @endif

                @if ($proyecto->due_on)
                    <tr><th style="font-weight:500">Se entrega</th>
                        <td>{{ $proyecto->due_on->format('d/m/Y') }}</td></tr>
                @endif

                <tr>
                    <th>{{ $proyecto->is_internal ? 'Valor del trabajo' : 'Valor' }}</th>
                    <td style="font-weight:700">
                        @if ($valor > 0)
                            {{ $pesos($valor) }}
                        @else
                            <span class="quien">por definir</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>

        @if ($proyecto->is_internal)
            <p class="foot" style="margin-top:.8rem">
                Es un compromiso interno de la institución: el trabajo se valora igual,
                pero no se factura.
            </p>
        @elseif ($valor > 0 && ! $proyecto->agreed_value)
            <p class="foot" style="margin-top:.8rem">
                Es una estimación, no una factura. Se cierra cuando acordemos el alcance.
            </p>
        @endif
    </div>
    @endif

    {{-- Lo que adjuntó al pedirlo. Verlo aquí evita el «¿les llegó la foto?». --}}
    @if ($proyecto->evidence->isNotEmpty())
        <div class="panel">
            <h2 style="margin-top:0">Lo que adjuntaste</h2>
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($proyecto->evidence as $soporte)
                    <li style="margin:.4rem 0">
                        @auth
                            <a href="{{ $soporte->enlace() }}" target="_blank" rel="noopener">
                                {{ $soporte->comoSeLlama() }}
                            </a>
                        @else
                            {{ $soporte->comoSeLlama() }}
                        @endauth
                    </li>
                @endforeach
            </ul>

            @guest
                <p class="foot" style="margin-top:.8rem">
                    Para abrirlos, <a href="{{ route('login') }}">entra a tu cuenta</a>:
                    no los dejamos en una dirección que cualquiera pueda pedir.
                </p>
            @endguest
        </div>
    @endif

    @php
        $propuesta = $proyecto->documents->firstWhere('kind', 'propuesta');
    @endphp

    @if ($propuesta && $propuesta->url)
        <div class="panel">
            <h2 style="margin-top:0">El documento</h2>
            <p style="margin:0">
                <a href="{{ $propuesta->url }}" target="_blank" rel="noopener">{{ $propuesta->title }}</a>
            </p>
        </div>
    @endif

    @if (session('aceptada'))
        <div class="msg ok">
            <strong>Recibimos tu aceptación.</strong>
            Te mandamos un correo con lo que sigue.
        </div>
    @endif

    @error('aceptar') <p class="msg error">{{ $message }}</p> @enderror

    {{-- Aceptar desde la misma página donde se lee. Obligar a responder el
         correo para decir que sí dejaría la aceptación fuera del sistema, que
         es donde no sirve de nada. --}}
    @if ($respondida && ! $proyecto->estaAceptado() && $puedeAceptar)
        <div class="panel aceptar">
            <h2 style="margin-top:0">¿Seguimos?</h2>
            <p class="help" style="margin-top:0">
                Si esto es lo que necesitas, acéptalo y arrancamos. Si algo no encaja
                —el alcance, la fecha o el valor—, escríbenos antes: se ajusta.
            </p>

            <form method="POST" action="{{ $firmado ? $urlAceptar : route('proyectos.aceptar', $proyecto) }}">
                @csrf
                <textarea name="nota" rows="2"
                          placeholder="Algo que quieras dejar dicho al aceptar. Opcional."></textarea>
                <button type="submit">Acepto la propuesta</button>
            </form>
        </div>
    @elseif ($proyecto->estaAceptado())
        <div class="panel" style="border-left:4px solid var(--ok)">
            <h2 style="margin-top:0">Propuesta aceptada</h2>
            <p style="margin:0">
                El {{ $proyecto->accepted_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}.
                @if ($proyecto->acceptance_note)
                    «{{ $proyecto->acceptance_note }}»
                @endif
            </p>
        </div>
    @endif

    {{-- El circuito de la venta interna. Solo a quien le toca: a un estudiante
         o a una empresa de fuera le sobra, y le haría pensar que su encargo
         también depende de Planeación. --}}
    @if ($proyecto->esClienteInterno())
        <div class="panel flujo">
            <h2 style="margin-top:0">Cómo se paga</h2>
            <p class="help" style="margin-top:0">
                No hay factura: hay un traslado de presupuesto entre áreas.
                @if ($proyecto->estaAceptado())
                    <strong>Es el paso que falta:</strong> nada se fabrica hasta que
                    Planeación confirme.
                @endif
            </p>

            <ol class="pasos">
                <li>
                    <span class="quien">Quien compra</span>
                    <strong>Formulario de pedido</strong>
                    <span class="detalle">Lo llena el área solicitante, con la cotización adjunta.</span>
                </li>
                <li>
                    <span class="quien">Quien compra</span>
                    <strong>Líder emisor</strong>
                    <span class="detalle">Da su visto bueno el líder del área que pone los recursos.</span>
                </li>
                <li>
                    <span class="quien">Quien vende</span>
                    <strong>Líder receptor</strong>
                    <span class="detalle">Avala y dice en qué cuentas presupuestales entran.</span>
                </li>
                <li>
                    <span class="quien">Planeación</span>
                    <strong>Traslado</strong>
                    <span class="detalle">Se hace la transacción presupuestal del cupo.</span>
                </li>
                <li class="fin">
                    <strong>Fabricación</strong>
                    <span class="detalle">Arranca con la confirmación de Planeación.</span>
                </li>
            </ol>

            @if ($proyecto->estaAceptado() && filled(config('fabos.proyectos.formulario_venta_interna')))
                <p style="margin:1rem 0 0">
                    <a class="btn" href="{{ config('fabos.proyectos.formulario_venta_interna') }}"
                       target="_blank" rel="noopener">Abrir el formulario de pedido →</a>
                </p>
                <p class="foot" style="margin-top:.6rem">
                    Cuanto antes se diligencie, antes empieza la fabricación.
                </p>
            @endif
        </div>
    @endif

    <div class="panel">
        <h2 style="margin-top:0">Qué sigue</h2>
        <p style="margin:0">
            @if ($respondida)
                Respóndenos si algo no encaja: el alcance, la fecha o el valor. Cuando
                estemos de acuerdo lo dejamos por escrito y arrancamos.
            @else
                Nada por tu parte, de momento. Si se te ocurre algo más o cambia lo que
                necesitas, escríbenos y lo sumamos antes de cotizarlo.
            @endif
        </p>

        @if ($firmado)
            <p class="foot" style="margin-top:.9rem">
                Llegaste por el enlace del correo. También puedes
                <a href="{{ route('login') }}">entrar a tu cuenta</a> con
                {{ $proyecto->contact_email }} y seguir el proyecto desde ahí, sin
                depender de este enlace.
            </p>
        @endif
    </div>

    <p class="foot">
        {{ config('fabos.lab.name') }} · {{ config('fabos.lab.institution') }}
    </p>

    {{-- Rejilla propia: las utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        .aceptar textarea { width:100%; margin-bottom:.7rem; }
        .flujo .pasos { list-style:none; margin:0; padding:0;
                        display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:.6rem; }
        .flujo .pasos li { border:1px solid var(--rule); border-left:3px solid var(--accent);
                           border-radius:5px; padding:.6rem .7rem; background:var(--ground); }
        .flujo .pasos li.fin { border-left-color:var(--ok); }
        .flujo .pasos .quien { display:block; font-size:.65rem; letter-spacing:.1em;
                               text-transform:uppercase; color:var(--muted);
                               font-family:ui-monospace,Consolas,monospace; }
        .flujo .pasos strong { display:block; font-size:.9rem; margin:.15rem 0; }
        .flujo .pasos .detalle { font-size:.8rem; color:var(--ink-soft); }
    </style>
@endsection
