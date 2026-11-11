<x-filament-panels::page>
    <style>
        .mhe { --borde: rgb(229 231 235); --suave: rgb(107 114 128); font-size: .9rem; }
        .dark .mhe { --borde: rgb(55 65 81); --suave: rgb(156 163 175); }
        .mhe .tarjetas { display:grid; grid-template-columns:repeat(auto-fit,minmax(10rem,1fr)); gap:.75rem; }
        .mhe .tarjeta { border:1px solid var(--borde); border-radius:.75rem; padding:.8rem 1rem; }
        .mhe .tarjeta .n { font-size:1.6rem; font-weight:700; font-variant-numeric:tabular-nums; line-height:1.1; }
        .mhe .tarjeta .r { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--suave); font-weight:600; }
        .mhe .tarjeta .d { font-size:.78rem; color:var(--suave); margin-top:.2rem; }
        .mhe .t-pendiente { border-color: rgb(217 119 6); }
        .mhe .t-programada .n { color: rgb(37 99 235); }
        .mhe .t-pendiente .n { color: rgb(217 119 6); }
        .mhe .t-reportada .n { color: rgb(5 150 105); }
        .mhe table { width:100%; border-collapse:collapse; }
        .mhe th, .mhe td { padding:.5rem .55rem; border-bottom:1px solid var(--borde); text-align:left; vertical-align:top; }
        .mhe th { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:var(--suave); font-weight:600; }
        .mhe td.n, .mhe th.n { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .mhe .de { color:var(--suave); font-size:.78rem; }
        .mhe .tabla { overflow-x:auto; }
        .mhe input[type=time], .mhe input[type=text], .mhe select, .mhe textarea {
            width:100%; border:1px solid var(--borde); border-radius:.5rem; padding:.35rem .5rem; background:transparent; font:inherit; }
        .mhe input[type=time] { width:6.5rem; }
        .mhe textarea { min-width:16rem; min-height:4.2rem; }
        .mhe .campos { display:grid; grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:.8rem; margin-bottom:1rem; }
        .mhe label .r { display:block; font-size:.78rem; font-weight:600; margin-bottom:.25rem; }
        .mhe .error { color: rgb(220 38 38); font-size:.78rem; margin-top:.2rem; }
        .mhe .aviso { border-left:4px solid rgb(217 119 6); padding:.6rem .9rem; background:rgba(217,119,6,.08); border-radius:.4rem; }
        .mhe .pasos { margin:.4rem 0 0 1.2rem; list-style:decimal; }
        .mhe .pasos li { margin:.15rem 0; }
        .mhe .anulado td { text-decoration: line-through; color: var(--suave); }
        .mhe .barra { height:.4rem; border-radius:999px; background:var(--borde); overflow:hidden; margin-top:.35rem; }
        .mhe .barra span { display:block; height:100%; background:rgb(5 150 105); }
        .mhe .barra.alta span { background:rgb(217 119 6); }
        .mhe .barra.llena span { background:rgb(220 38 38); }
        .mhe .boton { display:inline-flex; align-items:center; gap:.4rem; background:rgb(217 119 6); color:#fff; font-weight:600;
            border-radius:.5rem; padding:.55rem 1rem; border:0; cursor:pointer; }
        .mhe .boton[disabled] { opacity:.6; cursor:wait; }
        .mhe a.enlace { color: rgb(217 119 6); text-decoration: underline; }
    </style>

    @php
        $h = fn (int $min) => rtrim(rtrim(number_format($min / 60, 2, ',', '.'), '0'), ',') ?: '0';
        $hora = fn ($t) => $t->copy()->setTimezone($tz);
        $estadoColor = \App\Models\ShiftAssignment::COLORES;
        $estadoNombre = \App\Models\ShiftAssignment::ESTADOS;
        $minPorReportar = $porReportar->sum(fn ($j) => $j->minutos());
        $minDespues = $despuesDelCorte->sum(fn ($j) => $j->minutos());
    @endphp

    <div class="mhe" style="display:flex;flex-direction:column;gap:1.5rem">

        @if ($flexible)
            <div class="aviso">
                <strong>Tu contrato es flexible.</strong> No haces horas extras: lo que trabajes fuera de tu jornada
                —un sábado, por ejemplo— se te compensa con tiempo dentro de la semana siguiente, y solo se te reconoce
                el recargo nocturno (de 19:00 a 6:00). Por eso tus jornadas salen como «Compensadas» y no van al
                formato de horas extras.
                <div style="margin-top:.4rem">
                    Recargo nocturno en el periodo del {{ $periodo[0]->format('d/m') }} al {{ $periodo[1]->format('d/m') }}:
                    <strong>{{ $h($recargoNocturno) }} h</strong>.
                </div>
            </div>
        @endif

        {{-- Los saldos: en qué va cada hora. --}}
        <x-filament::section>
            <x-slot name="heading">Ciclo de {{ $ciclo }} · corte el {{ $corte->locale('es')->isoFormat('dddd D [de] MMMM') }}</x-slot>
            <x-slot name="description">
                Entra en el reporte lo que ya hiciste hasta el día del corte. Lo programado para después no entra; cuando lo hagas, queda para el ciclo siguiente.
            </x-slot>

            <div class="tarjetas">
                <div class="tarjeta t-pendiente">
                    <div class="r">Pendientes de reporte</div>
                    <div class="n">{{ $h($saldos['pendiente']) }} h</div>
                    <div class="d">
                        {{ $h($minPorReportar) }} h entran en este corte{{ $minDespues ? ', ' . $h($minDespues) . ' h quedan para el siguiente' : '' }}.
                    </div>
                </div>
                <div class="tarjeta t-programada">
                    <div class="r">Programadas</div>
                    <div class="n">{{ $h($saldos['programada']) }} h</div>
                    <div class="d">Todavía no se han hecho.</div>
                </div>
                <div class="tarjeta t-reportada">
                    <div class="r">Reportadas para nómina</div>
                    <div class="n">{{ $h($saldos['reportada']) }} h</div>
                    <div class="d">Ya van en un reporte.</div>
                </div>
                <div class="tarjeta">
                    <div class="r">Compensadas</div>
                    <div class="n">{{ $h($saldos['compensada']) }} h</div>
                    <div class="d">Reconocidas con tiempo; no van a pago.</div>
                </div>
            </div>

            @unless ($flexible)
                <div class="tarjetas" style="margin-top:.9rem">
                    @foreach (['dia' => 'Hoy', 'semana' => 'Esta semana', 'periodo' => 'En el periodo (' . $periodo[0]->format('d/m') . ' al ' . $periodo[1]->format('d/m') . ')'] as $clave => $rotulo)
                        @php [$usado, $tope] = $topes[$clave]; $pct = $tope ? min(100, round($usado / $tope * 100)) : 0; @endphp
                        <div class="tarjeta">
                            <div class="r">{{ $rotulo }}</div>
                            <div><strong>{{ $h($usado) }} h</strong> <span class="de">de {{ $h($tope) }} h</span></div>
                            <div class="barra {{ $usado >= $tope ? 'llena' : ($usado >= $tope * .75 ? 'alta' : '') }}"><span style="width:{{ $pct }}%"></span></div>
                        </div>
                    @endforeach
                </div>
                <p class="de" style="margin-top:.6rem">Los topes se miran sobre todas las horas extras programadas y trabajadas, ya estén reportadas o no.</p>
            @endunless
        </x-filament::section>

        {{-- Generar el reporte. --}}
        <x-filament::section>
            <x-slot name="heading">Generar mi reporte de nómina</x-slot>
            <x-slot name="description">El ADF-404-f4 «Autorización trabajo horas extras», diligenciado con tus datos.</x-slot>

            @if ($porReportar->isEmpty())
                <p>
                    No tienes horas extras pendientes de reportar hasta el corte del {{ $corte->format('d/m/Y') }}.
                    @if ($despuesDelCorte->isNotEmpty())
                        Las {{ $h($minDespues) }} h que hiciste después del corte entran en el ciclo siguiente.
                    @endif
                </p>
            @else
                <form wire:submit="generar">
                    <div class="campos">
                        <label><span class="r">Nombre</span><input type="text" value="{{ auth()->user()->name }}" disabled></label>
                        <label>
                            <span class="r">Documento de identificación</span>
                            <input type="text" wire:model="documento" inputmode="numeric" autocomplete="off">
                            @error('documento') <div class="error">{{ $message }}</div> @enderror
                        </label>
                        <label>
                            <span class="r">Tipo de contrato</span>
                            <select wire:model="tipoContrato">
                                <option value="">Elige…</option>
                                <option value="fijo">Término fijo</option>
                                <option value="indefinido">Término indefinido</option>
                            </select>
                            @error('tipoContrato') <div class="error">{{ $message }}</div> @enderror
                        </label>
                        <label>
                            <span class="r">Proceso o área</span>
                            <input type="text" wire:model="area" placeholder="Unidad de Desarrollo">
                            @error('area') <div class="error">{{ $message }}</div> @enderror
                        </label>
                        <label>
                            <span class="r">Jefe inmediato <span class="de">(firma después de ti)</span></span>
                            <select wire:model="coordinadorId">
                                <option value="">Elige…</option>
                                @foreach ($coordinadores as $id => $nombre)
                                    <option value="{{ $id }}">{{ $nombre }}</option>
                                @endforeach
                            </select>
                            @error('coordinadorId') <div class="error">{{ $message }}</div> @enderror
                        </label>
                    </div>

                    @if ($porReportar->count() > $maxFilas)
                        <p class="aviso" style="margin-bottom:.8rem">El formato tiene {{ $maxFilas }} filas: entran las {{ $maxFilas }} más antiguas y las demás quedan pendientes para otro reporte.</p>
                    @endif

                    <div class="tabla">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Horario laboral</th>
                                    <th>Horario extra</th>
                                    <th class="n">Horas</th>
                                    <th>Actividades realizadas</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filas as $id => $f)
                                    @php $j = $porReportar[$id] ?? null; @endphp
                                    @continue (! $j)
                                    @php $partes = \App\Services\Staffing\FormatoAdf404::partir($j); @endphp
                                    <tr wire:key="fila-{{ $id }}">
                                        <td style="white-space:nowrap">
                                            {{ ucfirst($hora($j->starts_at)->locale('es')->isoFormat('ddd D [de] MMM')) }}
                                            @if (\App\Services\Staffing\FormatoAdf404::esFestivo($hora($j->starts_at)))
                                                <div class="de">Domingo o festivo</div>
                                            @endif
                                        </td>
                                        <td style="white-space:nowrap">
                                            <input type="time" wire:model="filas.{{ $id }}.laboral_inicio"> a
                                            <input type="time" wire:model="filas.{{ $id }}.laboral_fin">
                                            @error("filas.$id.laboral_inicio") <div class="error">{{ $message }}</div> @enderror
                                            @error("filas.$id.laboral_fin") <div class="error">{{ $message }}</div> @enderror
                                            <div class="de">Sin jornada ese día: 00:00 a 00:00.</div>
                                        </td>
                                        <td style="white-space:nowrap">
                                            {{ $hora($j->starts_at)->format('H:i') }} a {{ $hora($j->ends_at)->format('H:i') }}
                                        </td>
                                        <td class="n">
                                            {{ $h($j->minutos()) }}
                                            <div class="de">
                                                {{ collect([
                                                    $partes['diurnas'] ? $h($partes['diurnas']) . ' diurnas' : null,
                                                    $partes['nocturnas'] ? $h($partes['nocturnas']) . ' nocturnas' : null,
                                                    $partes['diurnas_festivas'] ? $h($partes['diurnas_festivas']) . ' diurnas fest.' : null,
                                                    $partes['nocturnas_festivas'] ? $h($partes['nocturnas_festivas']) . ' nocturnas fest.' : null,
                                                ])->filter()->implode(' · ') }}
                                            </div>
                                        </td>
                                        <td>
                                            <textarea wire:model="filas.{{ $id }}.actividades" maxlength="1000"></textarea>
                                            @error("filas.$id.actividades") <div class="error">{{ $message }}</div> @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                        <button type="submit" class="boton" wire:loading.attr="disabled" wire:target="generar">
                            <x-filament::icon icon="heroicon-o-pencil-square" class="h-5 w-5" />
                            Generar y firmar mi reporte
                        </button>
                        <span class="de">
                            {{ $h($minPorReportar) }} h en {{ $porReportar->count() === 1 ? 'una jornada' : $porReportar->count() . ' jornadas' }}.
                            Al generarlo, pasan de pendientes a reportadas.
                        </span>
                    </div>

                    <ol class="pasos de" style="margin-top:.8rem">
                        <li>Lo firmas en pantalla, con el dedo o con el mouse.</li>
                        <li>Le llega a tu jefe inmediato un correo para que lo firme.</li>
                        <li>Con las dos firmas, lo envías desde «Mis reportes» a novedades de nómina, en Excel.</li>
                    </ol>
                </form>
            @endif
        </x-filament::section>

        {{-- Las jornadas. --}}
        <x-filament::section collapsible>
            <x-slot name="heading">Mis jornadas</x-slot>
            <x-slot name="description">Las de los últimos cuatro meses, las que vienen y todo lo que siga sin reportar.</x-slot>

            @if ($jornadas->isEmpty())
                <p class="de">Todavía no tienes jornadas programadas fuera de tu horario.</p>
            @else
                <div class="tabla">
                    <table>
                        <thead><tr><th>Cuándo</th><th class="n">Horas</th><th>Motivo</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach ($jornadas as $j)
                                @php $e = $j->estado(); @endphp
                                <tr>
                                    <td style="white-space:nowrap">
                                        {{ ucfirst($hora($j->starts_at)->locale('es')->isoFormat('ddd D [de] MMM YYYY')) }}
                                        <div class="de">{{ $hora($j->starts_at)->format('H:i') }} a {{ $hora($j->ends_at)->format('H:i') }}</div>
                                    </td>
                                    <td class="n">{{ $h($j->minutos()) }}</td>
                                    <td>{{ $j->reason }}</td>
                                    <td>
                                        <x-filament::badge :color="$estadoColor[$e]">{{ $estadoNombre[$e] }}</x-filament::badge>
                                        <div class="de">
                                            @if ($j->reporte_id)
                                                Reporte #{{ $j->reporte_id }} · corte del {{ $j->reporte?->corte?->format('d/m/Y') }}
                                            @elseif ($e === 'compensada' && $j->compensa_el)
                                                Se compensa el {{ $j->compensa_el->locale('es')->isoFormat('ddd D [de] MMM') }}
                                            @elseif ($e === 'pendiente' && $j->starts_at->gt($corte->copy()->endOfDay()))
                                                Para el ciclo siguiente
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- El historial. --}}
        <x-filament::section collapsible>
            <x-slot name="heading">Mis reportes</x-slot>
            <x-slot name="description">Cada reporte que generaste. Descargarlo otra vez no cambia tus saldos.</x-slot>

            @if ($reportes->isEmpty())
                <p class="de">Todavía no has generado ningún reporte.</p>
            @else
                <div class="tabla">
                    <table>
                        <thead><tr><th>Corte</th><th>Generado</th><th class="n">Jornadas</th><th class="n">Horas</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($reportes as $r)
                                <tr @class(['anulado' => $r->estaAnulado()])>
                                    <td>
                                        {{ $r->corte->format('d/m/Y') }}
                                        <div class="de">Ciclo de {{ \App\Models\CorteDeNomina::nombreDelCiclo($r->corte) }}</div>
                                    </td>
                                    <td>
                                        {{ $hora($r->created_at)->format('d/m/Y H:i') }}
                                        @if ($r->origen === \App\Models\ReporteDeExtras::POR_FUERA)
                                            <div class="de">Reportadas a nómina por fuera de fabOS</div>
                                        @endif
                                    </td>
                                    <td class="n">{{ $r->estaAnulado() ? count($r->datos['jornadas'] ?? []) : $r->jornadas_count }}</td>
                                    <td class="n">
                                        {{ $h($r->minutos) }}
                                        <div class="de">
                                            {{ collect([
                                                $r->diurnas ? $h($r->diurnas) . ' diurnas' : null,
                                                $r->nocturnas ? $h($r->nocturnas) . ' nocturnas' : null,
                                                $r->diurnas_festivas ? $h($r->diurnas_festivas) . ' diurnas fest.' : null,
                                                $r->nocturnas_festivas ? $h($r->nocturnas_festivas) . ' nocturnas fest.' : null,
                                            ])->filter()->implode(' · ') }}
                                        </div>
                                    </td>
                                    <td>
                                        @if ($r->estaAnulado())
                                            <span class="de" style="text-decoration:none">Anulado el {{ $hora($r->anulado_at)->format('d/m/Y') }}</span>
                                        @elseif ($r->origen === \App\Models\ReporteDeExtras::FABOS)
                                            @php
                                                $color = match ($r->estado) { 'enviado' => 'success', 'firmado' => 'info', 'por_firmar' => 'warning', default => 'gray' };
                                            @endphp
                                            <x-filament::badge :color="$color">{{ $r->estadoLegible() }}</x-filament::badge>
                                            <div style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:center;margin-top:.4rem">
                                                @if ($r->estado === 'generado' || ($r->estado === 'por_firmar' && ! $r->firmo('trabajador')))
                                                    <button type="button" class="boton" wire:click="firmarReporte({{ $r->id }})" wire:loading.attr="disabled">Firmar</button>
                                                @elseif ($r->puedeEnviarse())
                                                    <button type="button" class="boton" wire:click="enviarANomina({{ $r->id }})"
                                                            wire:confirm="Se envía a novedades de nómina ({{ \App\Support\ConfiguracionDeNomina::valor('correo') }}) con el Excel firmado. ¿Lo envío?"
                                                            wire:loading.attr="disabled" wire:target="enviarANomina({{ $r->id }})">
                                                        {{ $r->estado === 'enviado' ? 'Enviar otra vez' : 'Enviar a novedades de nómina' }}
                                                    </button>
                                                @endif
                                                @if ($r->tieneArchivo())
                                                    <a class="enlace" href="{{ route('nomina.reporte.descargar', $r) }}">Descargar</a>
                                                @endif
                                            </div>
                                            @if ($r->estado === 'enviado')
                                                <div class="de">El {{ $hora($r->enviado_a_nomina_at)->format('d/m/Y H:i') }} a {{ $r->enviado_a_nomina_a }}</div>
                                            @elseif ($r->estado === 'generado')
                                                <div class="de">Se descargó antes de que hubiera firmas en fabOS. Si no lo enviaste, fírmalo aquí.</div>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
