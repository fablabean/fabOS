<x-filament-panels::page>

    {{-- La ficha de una persona: todo lo que ha pasado con ella, en una
         pantalla. Estilos propios porque el CSS del panel no trae las
         rejillas que usa una pagina a medida. --}}
    <style>
        .ficha{display:flex;flex-direction:column;gap:1.5rem}
        .ficha .datos{display:grid;gap:.9rem 1.5rem;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr))}
        .ficha dt{font-size:.75rem;color:rgb(107 114 128);text-transform:uppercase;letter-spacing:.06em}
        .ficha dd{margin:.15rem 0 0;font-weight:500}
        .ficha .grande{font-size:1.6rem;font-weight:600;letter-spacing:-.02em;line-height:1.2}
        .ficha table{width:100%;border-collapse:collapse;font-size:.875rem}
        .ficha th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128);padding:.35rem .5rem;border-bottom:1px solid rgb(229 231 235)}
        .dark .ficha th{border-color:rgb(55 65 81)}
        .ficha td{padding:.45rem .5rem;border-bottom:1px solid rgb(243 244 246);vertical-align:top}
        .dark .ficha td{border-color:rgb(31 41 55)}
        .ficha .pie{font-size:.75rem;color:rgb(107 114 128)}
        .ficha .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
        .ficha .etiqueta{display:inline-block;font-size:.7rem;font-weight:600;padding:.1rem .45rem;border-radius:.35rem;background:rgb(243 244 246);color:rgb(55 65 81);margin-right:.25rem}
        .dark .ficha .etiqueta{background:rgb(31 41 55);color:rgb(209 213 219)}
        .ficha .ok{background:rgb(220 252 231);color:rgb(22 101 52)}
        .dark .ficha .ok{background:rgb(20 83 45);color:rgb(187 247 208)}
        .ficha .warn{background:rgb(254 243 199);color:rgb(146 64 14)}
        .dark .ficha .warn{background:rgb(120 53 15);color:rgb(253 230 138)}
        .ficha .bad{background:rgb(254 226 226);color:rgb(153 27 27)}
        .dark .ficha .bad{background:rgb(127 29 29);color:rgb(254 202 202)}
        .ficha a.enlace{color:rgb(37 99 235);text-decoration:underline}
        .dark .ficha a.enlace{color:rgb(147 197 253)}
    </style>

    @php
        $tz = config('fabos.lab.timezone');
        $unidades = (int) config('fabos.currency.minor_units');
        $moneda = config('fabos.currency.code');
        $fbc = fn (int $menor) => number_format($menor / $unidades, 2, ',', '.') . ' ' . $moneda;
        // Algunas fechas llegan como texto (no todas las columnas tienen cast).
        $fecha = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f)->timezone($tz)->format('d/m/Y') : null;
        $fechaHora = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f)->timezone($tz)->format('d/m/Y H:i') : null;
        $estadoReserva = fn (string $s) => match ($s) {
            'confirmada', 'en_curso', 'completada' => 'ok',
            'solicitada' => 'warn',
            default => 'bad',
        };
    @endphp

    <div class="ficha">

        {{-- ------------------------------------------------- quien es --}}
        <x-filament::section>
            <x-slot name="heading">Quién es</x-slot>

            <dl class="datos">
                <div><dt>Correo</dt><dd>{{ $persona->email ?: '—' }}</dd></div>
                <div><dt>Teléfono</dt><dd>{{ $persona->phone ?: '—' }}</dd></div>
                <div>
                    <dt>Categoría</dt>
                    <dd>
                        {{ $persona->category?->name ?? 'sin asignar' }}
                        <span class="etiqueta {{ $persona->category_confirmed ? 'ok' : 'warn' }}">{{ $persona->category_confirmed ? 'confirmada' : 'sin confirmar' }}</span>
                    </dd>
                </div>
                <div>
                    <dt>Rol en el panel</dt>
                    <dd>{{ $persona->roles->pluck('name')->map(fn ($r) => \App\Models\User::ROLES[$r] ?? $r)->implode(', ') ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Estado</dt>
                    <dd><span class="etiqueta {{ $persona->status === 'activo' ? 'ok' : 'bad' }}">{{ $persona->status }}</span></dd>
                </div>
                <div><dt>En fabOS desde</dt><dd>{{ $fecha($persona->created_at) }}</dd></div>
                @if ($persona->responsibleAreas->isNotEmpty())
                    <div><dt>Responsable de</dt><dd>{{ $persona->responsibleAreas->pluck('name')->implode(', ') }}</dd></div>
                @endif
                @if ($persona->validated_at)
                    <div><dt>Validada</dt><dd>{{ $fecha($persona->validated_at) }}</dd></div>
                @endif
            </dl>
        </x-filament::section>

        {{-- ------------------------------------------------- fabcoins --}}
        <x-filament::section>
            <x-slot name="heading">{{ config('fabos.currency.name') }}s</x-slot>
            <x-slot name="description">Lo que tiene y los últimos movimientos. El saldo se deriva de los asientos, no se guarda.</x-slot>

            <p class="grande">{{ $fbc($saldo) }}</p>

            @if ($movimientos->isEmpty())
                <p class="pie">Sin movimientos todavía.</p>
            @else
                <table>
                    <thead><tr><th>Cuándo</th><th>Qué</th><th class="num">Monto</th></tr></thead>
                    <tbody>
                    @foreach ($movimientos as $m)
                        <tr>
                            <td>{{ $fechaHora($m->transaction?->occurred_at ?? $m->created_at) }}</td>
                            <td>{{ $m->transaction?->memo ?? $m->transaction?->kind ?? '—' }}</td>
                            {{-- En la cuenta de una persona, el crédito suma y el débito resta. --}}
                            <td class="num">{{ $m->esDebito() ? '−' : '+' }} {{ $fbc((int) $m->amount_minor) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------ certifabs --}}
        <x-filament::section>
            <x-slot name="heading">Certifabs</x-slot>
            <x-slot name="description">Lo que está habilitada a usar sin nadie al lado.</x-slot>

            @if ($certifabs->isEmpty())
                <p class="pie">Ninguno todavía.</p>
            @else
                <table>
                    <thead><tr><th>Habilita</th><th>Nivel</th><th>Desde</th><th>Otorgó</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach ($certifabs as $c)
                        <tr>
                            <td>{{ $c->asset?->name ?? $c->riskFamily?->name ?? '—' }}</td>
                            <td>{{ $c->level }}</td>
                            <td>{{ $fecha($c->granted_at) }}</td>
                            <td>{{ $c->grantedBy?->name ?? ($c->granted_via === 'curso' ? 'un curso' : '—') }}</td>
                            <td>
                                @if ($c->revoked_at)
                                    <span class="etiqueta bad">revocado</span>
                                @else
                                    <span class="etiqueta ok">vigente</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- --------------------------------------------------- cursos --}}
        <x-filament::section>
            <x-slot name="heading">Formación</x-slot>

            @if ($cursos->isEmpty())
                <p class="pie">No se ha inscrito en ningún curso.</p>
            @else
                <table>
                    <thead><tr><th>Curso</th><th>Edición</th><th>Estado</th><th>Examen</th><th>Práctica</th><th>Certificado</th></tr></thead>
                    <tbody>
                    @foreach ($cursos as $i)
                        <tr>
                            <td>{{ $i->edition?->course?->name ?? '—' }}</td>
                            <td>{{ $i->edition?->code ?? '—' }}</td>
                            <td><span class="etiqueta {{ $i->aprobada() ? 'ok' : ($i->status === 'reprobado' ? 'bad' : '') }}">{{ \App\Models\Enrollment::ESTADOS[$i->status] ?? $i->status }}</span></td>
                            <td>{{ $i->theory_score === null ? '—' : $i->theory_score . '% · ' . $i->theory_attempts . ($i->theory_attempts === 1 ? ' intento' : ' intentos') }}</td>
                            <td>
                                @if ($i->practicaAprobada())
                                    Firmada por {{ $i->practicalBy?->name ?? '—' }} el {{ $fecha($i->practical_passed_at) }}
                                @elseif ($i->edition?->course?->requires_practical)
                                    Pendiente
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($i->certificate_code)
                                    <a class="enlace" href="{{ route('publico.verificar', $i->certificate_code) }}" target="_blank">{{ $i->certificate_code }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------ proyectos --}}
        <x-filament::section>
            <x-slot name="heading">Proyectos</x-slot>
            <x-slot name="description">Los que pidió, los que lleva y aquellos en cuyo equipo está.</x-slot>

            @if ($proyectos->isEmpty())
                <p class="pie">No aparece en ningún proyecto.</p>
            @else
                <table>
                    <thead><tr><th>Código</th><th>Proyecto</th><th>Papel</th><th>Etapa</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach ($proyectos as $fila)
                        @php $p = $fila['proyecto']; @endphp
                        <tr>
                            <td><a class="enlace" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p]) }}">{{ $p->code }}</a></td>
                            <td>{{ $p->name }}</td>
                            <td>@foreach ($fila['papeles'] as $papel)<span class="etiqueta">{{ $papel }}</span>@endforeach</td>
                            <td>{{ \App\Models\Project::ETAPAS[$p->stage] ?? $p->stage }}</td>
                            <td><span class="etiqueta {{ $p->status === 'activo' ? 'ok' : '' }}">{{ \App\Models\Project::ESTADOS[$p->status] ?? $p->status }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------- reservas --}}
        <x-filament::section>
            <x-slot name="heading">Reservas</x-slot>
            <x-slot name="description">
                Lo que reservó o pidió: equipos, espacios, asesorías y prácticas.
                @if ($cuantasReservas > $reservas->count()) Las últimas {{ $reservas->count() }} de {{ $cuantasReservas }}. @endif
            </x-slot>

            @if ($reservas->isEmpty())
                <p class="pie">No ha reservado nada.</p>
            @else
                <table>
                    <thead><tr><th>Cuándo</th><th>Qué</th><th>Modo</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach ($reservas as $r)
                        <tr>
                            <td class="num">{{ $fechaHora($r->starts_at) }} — {{ $r->ends_at->timezone($tz)->format('H:i') }}</td>
                            <td>
                                @if ($r->esAtencionPersonal())
                                    {{ $r->queAtiende() }} con {{ $r->reservable?->name ?? '—' }}
                                @else
                                    {{ $r->reservable?->name ?? '—' }}
                                @endif
                                @if ($r->purpose)<div class="pie">{{ \Illuminate\Support\Str::limit($r->purpose, 80) }}</div>@endif
                            </td>
                            <td>{{ \App\Models\Reservation::MODOS[$r->mode] ?? $r->mode }}</td>
                            <td><span class="etiqueta {{ $estadoReserva($r->status) }}">{{ \App\Models\Reservation::ESTADOS[$r->status] ?? $r->status }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------ lo que atiende --}}
        @if ($atenciones->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Lo que ha atendido</x-slot>
                <x-slot name="description">Asesorías y prácticas a su nombre, como parte del equipo.</x-slot>

                <table>
                    <thead><tr><th>Cuándo</th><th>Qué</th><th>A quién</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach ($atenciones as $r)
                        <tr>
                            <td class="num">{{ $fechaHora($r->starts_at) }}</td>
                            <td>{{ $r->queAtiende() }}</td>
                            <td>{{ $r->user?->name ?? '—' }}</td>
                            <td><span class="etiqueta {{ $estadoReserva($r->status) }}">{{ \App\Models\Reservation::ESTADOS[$r->status] ?? $r->status }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>
