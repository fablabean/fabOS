<x-filament-panels::page>

    {{-- Estilos propios: Filament no trae las utilidades de tabla que usaría
         una página a medida, y sin esto las cifras se apilan. --}}
    <style>
        .dot table { width:100%; border-collapse:collapse; font-size:.9rem; }
        .dot th, .dot td { padding:.45rem .5rem; text-align:left; }
        .dot thead th { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em;
                        color:rgb(107 114 128); border-bottom:1px solid rgb(229 231 235); }
        .dot td.num, .dot th.num { text-align:right; font-variant-numeric:tabular-nums; }
        .dot tbody tr { border-bottom:1px solid rgb(243 244 246); }
        .dot .ya td { color:rgb(156 163 175); }
        .dot .total { font-size:1.6rem; font-weight:600; letter-spacing:-.02em; }
        .dot .nota { font-size:.8rem; color:rgb(107 114 128); }
        .dark .dot thead th { border-color:rgb(55 65 81); }
        .dark .dot tbody tr { border-color:rgb(31 41 55); }
    </style>

    @php
        $unidades = (int) config('fabos.currency.minor_units');
        $moneda = config('fabos.currency.code');
        $fbc = fn (int $menor) => number_format($menor / $unidades, 2, ',', '.') . ' ' . $moneda;

        $filas = $this->aQuien();
        $pendiente = $this->pendiente();
    @endphp

    <div class="dot">
        <x-filament::section>
            <x-slot name="heading">Periodo {{ $periodo }}</x-slot>
            <x-slot name="description">
                Emitir moneda es un acto del laboratorio, no una consecuencia del calendario.
                Aquí se ve a quién y cuánto <em>antes</em> de pulsar, y lo emitido queda con tu
                nombre en el libro.
            </x-slot>

            <div style="display:flex;gap:2.5rem;flex-wrap:wrap;align-items:baseline">
                <div>
                    <p class="nota" style="margin:0">Se emitiría ahora</p>
                    <p class="total" style="margin:0">{{ $fbc($pendiente) }}</p>
                </div>
                <div>
                    <p class="nota" style="margin:0">A cuántas personas</p>
                    <p class="total" style="margin:0">
                        {{ collect($filas)->reject(fn ($f) => $f['ya'])->count() }}
                    </p>
                </div>
            </div>

            @if ($pendiente > 0)
                <x-slot name="footer">
                    <x-filament::button
                        wire:click="emitir"
                        wire:confirm="Se van a crear {{ $fbc($pendiente) }} y abonarse a {{ collect($filas)->reject(fn ($f) => $f['ya'])->count() }} personas. Queda a tu nombre. ¿Seguir?">
                        Emitir la dotación de {{ $periodo }}
                    </x-filament::button>
                </x-slot>
            @endif
        </x-filament::section>

        <x-filament::section class="mt-4" collapsible>
            <x-slot name="heading">Quién recibe, y por qué</x-slot>
            <x-slot name="description">
                Sale de la <strong>categoría</strong> de cada persona, no de una decisión
                individual. Se cambia en Personas → Categorías de usuario.
            </x-slot>

            @if (empty($filas))
                <p class="nota">
                    Ninguna categoría tiene dotación configurada, así que no hay nada que emitir.
                </p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>Categoría</th>
                            <th class="num">Dotación</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filas as $f)
                            <tr class="{{ $f['ya'] ? 'ya' : '' }}">
                                <td>{{ $f['persona']->name }}</td>
                                <td>{{ $f['categoria'] }}</td>
                                <td class="num">{{ $fbc($f['importe']) }}</td>
                                <td>
                                    {{-- Repetir no abona dos veces: la clave de
                                         idempotencia lleva el periodo. --}}
                                    {{ $f['ya'] ? 'Ya la tiene de ' . $periodo : 'Pendiente' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="nota" style="margin-top:.9rem">
                    Volver a emitir el mismo periodo es inofensivo: a quien ya la tiene no se le
                    abona otra vez.
                </p>
            @endif
        </x-filament::section>
    </div>

</x-filament-panels::page>
