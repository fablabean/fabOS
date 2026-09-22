@php
    /** @var \App\Models\Project $proyecto */
    $lab = config('fabos.lab.name');
    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    $valor = $proyecto->valorDeReferencia();
    $version = $proyecto->propuestaVigente();
    $tz = config('fabos.lab.timezone');
    $fecha = fn ($d) => $d?->timezone($tz)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Propuesta {{ $proyecto->code }} · {{ $lab }}</title>
    <style>
        /* Pensada para el papel, como el acuerdo de servicio: tablas y no
           flex, letra con acentos, márgenes de hoja. */
        *{box-sizing:border-box}
        @page{margin:1.6cm 1.8cm}
        body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;line-height:1.55;
             color:#111;margin:0;padding:0;background:#fff}
        table{width:100%;border-collapse:collapse}
        .cabecera{border-bottom:2px solid #111;margin-bottom:1.4rem}
        .cabecera td{padding:0 0 .9rem;vertical-align:top}
        /* Pequeño y encogido a su contenido: encabeza, no ocupa. */
        .cabecera td.marca{width:1px;padding-right:.9rem;vertical-align:middle}
        .cabecera td.marca img{height:1.6cm;max-width:4cm}
        h1{font-size:1.35rem;margin:0}
        .meta{font-size:.85rem;color:#666;margin-top:.2rem}
        .derecha{text-align:right}
        .codigo{font-size:1.4rem;font-weight:700}
        h2{font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:#666;
           margin:1.4rem 0 .5rem}
        .datos td{padding:.25rem 0;vertical-align:top}
        .datos td.k{color:#666;width:11rem;padding-right:1rem;white-space:nowrap}
        .entregable{margin:0 0 .6rem;padding-left:.9rem}
        .entregable b{font-weight:600}
        .entregable small{display:block;color:#666}
        .portada{margin:0 0 1.2rem}
        .portada img{width:100%;max-height:7.5cm;object-fit:cover}
        .nota{color:#666;margin:.5rem 0 0}
        .pie{margin-top:2rem;font-size:.8em;color:#666;border-top:1px solid #ddd;padding-top:.6rem}
        .total{font-size:1.1rem;font-weight:700}
    </style>
</head>
<body>

<table class="cabecera">
    <tr>
        {{-- El logo incrustado: un PDF se guarda, se reenvía y se abre sin
             sesión y sin red, y uno enlazado saldría roto justo en el
             documento que va a leer quien decide. --}}
        @if ($logo)
            <td class="marca"><img src="{{ $logo }}" alt="{{ $lab }}"></td>
        @endif
        <td>
            <h1>{{ $proyecto->name }}</h1>
            <div class="meta">
                {{ $lab }}@if (config('fabos.lab.institution')) · {{ config('fabos.lab.institution') }}@endif
            </div>
        </td>
        <td class="derecha">
            <div class="codigo">{{ $proyecto->code }}</div>
            <div class="meta">
                {{ $respondida ? 'Propuesta' : 'Solicitud' }}
                @if ($version && $version->version > 1) · versión {{ $version->version }} @endif
            </div>
        </td>
    </tr>
</table>

{{-- La imagen va incrustada y no por su dirección: el PDF se guarda, se
     reenvía y se abre sin sesión, y una imagen enlazada saldría rota. --}}
@if ($portada)
    <div class="portada"><img src="{{ $portada }}" alt=""></div>
@endif

<h2>De qué se trata</h2>
<table class="datos">
    <tr><td class="k">Para</td><td>{{ $proyecto->quienFirma() ?: $proyecto->quienPide() }}</td></tr>
    @if ($proyecto->lead)
        <tr><td class="k">Lo lleva</td><td>{{ $proyecto->lead->name }}</td></tr>
    @endif
    <tr><td class="k">Solicitud recibida</td><td>{{ $fecha($proyecto->created_at) }}</td></tr>
    @if ($respondida && $version?->sent_at)
        <tr><td class="k">Propuesta enviada</td><td>{{ $fecha($version->sent_at) }}</td></tr>
    @endif
    @if ($proyecto->accepted_at)
        <tr><td class="k">Aceptada</td><td>{{ $fecha($proyecto->accepted_at) }}</td></tr>
    @endif
</table>

@if ($proyecto->summary)
    <h2>Lo que nos contaste</h2>
    <div>{!! nl2br(e($proyecto->summary)) !!}</div>
@endif

<h2>{{ $respondida ? 'Qué entregaríamos' : 'Qué pediste' }}</h2>
@if ($proyecto->deliverables->isEmpty())
    <p class="nota" style="margin-top:0">
        Todavía no hay una lista cerrada de entregables. Es lo primero que acordamos juntos.
    </p>
@else
    @foreach ($proyecto->deliverables as $entregable)
        <p class="entregable">
            <b>· {{ $entregable->title }}</b>
            @if ($entregable->detail)<small>{{ $entregable->detail }}</small>@endif
            @if ($entregable->due_on)<small>para el {{ $entregable->due_on->format('d/m/Y') }}</small>@endif
        </p>
    @endforeach
@endif

@if ($respondida || $proyecto->due_on || $valor > 0)
    <h2>Tiempos y valor</h2>
    <table class="datos">
        @if ($proyecto->starts_on)
            <tr><td class="k">Arranca</td><td>{{ $fecha($proyecto->starts_on) }}</td></tr>
        @endif
        @if ($proyecto->due_on)
            <tr><td class="k">Se entrega</td><td>{{ $fecha($proyecto->due_on) }}</td></tr>
        @endif
        <tr>
            <td class="k">{{ $proyecto->is_internal ? 'Valor del trabajo' : 'Valor' }}</td>
            <td class="total">{{ $valor > 0 ? $pesos($valor) : 'por definir' }}</td>
        </tr>
    </table>

    @if ($proyecto->is_internal)
        <p class="nota">
            Es un compromiso interno de la institución: el trabajo se valora igual,
            pero no se factura.
        </p>
    @elseif ($valor > 0 && ! $proyecto->agreed_value)
        <p class="nota">
            Es una estimación, no una factura. Se cierra cuando acordemos el alcance.
        </p>
    @endif
@endif

@if ($soportes->isNotEmpty())
    <h2>Lo que adjuntaste</h2>
    @foreach ($soportes as $archivo)
        <p class="entregable"><b>· {{ $archivo->comoSeLlama() }}</b></p>
    @endforeach
@endif

<div class="pie">
    {{-- Un PDF se guarda y se reenvía: tiene que decir cuándo se imprimió y
         dónde está la versión que manda, o dentro de un mes nadie sabrá si lo
         que tiene en la mano sigue vigente. --}}
    Impreso el {{ $fecha(now()) }}. La versión vigente de esta propuesta, con lo que
    se haya conversado después, está siempre en {{ $enlace }}
    @if ($proyecto->lead && $proyecto->lead->email)
        · {{ $proyecto->lead->email }}
    @endif
</div>

</body>
</html>
