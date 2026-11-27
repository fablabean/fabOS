{{--
    Informe de Mantenimiento (Preventivo o Correctivo), INV-118 §6.4.
    El mismo texto y orden del informe que el laboratorio llena en Word:
    datos del equipo, tabla del mantenimiento, lo que se hizo, piezas y
    resultados. Lo que la orden no tiene se deja en blanco, como en el Word.
--}}
@php
    $piezas = collect($orden->piezas_reemplazadas ?? [])->filter(fn ($p) => filled($p['nombre'] ?? null));
    $fabricante = trim(($activo?->brand ?? '') . ' ' . ($activo?->model ?? ''));
    $ubicacion = $activo?->location?->path ?: $activo?->location?->name ?: ($activo?->area?->name ? 'FabLab, área de ' . $activo->area->name : '');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Mantenimiento</title>
    <style>
        @page { margin: 2.5cm 2.6cm 2.2cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; line-height: 1.25; }
        h1 { text-align: center; font-size: 16pt; margin: 0 0 1.2cm; }
        h2 { font-size: 13pt; margin: .9cm 0 .5cm; }
        .dato { margin: 0 0 .55cm .55cm; }
        .valor-sans { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; }
        table { width: 92%; margin-left: .55cm; border-collapse: collapse; }
        td { border: 1px solid #000; padding: 1px 6px; vertical-align: top; }
        td.k { font-weight: bold; width: 45%; }
        ul { margin: 0; padding-left: 1.05cm; }
        li { margin-bottom: .7cm; }
        .pieza { margin: 0 0 .25cm .55cm; }
        .pieza + .cant { margin: 0 0 .5cm .55cm; }
        .pie { position: fixed; bottom: -1.2cm; left: 0; right: 0; font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #777; text-align: right; }
    </style>
</head>
<body>
    <div class="pie">fabOS · orden de trabajo #{{ $orden->id }} · INV-118</div>

    <h1>Informe de Mantenimiento (Preventivo o Correctivo)</h1>

    <h2>Datos del equipo intervenido</h2>
    <div class="dato"><b>Nombre del equipo:</b> <span class="valor-sans">{{ $activo?->name }}</span></div>
    <div class="dato"><b>Código de identificación:</b> <span class="valor-sans">{{ $activo?->asset_tag }}</span></div>
    <div class="dato"><b>Categoría de la máquina</b>: {{ $esquema !== '—' ? $esquema : '' }}</div>
    <div class="dato"><b>Ubicación del equipo dentro del FabLab:</b> {{ $ubicacion }}</div>
    <div class="dato"><b>Fabricante y modelo:</b> {{ $fabricante }}</div>

    <h2>Datos del mantenimiento</h2>
    <table>
        <tr><td class="k">Tipo de mantenimiento</td><td>{{ \App\Models\WorkOrder::TIPOS[$orden->kind] ?? ucfirst($orden->kind) }}</td></tr>
        <tr><td class="k">Fecha de inicio</td><td>{{ $inicio->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Fecha de finalización</td><td>{{ $fecha->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Responsable del mantenimiento</td><td>{{ $orden->assignedTo?->name }}</td></tr>
    </table>

    <h2>Descripción de la tarea realizada</h2>
    <ul>
        <li><b>Acciones realizadas:</b> {{ $orden->work_done }}</li>
        <li><b>Observaciones adicionales:</b> {{ $orden->diagnosis }}</li>
    </ul>

    <h2>Piezas o componentes reemplazados (Si aplica)</h2>
    @forelse ($piezas as $p)
        <div class="pieza"><b>Nombre de la pieza o componente:</b> {{ $p['nombre'] }}</div>
        <div class="cant pieza"><b>Cantidad:</b> {{ $p['cantidad'] ?? '' }}</div>
    @empty
        <div class="pieza"><b>Nombre de la pieza o componente:</b></div>
        <div class="cant pieza"><b>Cantidad:</b></div>
    @endforelse

    <h2>Resultados del mantenimiento</h2>
    <div class="dato"><b>Estado del equipo post mantenimiento:</b> {{ $orden->estado_post }}</div>
    <div class="dato"><b>Pruebas realizadas:</b> {{ $orden->pruebas_realizadas }}</div>
    <div class="dato"><b>Recomendaciones futuras:</b> {{ $orden->recomendaciones }}</div>
</body>
</html>
