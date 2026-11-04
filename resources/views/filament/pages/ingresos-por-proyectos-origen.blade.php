{{-- El origen de una cifra de «Ingresos por proyectos»: qué proyectos la
     forman, con su valor y de qué campo viene. --}}
@php
    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
@endphp

<div style="overflow-x:auto">
    @if ($filas->isEmpty())
        <p style="color:rgb(107 114 128)">Ningún proyecto.</p>
    @else
        <p style="margin:0 0 .6rem;font-size:.85rem;color:rgb(107 114 128)">
            {{ $filas->count() }} {{ $filas->count() === 1 ? 'proyecto' : 'proyectos' }} ·
            {{ $pesos($filas->sum('valor')) }}. El valor sale de «Compromisos» en cada ficha: si está mal, se corrige ahí.
        </p>
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead>
                <tr style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)">
                    <th style="text-align:left;padding:.35rem .4rem">Código</th>
                    <th style="text-align:left;padding:.35rem .4rem">Proyecto</th>
                    <th style="text-align:left;padding:.35rem .4rem">Cliente</th>
                    <th style="text-align:left;padding:.35rem .4rem">Etapa</th>
                    <th style="text-align:left;padding:.35rem .4rem">Responsable</th>
                    <th style="text-align:right;padding:.35rem .4rem">Valor</th>
                    <th style="text-align:left;padding:.35rem .4rem">De dónde</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($filas as $f)
                @php $p = $f['proyecto']; @endphp
                <tr style="border-top:1px solid rgba(128,128,128,.2)">
                    <td style="padding:.35rem .4rem;white-space:nowrap">
                        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $p]) }}" target="_blank" style="text-decoration:underline">{{ $p->code }}</a>
                    </td>
                    <td style="padding:.35rem .4rem">{{ $p->name }}</td>
                    <td style="padding:.35rem .4rem">{{ \App\Models\Project::CLIENTES[$p->client_kind] ?? $p->client_kind }}{{ $p->is_internal ? ' · interno' : '' }}</td>
                    <td style="padding:.35rem .4rem">{{ \App\Models\Project::ETAPAS[$p->stage] ?? $p->stage }}{{ $p->status !== 'cerrado' && $p->status !== 'activo' ? ' · ' . (\App\Models\Project::ESTADOS[$p->status] ?? $p->status) : '' }}</td>
                    <td style="padding:.35rem .4rem">{{ $p->lead?->name ?? '—' }}</td>
                    <td style="padding:.35rem .4rem;text-align:right;white-space:nowrap">{{ $pesos($f['valor']) }}</td>
                    <td style="padding:.35rem .4rem;color:{{ $f['fuente'] === 'Sin valor' ? '#C53030' : ($f['fuente'] === 'Estimado' ? '#B7791F' : 'inherit') }}">{{ $f['fuente'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
