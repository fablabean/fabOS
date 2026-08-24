<x-filament-panels::page>

    {{-- Los estilos van aquí: Filament trae su CSS precompilado, así que las
         utilidades de rejilla de Tailwind que él no usa no existen en
         producción y la página se apila en una sola columna. --}}
    <style>
        .as-rejilla { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .as-caja { border: 1px solid rgba(128,128,128,.25); border-radius: .65rem; padding: 1rem 1.15rem; }
        .as-caja h3 { margin: 0 0 .15rem; font-size: 1rem; font-weight: 700; }
        .as-sub { font-size: .8rem; opacity: .65; margin-bottom: .75rem; }
        .as-tabla { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .as-tabla td { padding: .35rem 0; border-bottom: 1px solid rgba(128,128,128,.15); }
        .as-tabla td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
        .as-marca { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em;
                    background: rgba(16,185,129,.15); color: #047857; padding: .1rem .4rem;
                    border-radius: .3rem; margin-left: .4rem; }
        .as-aviso { border-left: 4px solid #b45309; background: rgba(180,83,9,.08);
                    padding: .9rem 1.1rem; border-radius: .5rem; }
        .as-vacio { padding: 2rem 0; text-align: center; opacity: .65; }
        .as-hist { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .as-hist th, .as-hist td { padding: .55rem .5rem; text-align: left;
                    border-bottom: 1px solid rgba(128,128,128,.2); }
        .as-hist th { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; opacity: .7; }
    </style>

    @php($reparto = $this->reparto)
    @php($unicos = $this->puntosUnicos)

    @if ($unicos->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Equipos con un solo asesor</x-slot>

            <div class="as-aviso">
                <p>
                    El día que esa persona falte, <strong>nadie podrá pedir asesoría de esa
                    máquina</strong> — y el sistema no lo va a decir: simplemente no habrá
                    horas libres.
                </p>
                <p style="margin-top:.5rem">
                    {{ $unicos->pluck('name')->implode(' · ') }}
                </p>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Cómo va el reparto</x-slot>
        <x-slot name="description">
            El sistema asigna cada asesoría a quien menos lleva de ese equipo. Aquí se
            comprueba si está saliendo parejo de verdad.
        </x-slot>

        @if ($reparto->isEmpty())
            <p class="as-vacio">
                Todavía no hay asesores declarados en ningún equipo.<br>
                Se declaran en la ficha de cada equipo, en <strong>Quién asesora</strong>.
            </p>
        @else
            <div class="as-rejilla">
                @foreach ($reparto as $bloque)
                    <div class="as-caja">
                        <h3>{{ $bloque['equipo']->name }}</h3>
                        <p class="as-sub">
                            {{ $bloque['total'] }}
                            {{ $bloque['total'] === 1 ? 'asesoría atendida' : 'asesorías atendidas' }}
                        </p>

                        <table class="as-tabla">
                            @foreach ($bloque['filas'] as $fila)
                                <tr>
                                    <td>
                                        {{ $fila['persona'] }}
                                        @if ($fila['responsable'])
                                            <span class="as-marca">responsable</span>
                                        @endif
                                    </td>
                                    <td>{{ $fila['cuantas'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Historial</x-slot>
        <x-slot name="description">Las últimas 60 asesorías, incluidas las canceladas.</x-slot>

        @php($historial = $this->historial)

        @if ($historial->isEmpty())
            <p class="as-vacio">Todavía no se ha pedido ninguna asesoría.</p>
        @else
            <div style="overflow-x:auto">
                <table class="as-hist">
                    <thead>
                        <tr>
                            <th>Cuándo</th>
                            <th>Equipo</th>
                            <th>Quién la pidió</th>
                            <th>Quién la atendió</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($historial as $a)
                            <tr>
                                <td>{{ $a->starts_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}</td>
                                <td>{{ $a->advisoryAsset?->name ?? '—' }}</td>
                                <td>{{ $a->user?->name ?? '—' }}</td>
                                <td>{{ $a->reservable?->name ?? '—' }}</td>
                                <td>{{ \App\Models\Reservation::ESTADOS[$a->status] ?? $a->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

</x-filament-panels::page>
