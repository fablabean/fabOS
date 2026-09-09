@php
    /** @var \App\Models\Project $proyecto */
    /** @var array<string,string> $variables */
    $paraPdf = $paraPdf ?? false;
    $v = $variables;

    // Cada párrafo de las cláusulas, con el número en negrita si lo trae.
    $parrafos = collect(preg_split('/\R\s*\R/', trim($clausulas)))->filter();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acuerdo de servicio · {{ $proyecto->code }} · {{ $v['laboratorio'] }}</title>
    <style>
        /* Pensada para el PDF: tablas y no flex, letra con acentos, márgenes
           de hoja. En pantalla es la misma página, un poco más holgada. */
        *{box-sizing:border-box}
        @if ($paraPdf)
            @page{margin:1.6cm 1.8cm}
        @endif
        body{
            @if ($paraPdf)
                font-family:DejaVu Sans,sans-serif;font-size:10.5px;padding:0;
            @else
                font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14px;padding:2.5rem;
            @endif
            line-height:1.55;color:#111;margin:0;max-width:860px;background:#fff;
        }
        table{width:100%;border-collapse:collapse}
        .cabecera{border-bottom:2px solid #111;margin-bottom:1.4rem}
        .cabecera td{padding:0 0 .9rem;vertical-align:top}
        h1{font-size:1.35rem;margin:0}
        .meta{font-size:.85rem;color:#666;margin-top:.2rem}
        .derecha{text-align:right}
        .codigo{font-size:1.4rem;font-weight:700}
        h2{font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:#666;margin:1.4rem 0 .5rem}
        .partes td{padding:.25rem 0;vertical-align:top;font-size:.95em}
        .partes td.k{color:#666;width:11rem;padding-right:1rem;white-space:nowrap}
        .clausula{margin:0 0 .8rem}
        .firmas{margin-top:2.8rem}
        .firmas td{width:50%;padding:0 1.5rem 0 0;vertical-align:bottom}
        .linea{border-top:1px solid #111;padding-top:.4rem;margin-top:3rem;font-size:.9em}
        .linea small{display:block;color:#666}
        .pie{margin-top:2rem;font-size:.8em;color:#666;border-top:1px solid #ddd;padding-top:.6rem}
        .aviso{background:#fff7e0;border:1px solid #f0d58c;padding:.6rem .9rem;border-radius:.4rem;margin-bottom:1.2rem;font-size:.9em}
    </style>
</head>
<body>

@unless ($paraPdf)
    <div class="aviso">
        Vista previa. Lo que ves es lo que saldrá en el PDF; si algo no cuadra, ciérrala y corrígelo en el formulario.
    </div>
@endunless

<table class="cabecera">
    <tr>
        <td>
            <h1>Acuerdo de servicio</h1>
            <div class="meta">{{ $v['laboratorio'] }} · {{ $v['institucion'] }} · {{ $v['ciudad'] }}</div>
        </td>
        <td class="derecha">
            <div class="codigo">{{ $v['codigo'] }}</div>
            <div class="meta">{{ $v['fecha'] }}</div>
        </td>
    </tr>
</table>

<h2>Las partes</h2>
<table class="partes">
    <tr><td class="k">El laboratorio</td><td>{{ $v['laboratorio'] }}, de {{ $v['institucion'] }}. Responde por el proyecto: {{ $v['responsable'] }}.</td></tr>
    <tr><td class="k">El cliente</td><td>{{ $v['cliente'] }}</td></tr>
    <tr><td class="k">El proyecto</td><td>«{{ $v['proyecto'] }}» ({{ $v['codigo'] }})</td></tr>
    <tr><td class="k">Valor</td><td>{{ $v['valor'] }}</td></tr>
    <tr><td class="k">Plazo</td><td>Del {{ $v['inicio'] }} al {{ $v['entrega'] }}</td></tr>
</table>

<h2>Lo acordado</h2>
@foreach ($parrafos as $parrafo)
    @php
        // «3. Plazo. El trabajo…» → el número y el título en negrita.
        $conTitulo = preg_match('/^(\d+\.\s*[^.]{1,60}\.)\s*(.*)$/su', trim($parrafo), $m);
    @endphp
    <p class="clausula">
        @if ($conTitulo)
            <strong>{{ $m[1] }}</strong> {!! nl2br(e($m[2])) !!}
        @else
            {!! nl2br(e(trim($parrafo))) !!}
        @endif
    </p>
@endforeach

<table class="firmas">
    <tr>
        <td>
            <div class="linea">
                Por el laboratorio
                <small>{{ $v['responsable'] }} · {{ $v['laboratorio'] }}</small>
            </div>
        </td>
        <td>
            <div class="linea">
                Por el cliente
                <small>{{ $v['cliente'] }}</small>
            </div>
        </td>
    </tr>
</table>

<div class="pie">
    {{ $v['laboratorio'] }} · {{ $v['institucion'] }} · {{ $v['ciudad'] }}. Documento generado por fabOS el {{ $v['fecha'] }} para el proyecto {{ $v['codigo'] }}.
</div>

</body>
</html>
