{{--
    Informe de Diagnóstico de Revisión Rutinaria (INV-118 §6.2-6.3).
    El mismo texto y orden del informe que el laboratorio llena en Word:
    los datos del equipo, la descripción, el resultado resaltado y las tres
    opciones del diagnóstico, con las observaciones en la que corresponde.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Diagnóstico de Revisión Rutinaria</title>
    <style>
        @page { margin: 2.5cm 2.6cm 2.2cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; line-height: 1.25; }
        h1 { text-align: center; font-size: 15pt; margin: 0 0 1.6cm; }
        .dato { margin: 0 0 .45cm; }
        .dato b { font-weight: bold; }
        .valor { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; }
        .sangria { margin-left: 1.05cm; }
        .bloque { margin-top: 1.1cm; }
        p { margin: 0 0 .4cm; }
        .resultado { font-size: 16pt; font-weight: bold; color: #0563C1; text-decoration: underline; margin: .3cm 0 1.6cm; }
        ol { margin: 0; padding-left: 1.05cm; }
        li { margin-bottom: .2cm; }
        .o { margin: .35cm 0 .35cm; }
        .obs { font-style: italic; margin-top: .3cm; }
        .obs span { font-style: normal; }
        .pie { position: fixed; bottom: -1.2cm; left: 0; right: 0; font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #777; text-align: right; }
    </style>
</head>
<body>
    <div class="pie">fabOS · orden de trabajo #{{ $orden->id }} · INV-118</div>

    <h1>Informe de Diagnóstico de Revisión Rutinaria</h1>

    <div class="dato"><b>Fecha de la Revisión:</b> <span class="valor">{{ $fecha->format('d/m/Y') }}</span></div>
    <div class="dato"><b>Número de Activo:</b> <span class="valor">{{ $activo?->asset_tag ?: '—' }}</span></div>
    <div class="sangria">
        <div class="dato"><b>Área:</b> <span class="valor">{{ $activo?->area?->name ?? '—' }}</span></div>
        <div class="dato"><b>Nombre del Equipo:</b> <span class="valor">{{ $activo?->name }}</span></div>
        <div class="dato"><b>Esquema de Color para la Revisión:</b> <span class="valor">{{ $esquema }}</span></div>
    </div>

    <div class="bloque">
        <p><b>Descripción de la Revisión:</b></p>
        <p>El equipo <b>{{ $activo?->name }}</b> fue sometido a una revisión rutinaria siguiendo los procedimientos establecidos para los equipos clasificados bajo el esquema de color <b>{{ $esquema }}</b>. Durante esta revisión, se evaluaron visualmente y operativamente las características pertinentes para determinar el estado del equipo.</p>
        <p>A continuación, se presenta el diagnóstico resultante de la revisión:</p>
        <p><b>Resultado de la Revisión:</b></p>
        <div class="resultado">{{ \App\Models\WorkOrder::RESULTADOS[$resultado] ?? 'Todo OK' }}</div>
    </div>

    <ol>
        <li>
            <b>Todo OK</b><br>
            El equipo se encuentra en buen estado, por lo que se realizará una limpieza y/o lubricación según el protocolo establecido. No se requieren acciones adicionales, y el equipo continuará siendo utilizado según las operaciones normales.
        </li>
    </ol>
    <div class="o"><b>O</b> (Si corresponde)</div>
    <ol start="2">
        <li>
            <b>Problemas Menores</b><br>
            Se observaron pequeñas anomalías en el equipo que no afectan su funcionamiento inmediato, pero que requieren atención preventiva. Las acciones necesarias serán programadas en el “Calendario Outlook: Cronograma de Mantenimiento”. A continuación, se detallan las observaciones detectadas durante la revisión:
            <div class="obs">Observaciones: @if ($resultado === 'menores')<span>{{ $orden->diagnosis }}</span>@endif</div>
        </li>
    </ol>
    <div class="o"><b>O</b> (Si corresponde)</div>
    <ol start="3">
        <li>
            <b>Problemas Importantes</b><br>
            Se identificaron fallas graves en el equipo, por lo que se ha detenido su uso de inmediato para evitar posibles daños adicionales o riesgos de seguridad. Se ha programado un mantenimiento correctivo y se procederá con las reparaciones o reemplazos necesarios. A continuación, se presentan las fallas detectadas y las acciones recomendadas:
            <div class="obs">Observaciones: @if ($resultado === 'importantes')<span>{{ $orden->diagnosis }}</span>@endif</div>
        </li>
    </ol>
</body>
</html>
