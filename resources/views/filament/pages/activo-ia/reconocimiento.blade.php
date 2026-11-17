{{--
    Lo que el servidor del FabLab reconoció en una foto, y con qué se parece.

    Nunca se enlaza solo: se sugiere, y la persona elige «Es este» o «Es nuevo».
--}}
@php
    $pendiente = $p['estado'] === 'pendiente';
    $vio = $p['propuesta'] ?? null;
    $puedeElegir = fn (array $c) => $c['tipo'] === 'activo' ? $permisos['trasladar_activo'] : $permisos['entrada_insumo'];
    $porcentaje = fn (float $s) => round($s * 100) . ' %';
    $urlDe = fn (array $c) => $c['tipo'] === 'activo'
        ? \App\Filament\Resources\Assets\AssetResource::getUrl('edit', ['record' => $c['id']])
        : \App\Filament\Resources\Supplies\SupplyResource::getUrl('edit', ['record' => $c['id']]);
    $detalle = fn (array $c) => collect([
        $c['ficha']['area'] ?? null, $c['ficha']['espacio'] ?? null, $c['ficha']['ubicacion'] ?? null,
        ($c['ficha']['marca'] ?? null) ? trim(($c['ficha']['marca'] ?? '') . ' ' . ($c['ficha']['modelo'] ?? '')) : null,
        ($c['ficha']['placa'] ?? null) ? 'placa ' . $c['ficha']['placa'] : null,
        isset($c['ficha']['existencia']) ? 'hay ' . $c['ficha']['existencia'] . ' ' . $c['ficha']['unidad'] : null,
    ])->filter()->join(' · ');
    $primero = $p['mismo'] ? ($p['por_foto'][0] ?? null) : null;
    $otrosPorFoto = $p['mismo'] ? array_slice($p['por_foto'], 1) : $p['por_foto'];
@endphp

{{-- Lo que ya salió en otra foto, desde otro ángulo: una línea, no una tarjeta entera. --}}
@if (in_array($p['estado'], ['repetida', 'angulo_guardado', 'parte'], true))
    @php($de = $p['repetida_de'] ?? [])
    @php($pieza = $p['parte_de'] ?? null)
    <div class="tarjeta repetida" wire:key="tarjeta-{{ $clave }}">
        <div class="fila" style="gap:.7rem;flex-wrap:nowrap">
            @if ($p['objeto']['miniatura'] ?? null)
                <img class="recorte" style="height:3.5rem" src="{{ $p['objeto']['miniatura'] }}" alt="Recorte del objeto">
            @endif
            <div style="min-width:0;flex:1">
                <h4 style="font-size:.85rem">{{ ($p['objeto']['foto'] ?? null) ? 'Foto ' . $p['objeto']['foto'] . ' · ' : '' }}Objeto {{ $p['objeto']['indice'] ?? '?' }} · {{ $p['propuesta']['nombre'] ?? 'objeto' }}</h4>
                <p class="gris" style="font-size:.8rem">
                    @if ($pieza && $p['estado'] === 'angulo_guardado')
                        ✓ Se guardó con el objeto {{ $pieza['objeto'] ?? '?' }}, como parte del mismo equipo.
                    @elseif ($p['estado'] === 'parte')
                        Es parte del objeto {{ $pieza['objeto'] ?? '?' }}: una sola pieza, no se registra aparte.
                        Cuando confirmes aquel, esta foto se guarda también con él.
                    @elseif ($p['estado'] === 'angulo_guardado')
                        ✓ Se guardó como otro ángulo del objeto {{ $de['objeto'] ?? '?' }} de la foto {{ $de['foto'] ?? '?' }}: ayuda a reconocerlo.
                    @else
                        Es el objeto {{ $de['objeto'] ?? '?' }} de la foto {{ $de['foto'] ?? '?' }}, visto desde otro ángulo: no lo cuento dos veces.
                        Cuando confirmes aquel, esta foto se guarda también como otro ángulo.
                    @endif
                </p>
            </div>
            @if ($p['estado'] === 'repetida')
                <x-filament::button size="xs" color="gray" wire:click="esOtro('{{ $clave }}')" title="No es el mismo: contarlo aparte">No, es otro</x-filament::button>
            @elseif ($p['estado'] === 'parte')
                <x-filament::button size="xs" color="gray" wire:click="noEsUnaPieza('{{ $clave }}')" title="Son dos equipos: contarlo aparte">No, va aparte</x-filament::button>
            @endif
        </div>
    </div>
@else
<div class="tarjeta {{ $p['estado'] === 'vinculada' ? 'guardada' : $p['estado'] }}" wire:key="tarjeta-{{ $clave }}">
    @php($obj = $p['objeto'] ?? [])
    <div class="fila entre">
        <div class="fila" style="gap:.7rem;flex-wrap:nowrap;min-width:0">
            @if ($obj['miniatura'] ?? null)
                <span x-data="{ grande: false }" style="flex:none">
                    <img class="recorte" src="{{ $obj['miniatura'] }}" alt="Recorte del objeto" title="Clic para verla en grande" x-on:click="grande = true">
                    <template x-teleport="body">
                        <div x-cloak x-show="grande" x-transition.opacity x-on:click="grande = false" x-on:keydown.escape.window="grande = false" class="aia-lupa">
                            <img src="{{ $obj['miniatura'] }}" alt="Recorte del objeto, en grande">
                        </div>
                    </template>
                </span>
            @endif
            <div style="min-width:0">
                <h4>
                    @if (($obj['total'] ?? 1) > 1 || ($obj['foto'] ?? null))
                        {{ ($obj['foto'] ?? null) ? 'Foto ' . $obj['foto'] . ' · ' : '' }}Objeto {{ $obj['indice'] ?? 1 }} de {{ $obj['total'] ?? 1 }}{{ filled($obj['posicion'] ?? null) ? ' · ' . $obj['posicion'] : '' }}

                        @if ($obj['cantidad'] ?? null)
                            <span class="pastilla media" title="Se ven {{ $obj['cantidad'] }} iguales en la foto">×{{ $obj['cantidad'] }}</span>
                        @endif
                    @else
                        Reconocimiento por foto
                    @endif
                </h4>
                @if (($obj['total'] ?? 1) > 1 && filled($p['resumen'] ?? null))
                    <p class="gris" style="font-size:.8rem;margin-top:.15rem">{{ $p['resumen'] }}</p>
                @endif
                @if ($obj['cortado'] ?? false)
                    <p class="gris" style="font-size:.76rem;color:rgb(217 119 6)">Sale cortado en el borde de la foto: los datos pueden estar incompletos.</p>
                @endif
                @if ($obj['manual'] ?? false)
                    <p class="gris" style="font-size:.76rem">Lo agregaste a mano porque la IA no lo vio: búscalo abajo o regístralo como nuevo.</p>
                @endif
                @if ($obj['tapado'] ?? false)
                    <p class="gris" style="font-size:.76rem;color:rgb(217 119 6)">Está casi tapado: no se adivina cuál es y su recorte no se guarda como foto. Si te importa, búscalo abajo o tómale una foto aparte.</p>
                @endif
                @if ($obj['igual_a'] ?? null)
                    <p class="gris" style="font-size:.76rem">Mismo producto que el objeto {{ $obj['igual_a'] }}: su etiqueta lo confirma.</p>
                @endif
                @if ($obj['incluye'] ?? null)
                    <p class="gris" style="font-size:.76rem;margin:.15rem 0 0">Incluye: {{ implode(', ', $obj['incluye']) }} (partes de la misma máquina).</p>
                @endif
                @if (($obj['pieza_de'] ?? null) && $p['estado'] === 'pendiente')
                    <div class="fila" style="gap:.5rem;margin-top:.3rem;flex-wrap:wrap">
                        <p class="gris" style="font-size:.76rem;color:rgb(217 119 6);margin:0">
                            Puede ser una sola pieza con el objeto {{ $obj['pieza_de'] }} (están pegados y parecen del mismo equipo). Si es así, no lo registres aparte.
                        </p>
                        <x-filament::button size="xs" color="warning" wire:click="esUnaPieza('{{ $clave }}')" title="Registrarlo junto con el otro">Sí, es una sola pieza</x-filament::button>
                    </div>
                @endif
                @if (($p['quizas_de'] ?? null) && $p['estado'] === 'pendiente')
                    <div class="fila" style="gap:.5rem;margin-top:.3rem;flex-wrap:wrap">
                        <p class="gris" style="font-size:.76rem;color:rgb(217 119 6);margin:0">
                            Puede ser el objeto {{ $p['quizas_de']['objeto'] }} de la foto {{ $p['quizas_de']['foto'] }}, visto desde otro ángulo. Si es ese, no lo registres otra vez.
                        </p>
                        <x-filament::button size="xs" color="warning" wire:click="esElMismo('{{ $clave }}')" title="Contarlo una sola vez">Sí, es el mismo</x-filament::button>
                    </div>
                @endif
                @if ($obj['parecido_a'] ?? null)
                    <p class="gris" style="font-size:.76rem;color:rgb(217 119 6)">Se parece al objeto {{ $obj['parecido_a'] }}, pero no está detrás de él: puede ser otro producto. Revisa la etiqueta.</p>
                @endif
            </div>
        </div>
        <div class="chips">
            @if (filled($vio['confianza'] ?? null))
                <span class="chip {{ in_array($vio['confianza'], ['alta', 'media', 'baja'], true) ? $vio['confianza'] : '' }}">Confianza {{ $vio['confianza'] }}</span>
            @endif
            <span class="chip">Servidor del FabLab{{ $p['segundos'] ? ' · ' . round($p['segundos']) . ' s' : '' }}</span>
        </div>
    </div>

    {{-- Ya la conoce: el caso feliz, arriba y grande. --}}
    @if ($primero)
        <div class="reconocido">
            <div>
                <span class="gris" style="font-size:.75rem">Es el mismo objeto de una foto guardada</span>
                <div class="reconocido-nombre">
                    <a href="{{ $urlDe($primero) }}" target="_blank">{{ $primero['nombre'] }}</a>
                    <span class="pastilla alta">{{ $porcentaje($primero['similitud']) }}</span>
                </div>
                <span class="gris">{{ $detalle($primero) }}</span>
            </div>
            @if ($pendiente)
                <div class="fila" style="flex:none">
                    {{-- Insumo que ya existe: se suma, no se crea otro. --}}
                    @if ($primero['tipo'] === 'insumo' && $permisos['entrada_insumo'])
                        <x-filament::button size="sm" icon="heroicon-m-plus" wire:click="sumarAlExistente('{{ $clave }}', '{{ $primero['clave'] }}')" title="No crea otro: suma a la existencia del que ya está">
                            Ya lo teníamos: sumar
                        </x-filament::button>
                    @endif
                    @if ($puedeElegir($primero))
                        <x-filament::button size="sm" :color="$primero['tipo'] === 'insumo' ? 'gray' : 'primary'" icon="heroicon-m-check" wire:click="esEste('{{ $clave }}', '{{ $primero['clave'] }}')">
                            Sí, es este
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($vio)
        @if (filled($vio['error'] ?? null))
            <div class="aviso ambar">El servidor no pudo decir qué es ({{ $vio['error'] }}), pero la foto sí se puede comparar.</div>
        @else
            <div class="lo-que-vio">
                @foreach (['nombre' => 'Qué es', 'tipo' => 'Tipo', 'categoria' => 'Categoría', 'marca' => 'Marca', 'modelo' => 'Modelo', 'texto_visible' => 'Texto que se lee'] as $campo => $etiqueta)
                    <div>
                        <span>{{ $etiqueta }}</span>
                        <b @class(['vacio' => blank($vio[$campo] ?? null)])>{{ $vio[$campo] ?? 'no se ve' }}</b>
                    </div>
                @endforeach
            </div>
            @if (filled($vio['descripcion'] ?? null))
                <p class="gris">{{ $vio['descripcion'] }}</p>
            @endif
        @endif
    @endif

    {{-- Las sugerencias, solo mientras falta decidir: ya resuelta, la tarjeta muestra su resultado. --}}
    @if ($pendiente)
    @foreach ([
        ['Se parece por foto', $otrosPorFoto, 'foto'],
        ['Ya está en el inventario y se parece', $p['por_texto'], 'texto'],
        ['Resultados de «' . ($p['busco'] ?? '') . '»', $p['por_busqueda'] ?? [], 'busqueda'],
    ] as [$titulo, $lista, $por])
        @if ($lista)
            <div class="candidatos">
                <strong style="font-size:.8rem">{{ $titulo }}</strong>
                @foreach ($lista as $c)
                    @php($probable = (bool) ($c['probable'] ?? false))
                    <div @class(['candidato', 'probable' => $probable]) wire:key="{{ $clave }}-{{ $por }}-{{ $c['clave'] }}">
                        <div style="min-width:0">
                            @if ($probable)
                                <span class="pastilla alta" style="margin-bottom:.2rem;display:inline-block">Más probable</span><br>
                            @endif
                            <a href="{{ $urlDe($c) }}" target="_blank">{{ $c['nombre'] }}</a>
                            <span class="chip" style="margin-left:.3rem">{{ $c['tipo'] === 'activo' ? 'Activo' : 'Insumo' }}</span>
                            <div class="gris" style="font-size:.76rem">{{ $detalle($c) ?: 'Sin más datos' }}</div>
                            @if (! empty($c['razones']))
                                <div class="chips" style="margin-top:.25rem">
                                    @foreach ($c['razones'] as $razon)
                                        <span class="chip">{{ $razon }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="fila" style="flex:none">
                            @if (isset($c['similitud']))
                                <span class="pastilla {{ $probable ? 'alta' : ($por === 'foto' ? 'media' : '') }}" title="{{ $por === 'foto' ? 'Parecido entre fotos' : 'Parecido por nombre, marca y área' }}">{{ $porcentaje($c['similitud']) }}</span>
                            @endif
                            @if ($pendiente && $c['tipo'] === 'insumo' && $permisos['entrada_insumo'])
                                <x-filament::button size="xs" :color="$probable ? 'primary' : 'gray'" icon="heroicon-m-plus" wire:click="sumarAlExistente('{{ $clave }}', '{{ $c['clave'] }}')" title="Ya está inventariado: suma a su existencia en vez de crear otro">Ya lo teníamos: sumar</x-filament::button>
                            @endif
                            @if ($pendiente && $puedeElegir($c))
                                <x-filament::button size="xs" :color="$probable && $c['tipo'] !== 'insumo' ? 'primary' : 'gray'" wire:click="esEste('{{ $clave }}', '{{ $c['clave'] }}')" title="Solo guarda la foto para reconocerlo, sin cambiar la existencia">Es este</x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endforeach
    @endif

    @php($hayInsumo = collect($p['por_foto'])->merge($p['por_texto'])->merge($p['por_busqueda'] ?? [])->contains('tipo', 'insumo'))
    @if ($pendiente && $hayInsumo && $permisos['entrada_insumo'])
        <label class="fila gris" style="font-size:.8rem">
            «Ya lo teníamos: sumar» agrega a la existencia
            <input type="number" min="0" step="any" wire:model="propuestas.{{ $clave }}.cantidad_a_sumar"
                   style="width:4.5rem;border-radius:.45rem;border:1px solid rgba(128,128,128,.35);background:transparent;padding:.2rem .4rem">
            en vez de crear otro registro.
        </label>
    @endif

    @if ($pendiente)
        <form class="buscar-a-mano" wire:submit="buscarEnInventario('{{ $clave }}')">
            <x-filament::icon icon="heroicon-m-magnifying-glass" />
            <input type="search" wire:model="propuestas.{{ $clave }}.busqueda" placeholder="¿No está en la lista? Búscalo por nombre, marca o placa…">
            <x-filament::button type="submit" size="xs" color="gray" wire:loading.attr="disabled" wire:target="buscarEnInventario('{{ $clave }}')">Buscar</x-filament::button>
        </form>
        @if (filled($p['busco'] ?? null) && empty($p['por_busqueda']))
            <p class="gris" style="font-size:.8rem">No encontré nada con «{{ $p['busco'] }}».</p>
        @endif
    @endif

    @if ($p['aviso_texto'] ?? null)
        <p class="gris">{{ $p['aviso_texto'] }}</p>
    @endif

    @if ($p['error'])
        <div class="aviso rojo">{{ $p['error'] }}</div>
    @endif

    @if ($pendiente)
        <div class="fila entre" style="border-top:1px solid var(--aia-borde);padding-top:.75rem">
            <span class="gris" style="font-size:.78rem">
                {{ $primero ? '¿No es ese?' : 'Si no es ninguno de estos:' }}
            </span>
            @if ($permisos['crear_activo'] || $permisos['crear_insumo'])
                <x-filament::button size="sm" :color="$primero ? 'gray' : 'primary'" icon="heroicon-m-plus" wire:click="esNuevo('{{ $clave }}')">
                    Es nuevo: preparar registro
                </x-filament::button>
            @endif
        </div>
    @elseif ($p['estado'] === 'sumada')
        <div class="aviso verde">
            ✓ Sumado a «{{ $p['resultado']['nombre'] }}», que ya estaba inventariado: ahora hay <strong>{{ $p['resultado']['existencia'] }}</strong>.
            @if ($p['resultado']['url'])
                <a href="{{ $p['resultado']['url'] }}" style="text-decoration:underline">Ver ficha</a>.
            @endif
            No se creó otro registro.
        </div>
    @elseif ($p['estado'] === 'vinculada')
        <div class="aviso verde">
            ✓ {{ ($obj['tapado'] ?? false) ? 'Es' : 'Foto guardada con' }} «{{ $p['resultado']['nombre'] }}».
            @if ($p['resultado']['url'])
                <a href="{{ $p['resultado']['url'] }}" style="text-decoration:underline">Ver ficha</a>.
            @endif
            {{ ($obj['tapado'] ?? false) ? 'Quedó anotado; como estaba tapado, su foto no se guardó.' : 'La próxima foto de este objeto se reconocerá sola. Una de otro ángulo ayuda.' }}
        </div>
        @if ($p['completar'] ?? null)
            <div class="aviso" style="margin-top:.5rem">
                <p style="margin:0 0 .4rem">
                    A la ficha de «{{ $p['completar']['nombre'] }}» le falta esto, y en la foto se leyó. ¿Lo guardo? Así la próxima foto sabe cuál es.
                    Corrige o borra lo que no esté bien.
                </p>
                <div class="fila" style="gap:.5rem;flex-wrap:wrap;align-items:flex-end">
                    @foreach (['marca' => 'Marca', 'modelo' => 'Modelo', 'placa' => 'Placa'] as $campo => $etiqueta)
                        @if (array_key_exists($campo, $p['completar']['datos']))
                            <label style="font-size:.78rem">{{ $etiqueta }}<br>
                                <input type="text" class="fi-input" style="width:9rem" wire:model.blur="propuestas.{{ $clave }}.completar.datos.{{ $campo }}">
                            </label>
                        @endif
                    @endforeach
                    <x-filament::button size="xs" wire:click="completarFicha('{{ $clave }}')">Guardar en la ficha</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="$set('propuestas.{{ $clave }}.completar', null)">No, gracias</x-filament::button>
                </div>
            </div>
        @elseif ($p['completada'] ?? null)
            <p class="gris" style="font-size:.78rem;margin-top:.4rem">✓ {{ $p['completada'] }}</p>
        @endif
    @elseif ($p['estado'] === 'nuevo')
        <p class="gris">Lo marcaste como nuevo: revisa el registro de abajo.</p>
    @endif
</div>
@endif
