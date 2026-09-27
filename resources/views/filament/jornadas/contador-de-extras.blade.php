<x-filament-widgets::widget>
    {{-- Estilos propios, como en las demás tarjetas del panel: Filament no
         trae las utilidades de rejilla que necesita esta tabla. --}}
    <style>
        .extras table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .extras th, .extras td { padding:.5rem .6rem; border-bottom:1px solid rgb(229 231 235); text-align:left; vertical-align:middle; }
        .extras th { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:rgb(107 114 128); font-weight:600; }
        .extras th.n, .extras td.n { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .extras .barra { height:.4rem; border-radius:999px; background:rgb(229 231 235); overflow:hidden; min-width:6rem; margin-top:.25rem; }
        .extras .barra span { display:block; height:100%; background:rgb(5 150 105); }
        .extras .barra.alta span { background:rgb(217 119 6); }
        .extras .barra.llena span { background:rgb(220 38 38); }
        .extras .de { color:rgb(107 114 128); font-size:.78rem; }
        .extras .aviso { color:rgb(185 28 28); font-size:.78rem; }
        .extras .nav { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; font-size:.85rem; margin-bottom:.8rem; }
        .extras .nav button { background:none; border:0; padding:0; color:rgb(217 119 6); cursor:pointer; text-decoration:underline; font:inherit; }
        .extras .nota { font-size:.78rem; color:rgb(107 114 128); margin-top:.8rem; }
        .dark .extras th, .dark .extras td { border-color:rgb(55 65 81); }
        .dark .extras .barra { background:rgb(55 65 81); }
    </style>

    @php
        $h = fn (int $min) => rtrim(rtrim(number_format($min / 60, 1, ',', '.'), '0'), ',');
        $pct = fn (int $min, int $tope) => $tope > 0 ? min(100, round($min / $tope * 100)) : 0;
        $tono = fn (int $min, int $tope) => $min >= $tope ? 'llena' : ($min >= $tope * .75 ? 'alta' : '');
    @endphp

    <div class="extras">
        <x-filament::section collapsible persist-collapsed id="contador-de-extras">
            <x-slot name="heading">
                Horas extras · corte del {{ $desde->format('d/m') }} al {{ $hasta->format('d/m/Y') }}
            </x-slot>
            <x-slot name="description">
                El periodo corta el 15. Topes: {{ $h($topeDia) }} h al día, {{ $h($topeSemana) }} h a la semana
                y {{ $h($topeMes) }} h en el periodo. Solo cuentan las jornadas marcadas como extra.
            </x-slot>

            <div class="nav" wire:loading.class="opacity-50">
                <button type="button" wire:click="irA('{{ $anterior }}')">← Corte anterior</button>
                @unless ($esActual)
                    <button type="button" wire:click="irA('{{ now(config('fabos.lab.timezone'))->toDateString() }}')">Corte actual</button>
                    <button type="button" wire:click="irA('{{ $siguiente }}')">Corte siguiente →</button>
                @endunless
            </div>

            @if ($filas->isEmpty())
                <p class="de">Nadie con jornada ni con horas extras en este corte.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>En el periodo</th>
                            <th class="n">{{ $esActual ? 'Esta semana' : 'Última semana' }}</th>
                            <th class="n">{{ $esActual ? 'Hoy' : 'Último día' }}</th>
                            <th class="n">Le quedan</th>
                            <th class="n">Jornadas</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($filas as $f)
                        <tr>
                            <td>
                                {{ $f['persona']->name }}
                                @if ($f['dias_excedidos'] || $f['semanas_excedidas'] || $f['excede_periodo'])
                                    <div class="aviso">
                                        Se pasó del tope:
                                        {{ collect([
                                            $f['dias_excedidos'] ? $f['dias_excedidos'] . ($f['dias_excedidos'] === 1 ? ' día' : ' días') : null,
                                            $f['semanas_excedidas'] ? $f['semanas_excedidas'] . ($f['semanas_excedidas'] === 1 ? ' semana' : ' semanas') : null,
                                            $f['excede_periodo'] ? 'el periodo' : null,
                                        ])->filter()->implode(', ') }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $h($f['periodo']) }} h</strong> <span class="de">de {{ $h($topeMes) }}</span>
                                <div class="barra {{ $tono($f['periodo'], $topeMes) }}"><span style="width:{{ $pct($f['periodo'], $topeMes) }}%"></span></div>
                            </td>
                            <td class="n">{{ $h($f['semana']) }} <span class="de">/ {{ $h($topeSemana) }}</span></td>
                            <td class="n">{{ $h($f['hoy']) }} <span class="de">/ {{ $h($topeDia) }}</span></td>
                            <td class="n">{{ $h($f['disponible']) }} h</td>
                            <td class="n">{{ $f['jornadas'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            <p class="nota">
                El sistema no deja programar por encima de ningún tope; si aparece «se pasó», es algo
                programado antes de que existiera el tope diario o registrado a mano. Una jornada que se
                compensa con tiempo no cuenta aquí.
            </p>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
