<x-filament-panels::page>

    @php
        $tz = config('fabos.lab.timezone');
    @endphp

    {{-- Estilos propios: el CSS de Filament viene compilado y no trae las
         utilidades que usaría una página a medida. --}}
    <style>
        .bnd{display:flex;flex-direction:column;gap:1rem}
        .bnd .cuando{font-size:1.05rem;font-weight:600}
        .bnd .quien{font-size:.85rem;color:rgb(107 114 128)}
        .bnd .motivo{
            font-size:.85rem;margin:.5rem 0 0;padding:.5rem .7rem;border-radius:6px;
            background:rgba(180,83,9,.10);color:#b45309;
        }
        .bnd .decidir{
            display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;
            margin-top:.9rem;padding-top:.9rem;border-top:1px solid rgba(128,128,128,.2);
        }
        .bnd label{display:block;font-size:.78rem;color:rgb(107 114 128);margin-bottom:.25rem}
        .bnd select,.bnd input[type=text]{
            font:inherit;font-size:.9rem;padding:.45rem .6rem;border-radius:8px;
            border:1px solid rgba(128,128,128,.35);background:transparent;color:inherit;min-width:14rem;
        }
        .bnd .nota{font-size:.8rem;color:rgb(107 114 128);margin-top:.5rem}
    </style>

    <div class="bnd">

        @forelse ($solicitudes as $fila)
            @php
                $s = $fila['reserva'];
                $equipo = $fila['equipo'];
            @endphp

            <x-filament::section>
                <x-slot name="heading">{{ $equipo?->name ?? 'Equipo eliminado' }}</x-slot>
                <x-slot name="description">
                    {{ $s->user?->name }} · {{ $s->user?->email }}
                </x-slot>

                <div class="cuando">
                    {{ $s->starts_at->timezone($tz)->translatedFormat('l j \d\e F') }},
                    de {{ $s->starts_at->timezone($tz)->format('H:i') }}
                    a {{ $s->ends_at->timezone($tz)->format('H:i') }}
                </div>

                @if ($s->purpose)
                    <div class="quien">Para: {{ $s->purpose }}</div>
                @endif

                @if ($s->status_reason)
                    <p class="motivo">{{ $s->status_reason }}</p>
                @endif

                <div class="decidir">
                    <div>
                        <label for="acom-{{ $s->id }}">Quién la atiende</label>
                        <select id="acom-{{ $s->id }}" wire:model="acompanante.{{ $s->id }}">
                            <option value="">Nadie: no hace falta acompañamiento</option>
                            @foreach ($fila['candidatos'] as $c)
                                <option value="{{ $c['id'] }}">
                                    {{ $c['nombre'] }}
                                    — {{ $c['en_jornada'] ? 'en jornada' : 'habría que abrirle el día' }}
                                    · {{ $c['extras_mes'] }} de {{ $topeMes }} h extras este mes
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <x-filament::button wire:click="aprobar({{ $s->id }})" color="success">
                        Aprobar
                    </x-filament::button>

                    <div>
                        <label for="mot-{{ $s->id }}">Motivo, si se rechaza</label>
                        <input id="mot-{{ $s->id }}" type="text"
                               wire:model="motivo.{{ $s->id }}"
                               placeholder="Ese día el edificio está cerrado">
                    </div>

                    <x-filament::button wire:click="rechazar({{ $s->id }})" color="danger">
                        Rechazar
                    </x-filament::button>
                </div>

                @if ($fila['candidatos']->isEmpty())
                    <p class="nota">
                        Nadie tiene certifab para este equipo todavía. Se puede aprobar sin
                        acompañante solo si el equipo no lo exige.
                    </p>
                @else
                    <p class="nota">
                        Al aprobar con acompañante se le programa la jornada y se le reserva el
                        tiempo. Si eso pasa del tope de extras, el sistema no deja aprobar.
                    </p>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>
                <x-slot name="heading">Nada por decidir</x-slot>
                <p style="margin:0">
                    No hay solicitudes esperando. Aquí llega lo que el sistema no puede confirmar
                    solo: pedidos fuera de la franja atendida, equipos que se piden en vez de
                    reservarse, y sesiones más largas de lo que permite el certifab de quien pide.
                </p>
            </x-filament::section>
        @endforelse

    </div>

</x-filament-panels::page>
