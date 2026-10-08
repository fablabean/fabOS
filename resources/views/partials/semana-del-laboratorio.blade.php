{{--
    La semana del laboratorio: días por horas, o espacios por días (§10, §11).

    Se dibuja en tres sitios —el cronograma general, la lista de proyectos y la
    de reservas del backoffice— y por eso vive aquí, con sus estilos: el sitio
    y Filament no comparten hoja de estilos, y dos copias acaban diciendo cosas
    distintas.

    Recibe:
      $s         lo que devuelve OcupacionSemanal::semana(), más espacio, solo y vista
      $livewire  true dentro de un componente Livewire (se navega sin recargar);
                 false en una página normal (se navega con enlaces)
      $base      la dirección de la página, para los enlaces cuando no es Livewire
      $conservar lo que la página ya lleva en la dirección y no debe perderse
--}}
@php
    $livewire = $livewire ?? false;
    $hPx = 2.6; // rem por hora
    $alto = ($s['horaHasta'] - $s['horaDesde']) * $hPx;
    $tz = config('fabos.lab.timezone');
    $hoy = now($tz)->toDateString();
    $estaSemana = now($tz)->startOfWeek()->toDateString();
    $nombresDia = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
    $total = collect($s['bloques'])->flatten(1);

    $filtros = array_filter([
        'espacio' => $s['espacio'],
        'solo'    => $s['solo'] ? 1 : null,
        'vista'   => $s['vista'] === 'espacios' ? 'espacios' : null,
    ]);
    $conservar = array_filter($conservar ?? []);
    $url = fn (array $cambios) => ($base ?? '') . '?'
        . http_build_query(array_filter($cambios + $filtros + ['semana' => $s['desde']->toDateString()] + $conservar)) . '#semana';

    $clasesDe = fn (array $b) => collect([
        $b['delProyecto'] ? 'nuestro' : '',
        $b['estado'] === 'solicitada' ? 'pedida' : '',
        in_array($b['estado'], ['completada', 'no_show']) ? 'pasada' : '',
    ])->filter()->implode(' ');
    // Para el equipo, cada franja abre su reserva en el panel: verla y
    // corregirla ahí mismo, sin ir a buscarla a la lista. A quien no es del
    // equipo no se le enlaza nada: la ficha del panel no es suya.
    $esEquipo = auth()->user()?->hasAnyRole(\App\Models\User::rolesDelEquipo()) ?? false;
    $enlaceDe = fn (array $b) => $esEquipo && ! empty($b['id'])
        ? \App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', ['record' => $b['id']])
        : null;
    $quienDe = fn (array $b) => $b['responsables'] ? implode(', ', $b['responsables']) : ($b['reserva'] ?? 'Sin responsable');
    $tituloDe = fn (array $b) => $b['hora'] . ' · ' . $b['que']
        . ($b['responsables'] ? "\nResponde: " . implode(', ', $b['responsables']) : '')
        . ($b['reserva'] ? "\nReservó: " . $b['reserva'] : '')
        . ($b['para'] ? "\nPara: " . $b['para'] : '')
        . "\n" . $b['estadoTxt'];
@endphp

<div class="sem-wrap" id="semana">
    <div class="sem-barra">
        <div class="sem-nav">
            @if ($livewire)
                <button type="button" wire:click="irA('{{ $s['desde']->copy()->subWeek()->toDateString() }}')">← Anterior</button>
                <strong>{{ $s['desde']->format('d/m') }} – {{ $s['hasta']->format('d/m/Y') }}</strong>
                <button type="button" wire:click="irA('{{ $s['desde']->copy()->addWeek()->toDateString() }}')">Siguiente →</button>
                @if ($s['desde']->toDateString() !== $estaSemana)
                    <button type="button" wire:click="irA('{{ $hoy }}')">Esta semana</button>
                @endif
            @else
                <a href="{{ $url(['semana' => $s['desde']->copy()->subWeek()->toDateString()]) }}">← Anterior</a>
                <strong>{{ $s['desde']->format('d/m') }} – {{ $s['hasta']->format('d/m/Y') }}</strong>
                <a href="{{ $url(['semana' => $s['desde']->copy()->addWeek()->toDateString()]) }}">Siguiente →</a>
                @if ($s['desde']->toDateString() !== $estaSemana)
                    <a href="{{ $url(['semana' => $hoy]) }}">Esta semana</a>
                @endif
            @endif
        </div>

        <div class="sem-vista" role="group" aria-label="Cómo ver la semana">
            @if ($livewire)
                <button type="button" wire:click="$set('vista', 'horas')" class="{{ $s['vista'] === 'horas' ? 'activa' : '' }}">Por horas</button>
                <button type="button" wire:click="$set('vista', 'espacios')" class="{{ $s['vista'] === 'espacios' ? 'activa' : '' }}">Por espacios</button>
            @else
                <a href="{{ $url(['vista' => null]) }}" class="{{ $s['vista'] === 'horas' ? 'activa' : '' }}">Por horas</a>
                <a href="{{ $url(['vista' => 'espacios']) }}" class="{{ $s['vista'] === 'espacios' ? 'activa' : '' }}">Por espacios</a>
            @endif
        </div>

        @if ($livewire)
            <div class="sem-filtro">
                <select wire:model.live="espacio" aria-label="Espacio">
                    <option value="">Todos los espacios</option>
                    @foreach ($s['espacios'] as $esp)
                        <option value="{{ $esp->id }}">{{ $esp->name }}</option>
                    @endforeach
                </select>
                <label class="sem-check">
                    <input type="checkbox" wire:model.live="solo"> Solo de proyectos
                </label>
            </div>
        @else
            <form method="get" action="{{ $base }}#semana" class="sem-filtro">
                <input type="hidden" name="semana" value="{{ $s['desde']->toDateString() }}">
                @foreach ($conservar as $clave => $valor)
                    <input type="hidden" name="{{ $clave }}" value="{{ $valor }}">
                @endforeach
                @if ($s['vista'] === 'espacios') <input type="hidden" name="vista" value="espacios"> @endif
                <select name="espacio" onchange="this.form.submit()" aria-label="Espacio">
                    <option value="">Todos los espacios</option>
                    @foreach ($s['espacios'] as $esp)
                        <option value="{{ $esp->id }}" @selected($s['espacio'] === $esp->id)>{{ $esp->name }}</option>
                    @endforeach
                </select>
                <label class="sem-check">
                    <input type="checkbox" name="solo" value="1" @checked($s['solo']) onchange="this.form.submit()">
                    Solo de proyectos
                </label>
                <noscript><button>Ver</button></noscript>
            </form>
        @endif
    </div>

    @if ($total->isEmpty())
        <p class="sem-vacio">
            Nada reservado esta semana{{ $s['espacio'] ? ' en este espacio' : '' }}{{ $s['solo'] ? ' para proyectos' : '' }}.
        </p>
    @elseif ($s['vista'] === 'espacios')
        {{-- Una fila por espacio: lo que se pregunta es «¿cuándo está libre
             la sala de corte?», y eso se lee de corrido en su fila. --}}
        <div class="sem-scroll">
            <table class="sem-tabla">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($s['dias'] as $dia)
                            <th class="{{ $dia->toDateString() === $hoy ? 'hoy' : '' }}">
                                {{ $nombresDia[$dia->dayOfWeekIso - 1] }} <span>{{ $dia->format('d') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach ($total->pluck('espacio')->unique()->sort() as $espacio)
                    <tr>
                        <th scope="row">{{ $espacio }}</th>
                        @foreach ($s['dias'] as $dia)
                            <td class="{{ $dia->toDateString() === $hoy ? 'hoy' : '' }}">
                                @foreach (collect($s['bloques'][$dia->toDateString()] ?? [])->where('espacio', $espacio)->sortBy('desde') as $b)
                                    @php $href = $enlaceDe($b); @endphp
                                    <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
                                        class="sem-f {{ $clasesDe($b) }}" title="{{ $tituloDe($b) }}{{ $href ? "\nClic para abrir la reserva" : '' }}">
                                        <span class="h">{{ $b['hora'] }}</span>
                                        {{-- La sala ya la dice la fila; se nombra lo que hay dentro. --}}
                                        @if ($b['tipo'] !== 'espacio')
                                            <span class="q">{{ $b['recurso'] ?? $b['que'] }}</span>
                                        @endif
                                        <span class="r">{{ $quienDe($b) }}</span>
                                        @if ($b['para'])<span class="p">{{ $b['para'] }}</span>@endif
                                    </{{ $href ? 'a' : 'div' }}>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        @php
            // Cada día se ensancha con lo que se le cruza: siete impresoras
            // produciendo a la vez no caben en una columna de seis letras.
            // Lo que ya pasó se encoge: se mira para hoy y lo que viene, y los
            // días de atrás no deben empujarlo fuera de la pantalla. Al pasar
            // el cursor por un bloque angosto, se ensancha para leerlo.
            $columnas = collect($s['dias'])->map(function ($dia) use ($s, $hoy) {
                if ($dia->toDateString() < $hoy) {
                    return 'minmax(4.5rem,.6fr)';
                }

                $carriles = collect($s['bloques'][$dia->toDateString()] ?? [])->max('carriles') ?? 1;
                return 'minmax(' . max(6.5, $carriles * 5.2) . 'rem,' . $carriles . 'fr)';
            })->implode(' ');
        @endphp
        {{-- Abre con hoy a la vista, sin tener que desplazarse a buscarlo. --}}
        <div class="sem-scroll" x-data x-init="$nextTick(() => { const h = $el.querySelector('.sem-dia.hoy'); if (h) $el.scrollLeft = Math.max(0, h.offsetLeft - $el.querySelector('.sem-horas').offsetWidth - 8) })">
            <div class="sem" style="grid-template-columns:3rem {{ $columnas }}">
                <div></div>
                @foreach ($s['dias'] as $dia)
                    <div class="sem-dia {{ $dia->toDateString() === $hoy ? 'hoy' : '' }}">
                        {{ $nombresDia[$dia->dayOfWeekIso - 1] }} <span>{{ $dia->format('d') }}</span>
                    </div>
                @endforeach

                <div class="sem-horas" style="height:{{ $alto }}rem">
                    @for ($h = $s['horaDesde']; $h < $s['horaHasta']; $h++)
                        <div style="top:{{ ($h - $s['horaDesde']) * $hPx }}rem">{{ sprintf('%02d', $h) }}:00</div>
                    @endfor
                </div>

                @foreach ($s['dias'] as $dia)
                    <div class="sem-col {{ $dia->toDateString() === $hoy ? 'hoy' : '' }} {{ $dia->toDateString() < $hoy ? 'atras' : '' }}"
                         style="height:{{ $alto }}rem;background-size:100% {{ $hPx }}rem">
                        @foreach ($s['bloques'][$dia->toDateString()] ?? [] as $b)
                            @php
                                $top = ($b['desde'] / 60 - $s['horaDesde']) * $hPx;
                                $altoB = max(1.1, ($b['hasta'] - $b['desde']) / 60 * $hPx);
                                $ancho = 100 / $b['carriles'];
                            @endphp
                            @php $href = $enlaceDe($b); @endphp
                            <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
                                 class="sem-b {{ $clasesDe($b) }}" title="{{ $tituloDe($b) }}{{ $href ? "\nClic para abrir la reserva" : '' }}"
                                 style="top:{{ $top }}rem;height:{{ $altoB }}rem;--alto:{{ $altoB }}rem;
                                        left:calc({{ $b['carril'] * $ancho }}% + 1px);width:calc({{ $ancho }}% - 2px)">
                                <div class="h">{{ $b['hora'] }}</div>
                                <div class="q">{{ $b['que'] }}</div>
                                <div class="r">{{ $quienDe($b) }}</div>
                                @if ($b['para'])<div class="p">{{ $b['para'] }}</div>@endif
                            </{{ $href ? 'a' : 'div' }}>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        @unless ($livewire ?? false)
            {{-- Fuera del panel no hay Alpine: lo mismo, a mano. --}}
            <script>
                (function (el) {
                    const h = el.querySelector('.sem-dia.hoy');
                    if (h) el.scrollLeft = Math.max(0, h.offsetLeft - el.querySelector('.sem-horas').offsetWidth - 8);
                })(document.currentScript.previousElementSibling);
            </script>
        @endunless
    @endif

    <div class="sem-ley">
        <span><i class="sem-m nuestro"></i> De un proyecto</span>
        <span><i class="sem-m"></i> Uso del laboratorio</span>
        <span><i class="sem-m pedida"></i> Solicitada, sin confirmar</span>
        <span><i class="sem-m pasada"></i> Ya pasó</span>
    </div>
    <p class="sem-nota">
        Con cada franja va quién responde: quien asesora o acompaña; si nadie del equipo
        está asignado, quien reservó. Pase el cursor por una franja para ver el detalle.
    </p>
</div>

@once
    {{-- Colores propios con los del sitio como primera opción: en el sitio
         existen --accent, --ground…; en Filament no, y ahí mandan los de
         respaldo, claros u oscuros según el tema del panel. --}}
    <style>
        .sem-wrap {
            --s-accent: var(--accent, #0D6E63); --s-warn: var(--warn, #A45A17);
            --s-ground: var(--ground, #EEEEEA); --s-surface: var(--surface, #FFFFFF);
            --s-ink: var(--ink, #1F2937); --s-soft: var(--ink-soft, #4B5563);
            --s-muted: var(--muted, #6B7280); --s-rule: var(--rule, #D1D5DB);
            color: var(--s-ink);
        }
        .dark .sem-wrap {
            --s-accent: var(--accent, #5CC9B8); --s-warn: var(--warn, #DFA163);
            --s-ground: var(--ground, #1C1F1B); --s-surface: var(--surface, #111310);
            --s-ink: var(--ink, #E9EAE2); --s-soft: var(--ink-soft, #C6C8BC);
            --s-muted: var(--muted, #93968A); --s-rule: var(--rule, #2F342B);
        }
        .sem-barra { display:flex; flex-wrap:wrap; gap:.8rem 1.2rem; align-items:center;
                     justify-content:space-between; margin-bottom:1rem; }
        .sem-nav { display:flex; flex-wrap:wrap; gap:.4rem 1rem; align-items:center; font-size:.9rem; }
        .sem-nav a, .sem-nav button { color:var(--s-accent); text-decoration:underline; background:none;
                                      border:0; padding:0; margin:0; font:inherit; font-weight:400; cursor:pointer; }
        .sem-vista { display:inline-flex; border:1px solid var(--s-rule); border-radius:4px; overflow:hidden; font-size:.85rem; }
        .sem-vista a, .sem-vista button { padding:.35rem .75rem; margin:0; text-decoration:none; color:var(--s-soft);
                                          background:transparent; border:0; border-radius:0; font:inherit; cursor:pointer; }
        .sem-vista .activa { background:var(--s-accent); color:var(--s-surface); font-weight:600; }
        .sem-filtro { display:flex; flex-wrap:wrap; gap:.6rem 1rem; align-items:center; }
        .sem-filtro select { width:auto; min-width:12rem; padding:.4rem 2rem .4rem .6rem; font-size:.88rem;
                             background-color:var(--s-ground); color:var(--s-ink);
                             border:1px solid var(--s-rule); border-radius:4px; }
        .sem-check { display:flex; gap:.4rem; align-items:center; margin:0; font-family:inherit;
                     font-size:.85rem; letter-spacing:0; text-transform:none; color:var(--s-soft); }
        .sem-vacio { margin:0; font-size:.9rem; color:var(--s-muted); }
        .sem-scroll { overflow-x:auto; }

        .sem { display:grid; min-width:40rem; column-gap:2px; }
        .sem-dia { font-size:.72rem; letter-spacing:.1em; text-transform:uppercase; color:var(--s-muted);
                   font-family:ui-monospace,Consolas,monospace; padding:0 0 .4rem .3rem; }
        .sem-dia span { font-size:.95rem; color:var(--s-ink); letter-spacing:0; }
        .sem-dia.hoy, .sem-dia.hoy span { color:var(--s-accent); font-weight:700; }
        .sem-horas { position:relative; }
        .sem-horas div { position:absolute; right:.4rem; transform:translateY(-.45rem);
                         font-size:.66rem; color:var(--s-muted); font-family:ui-monospace,Consolas,monospace; }
        .sem-col { position:relative; background-color:var(--s-ground); border-radius:3px;
                   background-image:linear-gradient(to bottom, var(--s-rule) 1px, transparent 1px); }
        .sem-col.hoy { outline:2px solid color-mix(in srgb, var(--s-accent) 45%, transparent); }

        .sem-b, .sem-f { border-radius:3px; line-height:1.2; color:var(--s-ink);
                         background:color-mix(in srgb, var(--s-muted) 22%, var(--s-surface));
                         border-left:3px solid var(--s-muted); }
        .sem-b { position:absolute; overflow:hidden; padding:.15rem .3rem; font-size:.68rem; cursor:default; }
        /* Al pasar por encima, el bloque crece hasta que se lea entero. */
        .sem-b:hover { z-index:2; height:auto !important; min-height:var(--alto); box-shadow:0 2px 8px rgb(0 0 0 / .25); }
        .sem-col.atras .sem-b:hover { min-width:11rem; }
        .sem-f { font-size:.7rem; padding:.2rem .35rem; margin-bottom:.25rem; }
        /* La franja que abre su reserva: sigue viéndose como franja, no como
           enlace, pero avisa de que se puede pulsar. */
        a.sem-b, a.sem-f { text-decoration:none; cursor:pointer; }
        a.sem-f { display:block; }
        a.sem-b:hover, a.sem-f:hover, a.sem-b:focus-visible, a.sem-f:focus-visible {
            outline:2px solid var(--s-ink); outline-offset:-1px; filter:brightness(.97); z-index:3; }
        .sem-f span { display:block; }
        .sem-b .h, .sem-f .h { font-family:ui-monospace,Consolas,monospace; font-size:.62rem; color:var(--s-soft); }
        .sem-b .q, .sem-f .q { font-weight:600; }
        .sem-b .r, .sem-f .r { color:var(--s-soft); }
        .sem-b .p, .sem-f .p { color:var(--s-muted); font-style:italic; }
        .sem-b.nuestro, .sem-f.nuestro { background:color-mix(in srgb, var(--s-accent) 26%, var(--s-surface));
                                         border-left-color:var(--s-accent); }
        .sem-b.pedida, .sem-f.pedida { background:repeating-linear-gradient(135deg, transparent 0 5px,
                                           color-mix(in srgb, var(--s-warn) 14%, transparent) 5px 10px), var(--s-surface);
                                       border-left:3px dashed var(--s-warn); }
        .sem-b.nuestro.pedida, .sem-f.nuestro.pedida { border-left-color:var(--s-accent); }
        .sem-b.pasada, .sem-f.pasada { opacity:.5; }

        .sem-tabla { width:100%; min-width:44rem; border-collapse:separate; border-spacing:2px; table-layout:fixed; }
        .sem-tabla th, .sem-tabla td { vertical-align:top; padding:.3rem; border:0; background:none; }
        .sem-tabla thead th { font-size:.72rem; letter-spacing:.1em; text-transform:uppercase; color:var(--s-muted);
                              font-family:ui-monospace,Consolas,monospace; font-weight:400; text-align:left; }
        .sem-tabla thead th span { font-size:.95rem; color:var(--s-ink); letter-spacing:0; }
        .sem-tabla thead th.hoy, .sem-tabla thead th.hoy span { color:var(--s-accent); font-weight:700; }
        .sem-tabla thead th:first-child, .sem-tabla tbody th { width:9rem; }
        .sem-tabla tbody th { font-size:.82rem; text-align:left; font-weight:600; font-family:inherit;
                              text-transform:none; letter-spacing:0; color:var(--s-ink); }
        .sem-tabla td { background:var(--s-ground); border-radius:3px; }
        .sem-tabla td.hoy { outline:2px solid color-mix(in srgb, var(--s-accent) 45%, transparent); }

        .sem-ley { display:flex; flex-wrap:wrap; gap:.4rem 1.2rem; margin-top:.9rem; font-size:.8rem; color:var(--s-soft); }
        .sem-ley span { display:inline-flex; gap:.35rem; align-items:center; white-space:nowrap; }
        .sem-m { display:inline-block; width:.9rem; height:.9rem; border-radius:2px;
                 background:color-mix(in srgb, var(--s-muted) 22%, var(--s-surface)); border-left:3px solid var(--s-muted); }
        .sem-m.nuestro { background:color-mix(in srgb, var(--s-accent) 26%, var(--s-surface)); border-left-color:var(--s-accent); }
        .sem-m.pedida { background:color-mix(in srgb, var(--s-warn) 14%, var(--s-surface)); border-left:3px dashed var(--s-warn); }
        .sem-m.pasada { opacity:.5; }
        .sem-nota { margin:.5rem 0 0; font-size:.8rem; color:var(--s-muted); }
    </style>
@endonce
