@extends('layouts.app')
@section('title', 'Propuesta ' . $proyecto->code . ' · ' . config('fabos.lab.name'))

@php
    use App\Models\Project;

    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    $valor = $proyecto->valorDeReferencia();
@endphp

@section('content')
    <p class="rotulo">{{ config('fabos.lab.name') }} · Propuesta {{ $proyecto->code }}</p>

    <h1 style="margin-top:.4rem">{{ $proyecto->name }}</h1>

    <p class="help">
        Esto es lo que proponemos para tu solicitud del
        {{ $proyecto->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}.
        @if ($proyecto->lead)
            Lo lleva {{ $proyecto->lead->name }}.
        @endif
    </p>

    @if ($proyecto->summary)
        <div class="panel">
            <h2 style="margin-top:0">Lo que nos contaste</h2>
            <div>{!! nl2br(e($proyecto->summary)) !!}</div>
        </div>
    @endif

    <div class="panel">
        <h2 style="margin-top:0">Qué entregaríamos</h2>

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
            Responde este correo o escríbenos si algo no encaja: el alcance, la fecha o
            el valor. Cuando estemos de acuerdo lo dejamos por escrito y arrancamos.
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
