@php
    $lab = config('fabos.lab.name');
    $institucion = config('fabos.lab.institution');
    $ciudad = config('fabos.lab.city');
    $acento = '#0D6E63';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Perfiles · {{ $lab }}</title>
    <style>
        /* Para DomPDF: tablas y no flex ni grid, letra con acentos y eñes. */
        @page{margin:1.7cm 1.9cm 2cm}
        *{box-sizing:border-box}
        body{font-family:DejaVu Sans,sans-serif;font-size:10px;line-height:1.55;color:#1d1f1b;margin:0}
        table{width:100%;border-collapse:collapse}

        /* ---------- membrete ---------- */
        .membrete td{vertical-align:middle;padding:0 0 .55cm}
        .membrete td.marca{width:1px;padding-right:.6cm}
        .membrete td.marca img{height:1.45cm;max-width:5.2cm}
        .membrete .lab{font-size:12px;font-weight:bold;letter-spacing:.02em}
        .membrete .inst{font-size:8.5px;color:#6e7066;letter-spacing:.12em;text-transform:uppercase;margin-top:.08cm}
        .membrete td.fecha{text-align:right;font-size:8.5px;color:#6e7066;white-space:nowrap}
        .filete{height:2px;background:{{ $acento }};margin:0 0 .12cm}
        .filete-fino{height:.5px;background:#c7c7bd;margin:0 0 .9cm}

        /* ---------- presentación ---------- */
        .rotulo{font-size:8px;letter-spacing:.22em;text-transform:uppercase;color:{{ $acento }};font-weight:bold}
        h1{font-size:21px;font-weight:bold;letter-spacing:-.01em;margin:.12cm 0 .3cm;color:#111}
        .para{font-size:10px;color:#3d4038;margin:0 0 .35cm}
        .razon{font-size:10.5px;color:#2b2d28;margin:0 0 .75cm;padding:.35cm .45cm;
               background:#f3f5f1;border-left:2px solid {{ $acento }}}
        .cuantos{font-size:8.5px;color:#6e7066;letter-spacing:.08em;text-transform:uppercase;margin:0 0 .3cm}

        /* ---------- perfiles ---------- */
        .perfil{page-break-inside:avoid;margin:0 0 .28cm}
        .perfil td{vertical-align:top;padding:.34cm .4cm;border-bottom:.5px solid #dedfd8}
        .perfil td.n{width:.9cm;padding-right:0;color:{{ $acento }};font-size:9px;font-weight:bold;letter-spacing:.05em}
        .nombre{font-size:12.5px;font-weight:bold;color:#111}
        .oficio{font-size:9.5px;color:{{ $acento }};margin-top:.04cm}
        .contacto{text-align:right;width:6.6cm;font-size:9.5px;line-height:1.7}
        .contacto .et{font-size:7.5px;letter-spacing:.14em;text-transform:uppercase;color:#8a8c82}
        .contacto a{color:#1d1f1b;text-decoration:none}

        /* ---------- pie ---------- */
        .pie{position:fixed;bottom:-1.25cm;left:0;right:0;font-size:7.5px;color:#8a8c82;
             border-top:.5px solid #c7c7bd;padding-top:.18cm}
        .pie td.d{text-align:right}
    </style>
</head>
<body>

<div class="pie">
    <table><tr>
        <td>{{ $lab }}@if ($institucion) · {{ $institucion }}@endif @if ($ciudad) · {{ $ciudad }}@endif</td>
        <td class="d">Datos de contacto compartidos para el fin indicado. No los reenvíes ni los uses para otra cosa.</td>
    </tr></table>
</div>

<table class="membrete">
    <tr>
        @if ($logo)
            <td class="marca"><img src="{{ $logo }}" alt="{{ $lab }}"></td>
        @else
            <td>
                <div class="lab">{{ $lab }}</div>
                @if ($institucion)<div class="inst">{{ $institucion }}</div>@endif
            </td>
        @endif
        <td class="fecha">{{ $ciudad ? $ciudad . ', ' : '' }}{{ $fecha }}</td>
    </tr>
</table>
<div class="filete"></div>
<div class="filete-fino"></div>

<div class="rotulo">Perfiles recomendados</div>
<h1>Personas para contactar</h1>

@if ($para)
    <p class="para"><strong>Para:</strong> {{ $para }}</p>
@endif

<div class="razon">{!! nl2br(e($razon)) !!}</div>

<p class="cuantos">{{ $perfiles->count() === 1 ? 'Un perfil' : $perfiles->count() . ' perfiles' }}</p>

@foreach ($perfiles as $i => $p)
    <table class="perfil">
        <tr>
            <td class="n">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
            <td>
                <div class="nombre">{{ $p->name }}</div>
                @if (filled($p->specialty))
                    <div class="oficio">{{ $p->specialty }}</div>
                @endif
            </td>
            <td class="contacto">
                @if (filled($p->email))
                    <span class="et">Correo</span><br>
                    <a href="mailto:{{ $p->email }}">{{ $p->email }}</a><br>
                @endif
                @if (filled($p->phone))
                    <span class="et">Celular</span><br>
                    {{ $p->phone }}
                @endif
            </td>
        </tr>
    </table>
@endforeach

</body>
</html>
