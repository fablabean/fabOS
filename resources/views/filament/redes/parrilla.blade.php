<x-filament-panels::page>
    @php
        $tz = config('fabos.lab.timezone');
        // Como se las reconoce en una casilla de dos centímetros.
        $siglas = ['instagram' => 'IG', 'linkedin' => 'in', 'facebook' => 'FB', 'youtube' => 'YT', 'tiktok' => 'TT', 'x' => 'X', 'whatsapp' => 'WA'];
    @endphp

    {{-- Lo que pide atención, antes que el calendario. --}}
    @if ($porAprobar || $tarde)
        <div class="pr-alertas">
            @if ($porAprobar)
                <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('lista', ['filters' => ['estado' => ['values' => ['por_aprobar']]]]) }}" class="pr-alerta aviso">
                    {{ $porAprobar }} {{ $porAprobar === 1 ? 'publicación espera' : 'publicaciones esperan' }} aprobación
                </a>
            @endif
            @if ($tarde)
                <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('lista', ['filters' => ['abiertas' => ['value' => '1']]]) }}" class="pr-alerta peligro">
                    {{ $tarde }} {{ $tarde === 1 ? 'pasó su fecha y no ha salido' : 'pasaron su fecha y no han salido' }}
                </a>
            @endif
        </div>
    @endif

    <div class="pr-cuerpo">
        <div class="pr-mes">
            <div class="pr-cabecera">
                <div class="pr-nav">
                    <button type="button" wire:click="mover(-1)" class="pr-boton" aria-label="Mes anterior">‹</button>
                    <h2>{{ ucfirst($inicio->locale('es')->isoFormat('MMMM YYYY')) }}</h2>
                    <button type="button" wire:click="mover(1)" class="pr-boton" aria-label="Mes siguiente">›</button>
                    <button type="button" wire:click="irAHoy" class="pr-boton texto">Hoy</button>
                </div>

                {{-- Si el mes está cojo de algún tema, se ve aquí. --}}
                <div class="pr-pilares">
                    <span>{{ $totalDelMes }} en el mes</span>
                    @foreach (\App\Models\Publicacion::PILARES as $clave => $nombre)
                        @if ($porPilar->get($clave))
                            <span class="pr-chip">{{ $nombre }} · {{ $porPilar->get($clave) }}</span>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="pr-grilla" wire:loading.class="cargando">
                @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d)
                    <div class="pr-dia-nombre">{{ $d }}</div>
                @endforeach

                @foreach ($semanas as $dias)
                    @foreach ($dias as $dia)
                        <div @class(['pr-dia', 'fuera' => ! $dia['delMes'], 'hoy' => $dia['hoy']])>
                            <div class="pr-numero">
                                <span>{{ $dia['fecha']->day }}</span>
                                @if ($puedeCrear && (! $dia['fecha']->isPast() || $dia['hoy']))
                                    <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('create', ['dia' => $dia['fecha']->toDateString()]) }}"
                                       class="pr-mas" title="Nueva publicación este día">+</a>
                                @endif
                            </div>

                            @foreach ($dia['especiales'] as $f)
                                <span class="pr-especial" title="{{ $f['idea'] }}">★ {{ $f['nombre'] }}</span>
                            @endforeach

                            @foreach ($dia['piezas'] as $p)
                                <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('edit', ['record' => $p]) }}"
                                   class="pr-pieza estado-{{ $p->estado }} {{ $p->vaTarde() ? 'tarde' : '' }}"
                                   title="{{ $p->nombreDelEstado() }}{{ $p->nombresDeLasRedes() ? ' · ' . $p->nombresDeLasRedes() : '' }}">
                                    <span class="hora">{{ $p->programada_para->timezone($tz)->format('H:i') }}</span>
                                    {{ $p->titulo }}
                                    <span class="redes">{{ collect($p->redes ?? [])->map(fn ($r) => $siglas[$r] ?? $r)->join(' · ') }}@if ($p->avanceDeTareas()) · ✓ {{ $p->avanceDeTareas() }}@endif</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                @endforeach
            </div>

            <div class="pr-leyenda">
                @foreach (\App\Models\Publicacion::ESTADOS as $clave => $nombre)
                    <span><i class="estado-{{ $clave }}"></i>{{ $nombre }}</span>
                @endforeach
            </div>
        </div>

        <aside class="pr-lado">
            @if ($misTareas->isNotEmpty())
                <section class="pr-caja">
                    <h3>Mis tareas</h3>
                    @foreach ($misTareas as $t)
                        <div class="pr-tarea" wire:key="t-{{ $t->id }}">
                            <span>
                                <strong>{{ $t->que }}</strong>
                                <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('edit', ['record' => $t->publicacion]) }}">{{ $t->publicacion->titulo }}</a>
                                @if ($t->vence_el)
                                    <em @class(['tarde' => $t->vaTarde()])>{{ $t->vaTarde() ? 'iba para el' : 'para el' }} {{ $t->vence_el->locale('es')->isoFormat('ddd D MMM') }}</em>
                                @endif
                            </span>
                            <button type="button" class="pr-boton" wire:click="marcarHecha({{ $t->id }})" title="Marcar hecha">✓</button>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="pr-caja">
                <h3>Sugerencias de fabOS</h3>
                <p class="pr-ayuda">
                    De lo que el sistema sabe que viene: cursos y eventos publicados, proyectos con
                    página en el sitio, alianzas abiertas, material nuevo del banco y semanas vacías.
                    El borrador lo escriben las {{ $redactor }}: es un arranque, no el texto final.
                </p>

                @forelse ($sugerencias as $s)
                    <div class="pr-sugerencia" wire:key="s-{{ $s['clave'] }}">
                        <strong>{{ $s['titulo'] }}</strong>
                        <span class="porque">{{ $s['porque'] }}</span>
                        <span class="meta">
                            {{ \App\Models\Publicacion::PILARES[$s['pilar']] ?? '' }}
                            · {{ \App\Models\Publicacion::FORMATOS[$s['formato']] ?? '' }}
                            @if ($s['fecha'])
                                · {{ $s['fecha']->locale('es')->isoFormat('ddd D MMM') }}
                            @endif
                        </span>
                        <details>
                            <summary>Ver borrador</summary>
                            <pre>{{ $s['texto'] }}</pre>
                        </details>
                        @if ($puedeCrear)
                            <div class="acciones">
                                <button type="button" class="pr-boton primario" wire:click="aceptar(@js($s['clave']))">Llevar a la parrilla</button>
                                <button type="button" class="pr-boton texto" wire:click="descartar(@js($s['clave']))">No, gracias</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="pr-ayuda">Nada nuevo por ahora: la parrilla está al día con lo que pasa en el laboratorio.</p>
                @endforelse
            </section>

            <section class="pr-caja">
                <h3>Ideas sin fecha</h3>
                @forelse ($sinFecha as $p)
                    <a class="pr-pieza estado-{{ $p->estado }}" href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('edit', ['record' => $p]) }}">
                        {{ $p->titulo }}
                    </a>
                @empty
                    <p class="pr-ayuda">Ninguna. Una publicación sin fecha queda aquí hasta que se le ponga.</p>
                @endforelse
            </section>
        </aside>
    </div>

    <style>
        .pr-alertas { display:flex; gap:.6rem; flex-wrap:wrap; }
        .pr-alerta { padding:.45rem .8rem; border-radius:.5rem; font-size:.85rem; font-weight:600; text-decoration:none; }
        .pr-alerta.aviso { background:color-mix(in srgb,#D69E2E 16%,transparent); color:#975A16; }
        .pr-alerta.peligro { background:color-mix(in srgb,#E53E3E 14%,transparent); color:#C53030; }
        .dark .pr-alerta.aviso { color:#F6E05E; } .dark .pr-alerta.peligro { color:#FC8181; }

        .pr-cuerpo { display:grid; grid-template-columns:minmax(0,1fr) 20rem; gap:1.2rem; align-items:start; }
        @media (max-width:1100px) { .pr-cuerpo { grid-template-columns:minmax(0,1fr); } }

        .pr-mes, .pr-caja { background:var(--color-white, #fff); border:1px solid rgba(0,0,0,.08); border-radius:.75rem; padding:1rem; }
        .dark .pr-mes, .dark .pr-caja { background:rgba(255,255,255,.04); border-color:rgba(255,255,255,.1); }

        .pr-cabecera { display:flex; justify-content:space-between; align-items:center; gap:.8rem; flex-wrap:wrap; margin-bottom:.8rem; }
        .pr-nav { display:flex; align-items:center; gap:.4rem; }
        .pr-nav h2 { font-size:1.15rem; font-weight:700; min-width:10.5rem; text-align:center; }
        .pr-boton { border:1px solid rgba(0,0,0,.12); border-radius:.45rem; padding:.25rem .65rem; font-size:.85rem; background:transparent; cursor:pointer; }
        .dark .pr-boton { border-color:rgba(255,255,255,.18); }
        .pr-boton.texto { border-color:transparent; color:#6b7280; }
        .pr-boton.primario { background:var(--primary-600, #d97706); border-color:transparent; color:#fff; font-weight:600; }
        .pr-pilares { display:flex; gap:.35rem; flex-wrap:wrap; font-size:.75rem; color:#6b7280; align-items:center; }
        .pr-chip { padding:.1rem .5rem; border-radius:999px; background:rgba(0,0,0,.05); }
        .dark .pr-chip { background:rgba(255,255,255,.08); }

        .pr-grilla { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:1px; background:rgba(0,0,0,.08); border-radius:.5rem; overflow:hidden; }
        .dark .pr-grilla { background:rgba(255,255,255,.08); }
        .pr-grilla.cargando { opacity:.6; }
        .pr-dia-nombre { background:rgba(0,0,0,.03); font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; padding:.35rem .5rem; }
        .pr-dia { background:var(--color-white, #fff); min-height:7rem; padding:.3rem; display:flex; flex-direction:column; gap:.2rem; }
        .dark .pr-dia { background:#18181b; } .dark .pr-dia-nombre { background:#1f1f23; }
        .pr-dia.fuera { opacity:.45; }
        .pr-dia.hoy { box-shadow:inset 0 0 0 2px var(--primary-500, #f59e0b); }
        .pr-numero { display:flex; justify-content:space-between; font-size:.75rem; font-weight:600; color:#6b7280; }
        .pr-mas { opacity:0; text-decoration:none; padding:0 .3rem; border-radius:.3rem; }
        .pr-dia:hover .pr-mas { opacity:1; background:rgba(0,0,0,.06); }

        .pr-pieza { display:block; font-size:.72rem; line-height:1.25; padding:.25rem .4rem; border-radius:.35rem; text-decoration:none;
                    border-left:3px solid var(--c); background:color-mix(in srgb,var(--c) 12%,transparent); color:inherit; margin-bottom:.2rem;
                    overflow:hidden; text-overflow:ellipsis; }
        .pr-pieza .hora { font-weight:700; margin-right:.2rem; }
        .pr-pieza .redes { display:block; font-size:.62rem; color:#6b7280; font-weight:600; }
        .pr-pieza.tarde { outline:1px dashed #C53030; }
        .estado-idea { --c:#9ca3af; } .estado-en_diseno { --c:#3B82F6; } .estado-por_aprobar { --c:#D69E2E; }
        .estado-aprobada { --c:#0D9488; } .estado-programada { --c:#7C3AED; } .estado-publicada { --c:#16A34A; }
        .estado-descartada { --c:#DC2626; opacity:.55; text-decoration:line-through; }

        .pr-leyenda { display:flex; flex-wrap:wrap; gap:.8rem; margin-top:.7rem; font-size:.72rem; color:#6b7280; }
        .pr-leyenda i { display:inline-block; width:.65rem; height:.65rem; border-radius:.15rem; background:var(--c); margin-right:.3rem; vertical-align:-1px; }

        .pr-lado { display:flex; flex-direction:column; gap:1rem; }
        .pr-caja h3 { font-weight:700; font-size:.95rem; margin-bottom:.3rem; }
        .pr-ayuda { font-size:.75rem; color:#6b7280; margin-bottom:.6rem; }
        .pr-sugerencia { border-top:1px solid rgba(0,0,0,.08); padding:.65rem 0; display:flex; flex-direction:column; gap:.2rem; font-size:.82rem; }
        .dark .pr-sugerencia { border-color:rgba(255,255,255,.1); }
        .pr-sugerencia .porque { color:#4b5563; font-size:.76rem; } .dark .pr-sugerencia .porque { color:#a1a1aa; }
        .pr-sugerencia .meta { color:#6b7280; font-size:.7rem; }
        .pr-sugerencia summary { cursor:pointer; font-size:.72rem; color:#6b7280; }
        .pr-sugerencia pre { white-space:pre-wrap; font-family:inherit; font-size:.75rem; background:rgba(0,0,0,.04); padding:.5rem; border-radius:.4rem; margin-top:.3rem; }
        .pr-sugerencia .acciones { display:flex; gap:.4rem; margin-top:.3rem; }

        .pr-especial { display:block; font-size:.66rem; line-height:1.2; color:#B7791F; font-weight:600; margin-bottom:.15rem; }
        .dark .pr-especial { color:#F6E05E; }
        .pr-tarea { display:flex; justify-content:space-between; align-items:center; gap:.5rem; border-top:1px solid rgba(0,0,0,.08); padding:.5rem 0; font-size:.8rem; }
        .dark .pr-tarea { border-color:rgba(255,255,255,.1); }
        .pr-tarea span { display:flex; flex-direction:column; min-width:0; }
        .pr-tarea a { color:#6b7280; font-size:.74rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pr-tarea em { font-style:normal; font-size:.7rem; color:#6b7280; }
        .pr-tarea em.tarde { color:#C53030; font-weight:600; }
    </style>
</x-filament-panels::page>
