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
@endsection
