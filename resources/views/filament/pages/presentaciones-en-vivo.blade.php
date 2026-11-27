<x-filament-panels::page>
    @php
        $zona = config('fabos.lab.timezone');
        $hora = fn ($ms) => \Illuminate\Support\Carbon::createFromTimestampMs($ms)->setTimezone($zona);
        $letras = ['A', 'B', 'C', 'D', 'E', 'F'];
    @endphp

    <style>
        .ev { display: grid; gap: 1.75rem; }
        .ev-tit { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: .75rem; }
        .ev-tit h2 { font-size: 1rem; font-weight: 600; color: var(--gray-950); }
        .dark .ev-tit h2 { color: #fff; }
        .ev-tit p { font-size: .8rem; color: var(--gray-500); }

        .ev-salas { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 22rem), 1fr)); }
        .ev-card { border-radius: .9rem; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / .05); outline: 1px solid rgb(0 0 0 / .06); display: flex; flex-direction: column; overflow: hidden; }
        .dark .ev-card { background: var(--gray-900); outline-color: rgb(255 255 255 / .1); }
        .ev-card.vivo { outline: 2px solid var(--success-500); }
        .ev-cab { padding: 1rem 1.1rem .5rem; display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; }
        .ev-cab small { display: block; font: 600 .66rem/1 ui-monospace, monospace; letter-spacing: .1em; text-transform: uppercase; color: var(--gray-400); margin-bottom: .35rem; }
        .ev-cab b { font-size: 1.05rem; font-weight: 700; color: var(--gray-950); }
        .dark .ev-cab b { color: #fff; }

        .ev-pill { display: inline-flex; align-items: center; gap: .35rem; white-space: nowrap; font-size: .72rem; font-weight: 600; padding: .2rem .6rem; border-radius: 99px; background: var(--gray-100); color: var(--gray-600); }
        .dark .ev-pill { background: rgb(255 255 255 / .07); color: var(--gray-300); }
        .ev-pill i { width: .45rem; height: .45rem; border-radius: 50%; background: currentColor; }
        .ev-pill.vivo { background: color-mix(in oklab, var(--success-500) 15%, transparent); color: var(--success-700); }
        .dark .ev-pill.vivo { color: var(--success-400); }
        .ev-pill.vivo i { animation: ev-late 1.4s ease-in-out infinite; }
        .ev-pill.hoy { background: color-mix(in oklab, var(--primary-500) 15%, transparent); color: var(--primary-700); }
        .dark .ev-pill.hoy { color: var(--primary-400); }
        @keyframes ev-late { 50% { opacity: .25; } }

        .ev-cifras { display: grid; grid-template-columns: repeat(4, 1fr); margin: .5rem 1.1rem; border-radius: .7rem; background: var(--gray-50); }
        .dark .ev-cifras { background: rgb(255 255 255 / .04); }
        .ev-cifras div { padding: .6rem .3rem; text-align: center; }
        .ev-cifras div + div { border-left: 1px solid rgb(0 0 0 / .06); }
        .dark .ev-cifras div + div { border-left-color: rgb(255 255 255 / .06); }
        .ev-cifras b { display: block; font-size: 1.25rem; font-weight: 700; line-height: 1.2; color: var(--gray-950); font-variant-numeric: tabular-nums; }
        .dark .ev-cifras b { color: #fff; }
        .ev-cifras span { font-size: .68rem; color: var(--gray-500); }

        .ev-votos { display: grid; gap: .55rem; padding: .4rem 1.1rem .2rem; }
        .ev-voto { display: grid; grid-template-columns: 4.5rem 1fr 2rem; align-items: center; gap: .6rem; font-size: .78rem; }
        .ev-voto > span:first-child { font-weight: 600; color: var(--gray-700); text-transform: capitalize; }
        .dark .ev-voto > span:first-child { color: var(--gray-300); }
        .ev-voto > span:last-child { text-align: right; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .ev-barra { display: flex; height: 1.15rem; border-radius: .4rem; overflow: hidden; background: var(--gray-100); }
        .dark .ev-barra { background: rgb(255 255 255 / .06); }
        .ev-barra span { display: grid; place-items: center; font-size: .65rem; font-weight: 700; color: #fff; min-width: 0; overflow: hidden; }
        .ev-barra span:nth-child(1) { background: var(--primary-500); }
        .ev-barra span:nth-child(2) { background: var(--info-500, #3b82f6); }
        .ev-barra span:nth-child(3) { background: var(--success-500); }
        .ev-barra span:nth-child(4) { background: var(--danger-400); }
        .ev-nota { font-size: .75rem; color: var(--gray-500); padding: .3rem 1.1rem 0; }

        .ev-enlace { margin: .8rem 1.1rem 0; display: flex; align-items: center; gap: .4rem; border-radius: .55rem; padding: .35rem .35rem .35rem .65rem; background: var(--gray-50); font: .72rem ui-monospace, monospace; color: var(--gray-600); }
        .dark .ev-enlace { background: rgb(255 255 255 / .04); color: var(--gray-400); }
        .ev-enlace span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ev-enlace button { font: 600 .7rem system-ui, sans-serif; padding: .25rem .55rem; border-radius: .4rem; background: #fff; outline: 1px solid rgb(0 0 0 / .08); color: var(--gray-700); }
        .dark .ev-enlace button { background: var(--gray-800); outline-color: rgb(255 255 255 / .1); color: var(--gray-200); }

        .ev-pie { margin-top: auto; padding: .9rem 1.1rem 1rem; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .ev-mas { margin-left: auto; display: flex; gap: .2rem; }
        .ev-link { font-size: .72rem; color: var(--gray-500); padding: .25rem .45rem; border-radius: .35rem; }
        .ev-link:hover { color: var(--gray-900); background: var(--gray-100); }
        .dark .ev-link:hover { color: #fff; background: rgb(255 255 255 / .06); }

        .ev-hist { border-radius: .9rem; background: #fff; outline: 1px solid rgb(0 0 0 / .06); overflow: hidden; }
        .dark .ev-hist { background: var(--gray-900); outline-color: rgb(255 255 255 / .1); }
        .ev-fila { display: grid; grid-template-columns: 4.2rem 1fr auto; align-items: center; gap: 1rem; padding: .8rem 1.1rem; }
        .ev-fila + .ev-fila, .ev-det + .ev-fila { border-top: 1px solid rgb(0 0 0 / .06); }
        .dark .ev-fila + .ev-fila, .dark .ev-det + .ev-fila { border-top-color: rgb(255 255 255 / .06); }
        .ev-dia { text-align: center; border-radius: .6rem; padding: .3rem 0; background: var(--gray-50); }
        .dark .ev-dia { background: rgb(255 255 255 / .05); }
        .ev-dia b { display: block; font-size: 1.2rem; line-height: 1.1; color: var(--gray-950); }
        .dark .ev-dia b { color: #fff; }
        .ev-dia span { font-size: .65rem; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-500); }
        .ev-fila .t { font-weight: 600; font-size: .9rem; color: var(--gray-950); }
        .dark .ev-fila .t { color: #fff; }
        .ev-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .3rem; }
        .ev-chip { font-size: .7rem; padding: .1rem .5rem; border-radius: 99px; background: var(--gray-100); color: var(--gray-600); }
        .dark .ev-chip { background: rgb(255 255 255 / .06); color: var(--gray-300); }
        .ev-acc { display: flex; gap: .4rem; }
        .ev-det { padding: .2rem 1.1rem 1.2rem 6.3rem; display: grid; gap: 1rem; }
        @media (max-width: 640px) { .ev-det { padding-left: 1.1rem; } .ev-fila { grid-template-columns: 3.4rem 1fr; } .ev-acc { grid-column: 1 / -1; } }
        .ev-det h4 { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--gray-500); margin-bottom: .45rem; }
        .ev-det .ev-votos { padding: 0; max-width: 34rem; }
        .ev-tws { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr)); }
        .ev-tw { border-radius: .6rem; padding: .6rem .75rem; background: var(--gray-50); font-size: .8rem; }
        .dark .ev-tw { background: rgb(255 255 255 / .04); }
        .ev-tw header { display: flex; justify-content: space-between; gap: .5rem; font-size: .7rem; color: var(--gray-500); margin-bottom: .2rem; }
        .ev-tw header b { color: var(--gray-800); }
        .dark .ev-tw header b { color: var(--gray-200); }
        .ev-tw p { color: var(--gray-800); }
        .dark .ev-tw p { color: var(--gray-200); }
        .ev-tw .h { margin-top: .3rem; font-size: .72rem; color: var(--primary-600); }
        .dark .ev-tw .h { color: var(--primary-400); }
        .ev-tw .r { margin-top: .2rem; font-size: .72rem; color: var(--gray-500); font-style: italic; }
        .ev-vacio { padding: 2rem 1rem; text-align: center; font-size: .85rem; color: var(--gray-500); }
    </style>

    <div wire:poll.5s class="ev">
        {{-- ------------------------------------------------------- salas --}}
        <div>
            <div class="ev-tit">
                <h2>Las presentaciones</h2>
                <p>Comparte solo el enlace de participantes. El de profesor lleva la agenda.</p>
            </div>
            <div class="ev-salas">
                @foreach ($salas as $s)
                    @php
                        $inicio = $s['agenda']['inicio'] ? $hora($s['agenda']['inicio']) : null;
                        $esHoy = $inicio?->isToday();
                        $enVivo = $s['vivos'] > 0 && $esHoy;
                    @endphp
                    <div @class(['ev-card', 'vivo' => $enVivo]) wire:key="sala-{{ $s['sala'] }}">
                        <div class="ev-cab">
                            <div>
                                <small>{{ $s['sala'] }}</small>
                                <b>{{ $s['titulo'] }}</b>
                            </div>
                            @if ($enVivo)
                                <span class="ev-pill vivo"><i></i>En vivo</span>
                            @elseif ($esHoy)
                                <span class="ev-pill hoy">Hoy</span>
                            @elseif ($inicio)
                                <span class="ev-pill">{{ $inicio->translatedFormat('j M') }}</span>
                            @else
                                <span class="ev-pill">Sin empezar</span>
                            @endif
                        </div>

                        <div class="ev-cifras">
                            <div><b>{{ $s['vivos'] }}</b><span>conectados</span></div>
                            <div><b>{{ $inicio ? $inicio->format('g:i') : '—' }}</b><span>{{ $inicio ? ($inicio->format('a') === 'pm' ? 'p. m.' : 'a. m.') : 'inicio' }}</span></div>
                            <div><b>{{ count($s['agenda']['listos']) }}</b><span>bloques listos</span></div>
                            <div><b>{{ $s['registrados'] }}</b><span>IDs aquí</span></div>
                        </div>

                        @if ($s['votos'])
                            <div class="ev-votos">
                                @foreach ($s['votos'] as $pregunta => $cuentas)
                                    @php($total = array_sum($cuentas))
                                    <div class="ev-voto">
                                        <span>{{ $pregunta }}</span>
                                        <div class="ev-barra" title="{{ collect($cuentas)->map(fn ($n, $i) => $letras[$i] . ': ' . $n)->implode(' · ') }}">
                                            @foreach ($cuentas as $i => $n)
                                                @if ($n > 0)
                                                    <span style="width: {{ $n / $total * 100 }}%">{{ $letras[$i] }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                        <span>{{ $total }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="ev-nota">
                                Sin votaciones{{ $s['tweets'] ? ' · con muro de tweets' : '' }}{{ $s['muro'] ? ' · con muro de proyectos' : '' }}.
                            </p>
                        @endif

                        @if ($s['profe'])
                            <div class="ev-enlace" x-data="{ ok: false }">
                                <span>{{ $s['publico'] }}</span>
                                <button type="button" x-on:click="navigator.clipboard.writeText(@js($s['publico'])); ok = true; setTimeout(() => ok = false, 1500)" x-text="ok ? 'Copiado' : 'Copiar'">Copiar</button>
                            </div>
                            <div class="ev-pie">
                                <x-filament::button tag="a" :href="$s['profe']" target="_blank" size="sm" icon="heroicon-m-play">Abrir como profesor</x-filament::button>
                                <x-filament::button tag="a" :href="$s['publico']" target="_blank" size="sm" color="gray" icon="heroicon-m-users">Participantes</x-filament::button>
                                <div class="ev-mas">
                                    @if ($s['preguntas'])
                                        <button type="button" class="ev-link" wire:click="reiniciarVotos('{{ $s['sala'] }}')" wire:confirm="¿Poner los votos en cero? Los actuales quedan en el historial.">Votos a cero</button>
                                    @endif
                                    <button type="button" class="ev-link" wire:click="reiniciarAgenda('{{ $s['sala'] }}')" wire:confirm="¿Reiniciar la agenda (inicio y bloques cerrados)? Lo de hoy queda en el historial.">Reiniciar agenda</button>
                                </div>
                            </div>
                        @else
                            <p class="ev-nota" style="color: var(--danger-600); padding-bottom: 1rem;">No encontré el módulo de esta presentación en las zonas.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- --------------------------------------------------- historial --}}
        <div>
            <div class="ev-tit">
                <h2>Historial</h2>
                <div style="display: flex; align-items: center; gap: .75rem;">
                    <p>Se guarda solo, un minuto después de cada voto, tweet o bloque.</p>
                    <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-down-on-square" wire:click="guardarAhora">Guardar ahora</x-filament::button>
                </div>
            </div>
            <div class="ev-hist">
                @forelse ($historial as $h)
                    @php($n = $h->cifras())
                    <div class="ev-fila" wire:key="h-{{ $h->id }}">
                        <div class="ev-dia"><b>{{ $h->fecha->format('j') }}</b><span>{{ $h->fecha->translatedFormat('M') }}</span></div>
                        <div>
                            <div class="t">{{ $h->titulo() }}</div>
                            <div class="ev-chips">
                                @if (! empty($h->datos['inicio']))
                                    <span class="ev-chip">desde las {{ $hora($h->datos['inicio'])->format('g:i a') }}</span>
                                @endif
                                @if ($n['bloques'])<span class="ev-chip">{{ $n['bloques'] }} bloques</span>@endif
                                @if ($n['votos'])<span class="ev-chip">{{ $n['votos'] }} votos</span>@endif
                                @if ($n['tweets'])<span class="ev-chip">{{ $n['tweets'] }} tweets</span>@endif
                                @if ($n['muro'])<span class="ev-chip">{{ $n['muro'] }} tarjetas</span>@endif
                                @if ($n['pico'])<span class="ev-chip">hasta {{ $n['pico'] }} conectados</span>@endif
                            </div>
                        </div>
                        <div class="ev-acc">
                            <x-filament::button size="xs" color="gray" :icon="$abierta === $h->id ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'" wire:click="verSesion({{ $h->id }})">{{ $abierta === $h->id ? 'Cerrar' : 'Ver' }}</x-filament::button>
                            @if ($n['tweets'] || $n['muro'] || $n['votos'])
                                <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="descargar({{ $h->id }})">CSV</x-filament::button>
                            @endif
                        </div>
                    </div>
                    @if ($abierta === $h->id)
                        <div class="ev-det" wire:key="d-{{ $h->id }}">
                            @foreach ($h->datos['votos'] ?? [] as $ronda => $preguntas)
                                <div>
                                    <h4>Votos{{ count($h->datos['votos']) > 1 ? ' · ronda ' . $ronda : '' }}</h4>
                                    <div class="ev-votos">
                                        @foreach ($preguntas as $pregunta => $cuentas)
                                            @php($total = max(1, array_sum($cuentas)))
                                            <div class="ev-voto">
                                                <span>{{ $pregunta }}</span>
                                                <div class="ev-barra">
                                                    @foreach ($cuentas as $i => $c)
                                                        @if ($c > 0)<span style="width: {{ $c / $total * 100 }}%">{{ $letras[$i] }} {{ $c }}</span>@endif
                                                    @endforeach
                                                </div>
                                                <span>{{ array_sum($cuentas) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            @if (! empty($h->datos['tweets']))
                                <div>
                                    <h4>Tweets ({{ count($h->datos['tweets']) }})</h4>
                                    <div class="ev-tws">
                                        @foreach (collect($h->datos['tweets'])->sortBy('t') as $tw)
                                            <div class="ev-tw">
                                                <header><b>{{ $tw['nombre'] ?? '' }} <span style="font-family: ui-monospace, monospace; font-weight: 400;">{{ $tw['codigo'] ?? '' }}</span></b><span>{{ isset($tw['t']) ? $hora($tw['t'])->format('g:i a') : '' }}</span></header>
                                                <p>{{ $tw['texto'] ?? '' }}</p>
                                                @if (! empty($tw['hashtag']))<div class="h">{{ $tw['hashtag'] }} · {{ $tw['tono'] ?? '' }}</div>@endif
                                                @if (! empty($tw['respuesta']))<div class="r">{{ $tw['respuesta'] }}</div>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (! empty($h->datos['muro']))
                                <div>
                                    <h4>Muro de proyectos ({{ count($h->datos['muro']) }})</h4>
                                    <div class="ev-tws">
                                        @foreach (collect($h->datos['muro'])->sortBy('t') as $m)
                                            <div class="ev-tw">
                                                <header><b>{{ $m['nombre'] ?? '' }}</b><span>{{ $m['trabajo'] ?? '' }}</span></header>
                                                <p>{{ $m['problema'] ?? '' }}</p>
                                                @if (! empty($m['gustaria']))<div class="r">{{ $m['gustaria'] }}</div>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (! $n['votos'] && ! $n['tweets'] && ! $n['muro'])
                                <p class="ev-nota" style="padding: 0;">Esta sesión solo dejó la agenda.</p>
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="ev-vacio">Todavía no hay sesiones guardadas. Se guardan solas mientras la clase corre.</div>
                @endforelse
            </div>
        </div>

        {{-- --------------------------------------------------- asistente --}}
        <x-filament::section>
            <x-slot name="heading">Asistente del bootcamp</x-slot>
            <x-slot name="description">
                Solo entra quien tenga su ID. Cada ID tiene su tiempo al día, desde su primer mensaje; al otro día vuelve a tenerlo.
            </x-slot>
            <x-slot name="afterHeader">
                <x-filament::badge :color="$asistenteActivo ? 'success' : 'gray'">{{ $asistenteActivo ? 'Prendido' : 'Apagado' }}</x-filament::badge>
            </x-slot>
            <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem;">
                <x-filament::button size="sm" :color="$asistenteActivo ? 'danger' : 'success'" wire:click="alternarAsistente"
                    :icon="$asistenteActivo ? 'heroicon-m-pause' : 'heroicon-m-play'">
                    {{ $asistenteActivo ? 'Apagar' : 'Prender' }}
                </x-filament::button>
                <form wire:submit="guardarMinutos" style="display: flex; align-items: flex-end; gap: .5rem;">
                    <label style="display: grid; gap: .25rem; font-size: .8rem;">
                        <span style="font-weight: 500;">Minutos por ID al día</span>
                        <x-filament::input.wrapper style="width: 7rem;">
                            <x-filament::input type="number" min="5" max="600" wire:model="minutosAsistente" />
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit" size="sm" color="gray">Guardar</x-filament::button>
                </form>
                <x-filament::button tag="a" href="{{ route('asistente.agentic') }}" target="_blank" size="sm" color="gray" icon="heroicon-m-arrow-top-right-on-square" style="margin-left: auto;">
                    Abrir el asistente
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- ----------------------------------------------- participantes --}}
        <x-filament::section>
            <x-slot name="heading">Participantes con ID ({{ $totalParticipantes }})</x-slot>
            <x-slot name="description">
                Quien entra a una presentación que pide nombre recibe un ID de 4 caracteres que sirve en todo el curso.
                Te lo escriben por LinkedIn y aquí ves quién es.
            </x-slot>

            <div class="grid gap-4">
                <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end;">
                    <x-filament::input.wrapper style="flex: 1; min-width: 14rem; max-width: 22rem;" prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="buscar" placeholder="Buscar por ID o nombre" />
                    </x-filament::input.wrapper>
                    <form wire:submit="guardarLinkedin" style="display: flex; gap: .5rem; align-items: flex-end; flex: 1; min-width: 18rem; justify-content: flex-end;">
                        <label style="display: grid; gap: .25rem; font-size: .8rem; flex: 1; max-width: 26rem;">
                            <span style="font-weight: 500;">Tu LinkedIn (lo ven al recibir su ID)</span>
                            <x-filament::input.wrapper>
                                <x-filament::input type="url" wire:model="linkedin" placeholder="https://www.linkedin.com/in/…" />
                            </x-filament::input.wrapper>
                        </label>
                        <x-filament::button type="submit" size="sm" color="gray" icon="heroicon-m-check">Guardar</x-filament::button>
                    </form>
                </div>

                @if ($participantes->isEmpty())
                    <p class="text-sm text-gray-500">{{ $buscar !== '' ? 'Nadie con ese ID o nombre.' : 'Todavía nadie ha recibido su ID.' }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pr-4">ID</th><th class="py-2 pr-4">Nombre</th><th class="py-2 pr-4">Curso</th><th class="py-2 pr-4">Desde</th><th class="py-2 pr-4">Asistente hoy</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($participantes as $p)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4 font-mono font-bold tracking-widest">{{ $p->codigo }}</td>
                                        <td class="py-2 pr-4">{{ $p->nombre }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $p->grupo }} · {{ $p->sala }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $p->created_at->setTimezone($zona)->format('d/m/Y g:i a') }}</td>
                                        @php($uso = $acceso->hoy($p->codigo))
                                        <td class="py-2 pr-4 text-gray-500 whitespace-nowrap">
                                            @if ($uso['empezo'])
                                                <span @class(['text-danger-600 font-semibold' => $uso['agotado']])>{{ $uso['usados'] }} de {{ $minutosAsistente }} min</span> · {{ $uso['mensajes'] }} mensajes
                                                <button type="button" class="ml-1 underline text-xs" wire:click="devolverHora('{{ $p->codigo }}')">devolver</button>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Quitar"
                                                wire:click="quitarParticipante({{ $p->id }})"
                                                wire:confirm="¿Quitar a {{ $p->nombre }} ({{ $p->codigo }})?" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
