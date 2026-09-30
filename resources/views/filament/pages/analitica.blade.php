<x-filament-panels::page>
    @php
        $canalesLegibles = [
            'buscador' => 'Buscadores', 'redes' => 'Redes sociales', 'ia' => 'Asistentes de IA',
            'enlace' => 'Otros sitios', 'directo' => 'Directo o sin dato', 'campaña' => 'Campañas (utm)',
        ];
        $familias = ['ia' => 'IA', 'buscador' => 'Buscador', 'redes' => 'Redes', 'otro' => 'Otro'];
        $conversionesLegibles = \App\Services\Analitica\Analitica::CONVERSIONES;
        $maxDia = max(1, $porDia->max('visitantes'));
        $maxCanal = max(1, $canales->max('entradas') ?? 0);
        $num = fn ($n) => number_format((float) $n, 0, ',', '.');
        $tz = config('fabos.lab.timezone');
    @endphp

    {{-- Estilos propios: el CSS de Filament viene compilado con un conjunto
         fijo de clases y las de una pagina a medida no se aplicarian. --}}
    <style>
        .ana{display:flex;flex-direction:column;gap:1.25rem}
        .ana .fila{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem}
        .ana select{border-radius:.5rem;border:1px solid rgba(128,128,128,.35);padding:.35rem 2rem .35rem .6rem;background:transparent;font-size:.9rem}
        .ana .cifras{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr))}
        .ana .cifra b{display:block;font-size:1.7rem;letter-spacing:-.02em;line-height:1.1}
        .ana .cifra span{font-size:.78rem;color:rgb(107 114 128)}
        .ana .dos{display:grid;gap:1.25rem;grid-template-columns:repeat(auto-fit,minmax(22rem,1fr))}
        .ana .grafico{display:flex;align-items:flex-end;gap:2px;height:9rem;padding-top:.5rem}
        .ana .grafico div{flex:1;background:rgb(13 110 99);border-radius:2px 2px 0 0;min-height:1px;position:relative}
        .ana .grafico div:hover{background:rgb(92 201 184)}
        .ana .ejes{display:flex;justify-content:space-between;font-size:.72rem;color:rgb(107 114 128);margin-top:.3rem}
        .ana table{width:100%;font-size:.85rem;border-collapse:collapse}
        .ana th,.ana td{text-align:left;padding:.35rem .45rem;border-bottom:1px solid rgba(128,128,128,.2);vertical-align:top}
        .ana th{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .ana td.num,.ana th.num{text-align:right;white-space:nowrap}
        .ana .ruta{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;word-break:break-all}
        .ana .barra{height:.5rem;border-radius:999px;background:rgba(128,128,128,.18);overflow:hidden;min-width:5rem}
        .ana .barra i{display:block;height:100%;background:rgb(13 110 99)}
        .ana .ia{color:rgb(124 58 237);font-weight:600}
        .ana .gris{color:rgb(107 114 128);font-size:.84rem}
        .ana .vacio{color:rgb(107 114 128);font-size:.88rem;padding:.4rem 0}
    </style>

    <div class="ana">
        <div class="fila">
            <p class="gris" style="margin:0">
                Del {{ $informe->desde()->format('d/m/Y') }} al {{ $informe->hasta()->format('d/m/Y') }}.
                Sin cookies y sin datos personales; el equipo no se cuenta. Cómo se mide, en
                <a class="underline" href="{{ \App\Filament\Pages\GuiaBuscadoresYAnalitica::getUrl() }}">Documentación → Buscadores y analítica</a>.
            </p>
            <select wire:model.live="dias" aria-label="Periodo">
                @foreach (\App\Filament\Pages\Analitica::PERIODOS as $n => $texto)
                    <option value="{{ $n }}">{{ $texto }}</option>
                @endforeach
            </select>
        </div>

        <x-filament::section>
            <div class="cifras">
                <div class="cifra"><b>{{ $num($cifras['visitantes']) }}</b><span>visitantes (por día)</span></div>
                <div class="cifra"><b>{{ $num($cifras['vistas']) }}</b><span>páginas vistas</span></div>
                <div class="cifra"><b>{{ number_format($cifras['por_visitante'], 1, ',', '.') }}</b><span>páginas por visitante</span></div>
                <div class="cifra"><b>{{ $num($cifras['conversiones']) }}</b><span>conversiones</span></div>
                <div class="cifra"><b class="ia">{{ $num($cifras['desde_ia']) }}</b><span>llegadas desde asistentes de IA</span></div>
                <div class="cifra"><b class="ia">{{ $num($cifras['rastreos_ia']) }}</b><span>lecturas de rastreadores de IA</span></div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Visitantes por día</x-slot>
            <div class="grafico">
                @foreach ($porDia as $d)
                    <div style="height:{{ round(100 * $d->visitantes / $maxDia) }}%"
                         title="{{ \Illuminate\Support\Carbon::parse($d->dia)->format('d/m') }}: {{ $d->visitantes }} visitantes, {{ $d->vistas }} páginas"></div>
                @endforeach
            </div>
            <div class="ejes">
                <span>{{ $informe->desde()->format('d/m') }}</span>
                <span>máx. {{ $num($maxDia) }} visitantes en un día</span>
                <span>{{ $informe->hasta()->format('d/m') }}</span>
            </div>
        </x-filament::section>

        <div class="dos">
            <x-filament::section>
                <x-slot name="heading">De dónde llegan</x-slot>
                <x-slot name="description">Cada entrada al sitio desde fuera, por canal.</x-slot>
                @forelse ($canales as $c)
                    <table><tr>
                        <td style="width:40%" class="{{ $c->canal === 'ia' ? 'ia' : '' }}">{{ $canalesLegibles[$c->canal] ?? $c->canal }}</td>
                        <td><div class="barra"><i style="width:{{ round(100 * $c->entradas / $maxCanal) }}%"></i></div></td>
                        <td class="num" style="width:4rem">{{ $num($c->entradas) }}</td>
                    </tr></table>
                @empty
                    <p class="vacio">Todavía no hay visitas en este periodo.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Fuentes</x-slot>
                <table>
                    <thead><tr><th>Fuente</th><th>Canal</th><th class="num">Entradas</th></tr></thead>
                    <tbody>
                    @forelse ($fuentes as $f)
                        <tr>
                            <td class="{{ $f->canal === 'ia' ? 'ia' : '' }}">{{ $f->fuente }}</td>
                            <td class="gris">{{ $canalesLegibles[$f->canal] ?? $f->canal }}</td>
                            <td class="num">{{ $num($f->entradas) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="vacio">Sin datos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <div class="dos">
            <x-filament::section>
                <x-slot name="heading">Páginas más vistas</x-slot>
                <table>
                    <thead><tr><th>Página</th><th class="num">Vistas</th><th class="num">Visitantes</th></tr></thead>
                    <tbody>
                    @forelse ($paginas as $p)
                        <tr><td class="ruta"><a href="{{ url($p->ruta) }}" target="_blank" class="underline">{{ $p->ruta }}</a></td><td class="num">{{ $num($p->vistas) }}</td><td class="num">{{ $num($p->visitantes) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="vacio">Sin datos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Por dónde entran</x-slot>
                <x-slot name="description">La primera página que ve quien llega de fuera.</x-slot>
                <table>
                    <thead><tr><th>Página</th><th class="num">Entradas</th></tr></thead>
                    <tbody>
                    @forelse ($entradas as $p)
                        <tr><td class="ruta">{{ $p->ruta }}</td><td class="num">{{ $num($p->entradas) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="vacio">Sin datos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Embudo de las actividades</x-slot>
            <x-slot name="description">Cuántos vieron la página de cada curso, taller o evento, cuántos empezaron el formulario y cuántos terminaron inscritos.</x-slot>
            <table>
                <thead><tr><th>Actividad</th><th class="num">La vieron</th><th class="num">Empezaron el formulario</th><th class="num">Inscritos</th><th class="num">En espera</th><th class="num">Conversión</th></tr></thead>
                <tbody>
                @forelse ($embudos as $e)
                    <tr>
                        <td>{{ $e->nombre }} <span class="ruta gris">{{ $e->ruta }}</span></td>
                        <td class="num">{{ $num($e->vieron) }}</td>
                        <td class="num">{{ $num($e->formulario) }}</td>
                        <td class="num">{{ $num($e->inscritos) }}</td>
                        <td class="num">{{ $num($e->en_espera) }}</td>
                        <td class="num">{{ $e->vieron ? round(100 * ($e->inscritos + $e->en_espera) / $e->vieron) . ' %' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vacio">Nadie ha visitado una actividad en este periodo.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <div class="dos">
            <x-filament::section>
                <x-slot name="heading">Conversiones</x-slot>
                <x-slot name="description">Anotadas por el servidor al guardar, con el canal por el que había llegado esa persona ese día.</x-slot>
                <table>
                    <thead><tr><th>Qué</th><th>Llegó por</th><th class="num">Cuántas</th></tr></thead>
                    <tbody>
                    @forelse ($conversiones as $c)
                        <tr>
                            <td>{{ $conversionesLegibles[$c->tipo] ?? $c->tipo }}</td>
                            <td class="{{ $c->canal === 'ia' ? 'ia' : 'gris' }}">{{ $canalesLegibles[$c->canal] ?? $c->canal }}</td>
                            <td class="num">{{ $num($c->n) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="vacio">Sin conversiones en este periodo.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Recorridos más comunes</x-slot>
                <x-slot name="description">De qué página a qué página se mueve la gente dentro del sitio.</x-slot>
                <table>
                    <thead><tr><th>Desde</th><th>Hacia</th><th class="num">Veces</th></tr></thead>
                    <tbody>
                    @forelse ($recorridos as $r)
                        <tr><td class="ruta">{{ $r->desde }}</td><td class="ruta">→ {{ $r->ruta }}</td><td class="num">{{ $num($r->veces) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="vacio">Sin datos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <div class="dos">
            <x-filament::section>
                <x-slot name="heading">Rastreadores</x-slot>
                <x-slot name="description">Quién está leyendo el sitio para buscadores y asistentes de IA. No ejecutan el script: se anotan en el servidor.</x-slot>
                <table>
                    <thead><tr><th>Rastreador</th><th>Tipo</th><th class="num">Páginas</th><th class="num">Última vez</th></tr></thead>
                    <tbody>
                    @forelse ($rastreadores as $r)
                        <tr>
                            <td class="{{ $r->familia === 'ia' ? 'ia' : '' }}">{{ $r->bot }}</td>
                            <td class="gris">{{ $familias[$r->familia] ?? $r->familia }}</td>
                            <td class="num">{{ $num($r->visitas) }}</td>
                            <td class="num gris">{{ \Illuminate\Support\Carbon::parse($r->ultima)->timezone($tz)->format('d/m H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vacio">Ningún rastreador en este periodo.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Dispositivos y campañas</x-slot>
                <table>
                    <thead><tr><th>Dispositivo</th><th class="num">Visitantes</th></tr></thead>
                    <tbody>
                    @forelse ($dispositivos as $d)
                        <tr><td>{{ ['movil' => 'Teléfono', 'tableta' => 'Tableta', 'escritorio' => 'Computador'][$d->dispositivo] ?? $d->dispositivo }}</td><td class="num">{{ $num($d->visitantes) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="vacio">Sin datos.</td></tr>
                    @endforelse
                    </tbody>
                </table>

                <table style="margin-top:1rem">
                    <thead><tr><th>Campaña (utm_campaign)</th><th>Fuente</th><th class="num">Entradas</th></tr></thead>
                    <tbody>
                    @forelse ($campanas as $c)
                        <tr><td>{{ $c->utm_campaign }}</td><td class="gris">{{ $c->utm_source }}</td><td class="num">{{ $num($c->entradas) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="vacio">Sin campañas. Se miden con enlaces que llevan <code>?utm_campaign=…</code>.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
