@php
    $tz = config('fabos.lab.timezone');
    $i = $inscripcion;
@endphp

<div style="font-size:.9rem">
    <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem;margin:0 0 1rem">
        <dt style="color:rgb(107 114 128)">Correo</dt><dd style="margin:0">{{ $i->user?->email }}</dd>
        <dt style="color:rgb(107 114 128)">Participante</dt><dd style="margin:0">{{ $i->tipoDeParticipante() ?? '—' }}</dd>
        <dt style="color:rgb(107 114 128)">Programa o área</dt><dd style="margin:0">{{ $i->program ?? '—' }}</dd>
        <dt style="color:rgb(107 114 128)">Estado</dt><dd style="margin:0">{{ \App\Models\Enrollment::ESTADOS[$i->status] ?? $i->status }}</dd>
        <dt style="color:rgb(107 114 128)">Se inscribió</dt><dd style="margin:0">{{ $i->enrolled_at?->timezone($tz)->format('d/m/Y H:i') }} · {{ $i->source === 'web' ? 'desde el sitio' : 'lo inscribió el equipo' }}</dd>
        @if ($i->waitlisted_at)
            <dt style="color:rgb(107 114 128)">Lista de espera</dt><dd style="margin:0">desde el {{ $i->waitlisted_at->timezone($tz)->format('d/m/Y H:i') }}@if ($i->promoted_at) · cupo asignado el {{ $i->promoted_at->timezone($tz)->format('d/m/Y H:i') }}@endif</dd>
        @endif
        @if ($i->consent_at)
            <dt style="color:rgb(107 114 128)">Aceptó condiciones</dt><dd style="margin:0">{{ $i->consent_at->timezone($tz)->format('d/m/Y H:i') }}</dd>
        @endif
    </dl>

    @if (! empty($i->answers))
        <h3 style="font-weight:600;margin:0 0 .5rem">Respuestas</h3>
        <dl style="margin:0">
            @foreach ($i->answers as $r)
                <dt style="color:rgb(107 114 128);margin-top:.6rem">{{ $r['pregunta'] ?? '' }}</dt>
                <dd style="margin:0">
                    @if (! empty($r['archivo']['ruta']))
                        <a class="underline" target="_blank" href="{{ \App\Filament\Componentes\ArchivoPrivado::url($r['archivo']['ruta'], $r['archivo']['nombre'] ?? null, descargar: true) }}">
                            {{ $r['archivo']['nombre'] ?? 'Descargar archivo' }}
                        </a>
                    @elseif (is_array($r['valor'] ?? null))
                        {{ implode(', ', $r['valor']) ?: '—' }}
                    @else
                        {{ filled($r['valor'] ?? null) ? $r['valor'] : '—' }}
                    @endif
                </dd>
            @endforeach
        </dl>
    @endif
</div>
