<x-filament-panels::page>
    @include('filament.pages.partes.estilos-semana')

    @php
        $hoy = \Illuminate\Support\Carbon::today(config('fabos.lab.timezone'))->toDateString();
        $nombresDeArea = $areas->pluck('name', 'id');
    @endphp

    <div class="sem">
        <div class="nav">
            <h2>Semana del {{ $dias[0]->locale('es')->isoFormat('D [de] MMMM') }}</h2>
            <button type="button" wire:click="semanaAnterior">← Anterior</button>
            <button type="button" wire:click="estaSemana">Esta semana</button>
            <button type="button" wire:click="semanaSiguiente">Siguiente →</button>
        </div>

        <div class="leyenda">
            <span><span class="punto" style="background:var(--pres)"></span> En el laboratorio</span>
            <span><span class="punto" style="background:var(--rem)"></span> Remoto</span>
            <span><span class="cambio">Cambiado</span> distinto a su horario de siempre</span>
            @if ($coordina) <span>Como coordinación, haz clic en un día para cambiarlo.</span> @endif
        </div>

        @if ($equipo->isEmpty())
            <div class="tarjeta">Nadie tiene horario esta semana. Los horarios se cargan en Jornadas → Horarios.</div>
        @else
            <div class="tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Persona</th>
                            @foreach ($visibles as $i)
                                <th class="@if ($dias[$i]->toDateString() === $hoy) hoy @endif">
                                    {{ ucfirst(rtrim($dias[$i]->locale('es')->isoFormat('ddd D'), '.')) }}<br>
                                    <span style="text-transform:none;letter-spacing:0;font-weight:700;color:var(--pres)">{{ $enElLab[$i] }} en el lab</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($equipo as $persona)
                            <tr wire:key="p-{{ $persona->id }}">
                                <td style="min-width:11rem">
                                    <strong>{{ $persona->name }}</strong>
                                    <div class="suave">
                                        {{ \App\Models\User::MODALIDADES_DE_TRABAJO[$persona->modalidadDeTrabajo()] }}
                                        @if (! empty($areasDe[$persona->id]))
                                            · {{ collect($areasDe[$persona->id])->map(fn ($a) => $nombresDeArea[$a] ?? null)->filter()->join(', ') }}
                                        @endif
                                    </div>
                                </td>
                                @foreach ($visibles as $i)
                                    @php
                                        $dia = $dias[$i];
                                        $bloques = $porDia[$i]['bloques'][$persona->id] ?? collect();
                                        $ausencia = $porDia[$i]['ausencias'][$persona->id] ?? null;
                                        $editable = ! $porDia[$i]['cierre'] && ! $ausencia && $this->puedeEditar($persona, $dia);
                                    @endphp
                                    <td class="@if ($dia->toDateString() === $hoy) hoy @endif">
                                        <div class="celda">
                                            @if ($porDia[$i]['cierre'])
                                                <span class="b ausente">Cerrado</span>
                                            @elseif ($ausencia)
                                                <span class="b ausente">{{ $ausencia }}</span>
                                            @elseif ($bloques->isEmpty())
                                                <span class="suave">—</span>
                                            @else
                                                @if ($bloques->contains('ajustado', true)) <span class="cambio">Cambiado</span> @endif
                                                @foreach ($bloques as $b)
                                                    <span class="b {{ $b['modalidad'] }}">{{ $b['inicio'] }}–{{ $b['fin'] }}{{ $b['modalidad'] === 'remota' ? ' · Remoto' : '' }}</span>
                                                @endforeach
                                            @endif
                                            @if ($editable && ($coordina || $persona->id === auth()->id()))
                                                {{ ($this->editarDiaAction)(['fecha' => $dia->toDateString(), 'persona' => $persona->id])->label('Cambiar')->link()->size('xs') }}
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($areas->isNotEmpty())
                <h3 style="font-weight:700;margin:1.5rem 0 .5rem">Presencial por área</h3>
                <p class="suave" style="margin-bottom:.6rem">Siempre tiene que haber al menos un profesional presencial por área. El área de cada persona sale de las máquinas que asesora.</p>
                <div class="tabla">
                    <table>
                        <thead>
                            <tr>
                                <th>Área</th>
                                @foreach ($visibles as $i)
                                    <th class="@if ($dias[$i]->toDateString() === $hoy) hoy @endif">{{ ucfirst(rtrim($dias[$i]->locale('es')->isoFormat('ddd D'), '.')) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($areas as $area)
                                <tr>
                                    <td><strong>{{ $area->name }}</strong></td>
                                    @foreach ($visibles as $i)
                                        @php $quienes = $coberturaPorArea[$i][$area->id] ?? []; @endphp
                                        <td class="@if ($dias[$i]->toDateString() === $hoy) hoy @endif">
                                            @if ($porDia[$i]['cierre'])
                                                <span class="suave">—</span>
                                            @elseif (count($quienes) === 0)
                                                <span class="b alerta">Nadie presencial</span>
                                            @else
                                                <span class="b presencial">{{ count($quienes) }}</span>
                                                <span class="suave">{{ $equipo->whereIn('id', $quienes)->pluck('name')->map(fn ($n) => \Illuminate\Support\Str::before($n, ' '))->join(', ') }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($cambios->isNotEmpty())
                <h3 style="font-weight:700;margin:1.5rem 0 .5rem">Cambios recientes</h3>
                <div class="tabla">
                    <table>
                        <thead><tr><th>Quién</th><th>Día</th><th>Quedó</th><th>Lo cambió</th></tr></thead>
                        <tbody>
                            @foreach ($cambios as $c)
                                @php
                                    $bloquesDelCambio = \App\Models\JornadaDelDia::where('user_id', $c->user_id)->whereDate('fecha', $c->fecha)->orderBy('starts_at')->get();
                                @endphp
                                <tr>
                                    <td>{{ $c->user?->name }}</td>
                                    <td>{{ ucfirst($c->fecha->locale('es')->isoFormat('ddd D [de] MMM')) }}</td>
                                    <td>
                                        <div class="fila">
                                            @foreach ($bloquesDelCambio as $b)
                                                <span class="b {{ $b->modalidad }}">{{ substr($b->starts_at, 0, 5) }}–{{ substr($b->ends_at, 0, 5) }}{{ $b->modalidad === 'remota' ? ' · Remoto' : '' }}</span>
                                            @endforeach
                                            @if ($c->forzado) <span class="b alerta" title="La coordinación lo guardó aunque un área quedaba sin nadie presencial">Forzado</span> @endif
                                        </div>
                                    </td>
                                    <td class="suave">{{ $c->creadoPor?->name ?? '—' }} · {{ $c->updated_at?->timezone(config('fabos.lab.timezone'))->locale('es')->isoFormat('D MMM, HH:mm') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
