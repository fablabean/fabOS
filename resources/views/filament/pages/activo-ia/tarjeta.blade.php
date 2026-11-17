{{--
    Una propuesta de Activo IA, lista para revisar.

    Los campos que la IA no pudo leer, o leyó con poca certeza, salen en ámbar:
    son los que hay que mirar antes de confirmar. Todo se puede corregir aquí
    mismo; lo que se guarda es lo que quede en la tarjeta, no lo que propuso.
--}}
@php
    $pendiente = $p['estado'] === 'pendiente';
    $base = "propuestas.{$clave}.datos";

    $permiso = match (true) {
        $p['clase'] === 'registro' && $p['tipo'] === 'activo' => $permisos['crear_activo'],
        $p['clase'] === 'registro' => $permisos['crear_insumo'],
        $p['clase'] === 'traslado' => $permisos['trasladar_activo'],
        $p['clase'] === 'entrada' => $permisos['entrada_insumo'],
        default => false,
    };

    // Qué tan seguro está de cada campo, y de dónde lo sacó.
    $evidencia = collect($p['evidencia'] ?? [])->keyBy(fn ($e) => str_replace(['_id', ' '], ['', '_'], mb_strtolower($e['campo'] ?? '')));

    $nota = function (string $campo) use ($p, $evidencia) {
        $clave = str_replace('_id', '', $campo);
        $e = $evidencia->get($clave);
        $original = $p['original'][$campo] ?? null;

        if ($original === null || $original === '') {
            return ['revisar' => true, 'texto' => 'No se leyó: complétalo si lo sabes.'];
        }

        if ($e) {
            return [
                'revisar' => ($e['certeza'] ?? 'alta') !== 'alta',
                'texto' => 'Certeza ' . ($e['certeza'] ?? '?') . ' · ' . ($e['de_donde'] ?? ''),
            ];
        }

        return ['revisar' => false, 'texto' => null];
    };

    $texto = function (string $campo, string $etiqueta) use ($base, $nota, $pendiente) {
        $n = $nota($campo);

        return view('filament.pages.activo-ia.campo', [
            'modelo' => "{$base}.{$campo}", 'etiqueta' => $etiqueta, 'nota' => $n, 'pendiente' => $pendiente,
            'tipo' => 'text', 'opciones' => null, 'lista' => null,
        ])->render();
    };

    $lista = function (string $campo, string $etiqueta, array $opciones, bool $ancho = false) use ($base, $nota, $pendiente) {
        $n = $nota($campo);

        return view('filament.pages.activo-ia.campo', [
            'modelo' => "{$base}.{$campo}", 'etiqueta' => $etiqueta, 'nota' => $n, 'pendiente' => $pendiente,
            'tipo' => 'select', 'opciones' => $opciones, 'lista' => null, 'ancho' => $ancho,
        ])->render();
    };
@endphp

<div class="tarjeta {{ $p['estado'] }}" wire:key="tarjeta-{{ $clave }}">
    @if ($p['clase'] === 'registro')
        <div class="fila entre">
            <h4>Propuesta: registrar {{ $p['tipo'] === 'activo' ? 'un activo' : 'un insumo' }}</h4>
            <div class="chips">
                <span class="chip">{{ $p['tipo'] === 'activo' ? 'Activo' : 'Insumo' }}</span>
                <span class="chip {{ $p['confianza'] }}">Confianza {{ $p['confianza'] }}</span>
            </div>
        </div>

        @if (filled($p['razon']))
            <p class="gris">{{ $p['razon'] }}</p>
        @endif

        @if (filled($p['donde_va'] ?? null))
            <div class="donde-va">
                <x-filament::icon icon="heroicon-o-map-pin" />
                <span>{{ $p['donde_va'] }} <span class="gris">Cámbialo abajo si va en otro sitio.</span></span>
            </div>
        @endif

        <div class="campos">
            {!! $texto('nombre', 'Nombre') !!}

            @if ($p['tipo'] === 'activo')
                {!! $lista('tipo_de_activo', 'Tipo de activo', $cat['tipos']) !!}
                {!! $lista('area_id', 'Área *', $cat['areas']) !!}
                {!! $lista('espacio_id', 'Espacio (dónde se usa)', $cat['espacios']) !!}
                {!! $lista('ubicacion_id', 'Ubicación (dónde se guarda)', $cat['ubicaciones'], true) !!}
                {!! $texto('marca', 'Marca') !!}
                {!! $texto('modelo', 'Modelo') !!}
                {!! $texto('serie', 'Número de serie') !!}
                {!! $texto('placa', 'Placa') !!}
            @else
                @php($n = $nota('unidad'))
                <div class="campo {{ $n['revisar'] ? 'revisar' : '' }}">
                    <label>Unidad de medida *</label>
                    <input type="text" list="aia-unidades-{{ $clave }}" wire:model="{{ $base }}.unidad" @disabled(! $pendiente)>
                    <datalist id="aia-unidades-{{ $clave }}">
                        @foreach ($cat['unidades'] as $u) <option value="{{ $u }}"></option> @endforeach
                    </datalist>
                    @if ($n['texto']) <small>{{ $n['texto'] }}</small> @endif
                </div>
                @php($n = $nota('cantidad'))
                <div class="campo {{ $n['revisar'] ? 'revisar' : '' }}">
                    <label>Existencia inicial</label>
                    <input type="number" step="any" min="0" wire:model="{{ $base }}.cantidad" @disabled(! $pendiente)>
                    <small>Entra como movimiento de entrada. Vacío = 0.</small>
                </div>
                {!! $texto('sku', 'Código / referencia') !!}
                {!! $lista('area_id', 'Área', $cat['areas']) !!}
                {!! $lista('categoria_id', 'Categoría', $cat['categorias']) !!}
                {!! $lista('ubicacion_id', 'Ubicación (dónde se guarda)', $cat['ubicaciones'], true) !!}
            @endif
        </div>

        <div class="campo">
            <label>Descripción</label>
            <input type="text" wire:model="{{ $base }}.descripcion" @disabled(! $pendiente)>
        </div>

        @if ($p['no_se_pudo_leer'])
            <div>
                <strong style="font-size:.8rem">No se pudo leer</strong>
                <ul class="lista">@foreach ($p['no_se_pudo_leer'] as $x) <li>{{ $x }}</li> @endforeach</ul>
            </div>
        @endif

        @if ($p['preguntas'])
            <div class="aviso ambar">
                <strong>Antes de confirmar</strong>
                <ul class="lista">@foreach ($p['preguntas'] as $x) <li>{{ $x }}</li> @endforeach</ul>
            </div>
        @endif

        @foreach ($p['avisos'] ?? [] as $x)
            <div class="aviso ambar">{{ $x }}</div>
        @endforeach

        @if ($p['duplicados'])
            @php($hayFuerte = collect($p['duplicados'])->contains('fuerte', true))
            <div class="dups">
                <strong style="font-size:.85rem">{{ $hayFuerte ? '⚠ Parece que ya existe' : 'Registros parecidos en el inventario' }}</strong>
                @foreach ($p['duplicados'] as $d)
                    @php($url = $p['tipo'] === 'activo'
                        ? \App\Filament\Resources\Assets\AssetResource::getUrl('edit', ['record' => $d['id']])
                        : \App\Filament\Resources\Supplies\SupplyResource::getUrl('edit', ['record' => $d['id']]))
                    <div class="dup {{ $d['fuerte'] ? 'fuerte' : '' }}">
                        <a href="{{ $url }}" target="_blank">{{ $d['nombre'] }}</a>
                        <span class="gris">{{ $d['motivo'] }}</span>
                        <span class="gris">
                            {{ collect([$d['ficha']['area'] ?? null, $d['ficha']['espacio'] ?? null, $d['ficha']['ubicacion'] ?? null,
                                ($d['ficha']['serie'] ?? null) ? 'serie ' . $d['ficha']['serie'] : null,
                                ($d['ficha']['placa'] ?? null) ? 'placa ' . $d['ficha']['placa'] : null,
                                isset($d['ficha']['existencia']) ? 'hay ' . $d['ficha']['existencia'] . ' ' . $d['ficha']['unidad'] : null])->filter()->join(' · ') }}
                        </span>
                        @if ($pendiente && $p['tipo'] === 'insumo' && $permisos['entrada_insumo'])
                            <x-filament::button size="xs" icon="heroicon-m-plus" wire:click="sumarAlExistente('{{ $clave }}', 'insumo:{{ $d['id'] }}')" title="No crea otro registro: suma la existencia inicial de este borrador (o 1) al que ya está">
                                Ya lo teníamos: sumar en vez de crear
                            </x-filament::button>
                        @endif
                    </div>
                @endforeach
                @if ($hayFuerte && $pendiente)
                    <label class="fila" style="font-size:.85rem">
                        <input type="checkbox" wire:model="propuestas.{{ $clave }}.confirmo_distinto">
                        Lo revisé: es otro, no un duplicado.
                    </label>
                @endif
            </div>
        @endif

    @elseif ($p['clase'] === 'traslado')
        <h4>Propuesta: trasladar «{{ $p['activo']['nombre'] }}»</h4>
        <div class="antes-despues">
            <div class="caja">
                <span class="gris">Ahora</span><br>
                {{ $p['activo']['espacio'] ?? 'Sin espacio' }}<br>
                <span class="gris">{{ $p['activo']['ubicacion'] ?? 'Sin ubicación' }}</span>
            </div>
            <span class="flecha">→</span>
            <div class="campos" style="grid-template-columns:1fr">
                {!! $lista('espacio_id', 'Espacio nuevo', $cat['espacios']) !!}
                {!! $lista('ubicacion_id', 'Ubicación nueva', $cat['ubicaciones']) !!}
            </div>
        </div>
        <div class="campo">
            <label>Motivo</label>
            <input type="text" wire:model="{{ $base }}.motivo" @disabled(! $pendiente)>
        </div>

    @elseif ($p['clase'] === 'entrada')
        <h4>Propuesta: entrada de «{{ $p['insumo']['nombre'] }}»</h4>
        <div class="antes-despues">
            <div class="caja">
                <span class="gris">Existencia actual</span><br>
                <strong>{{ $p['insumo']['existencia'] }}</strong> {{ $p['insumo']['unidad'] }}
            </div>
            <span class="flecha">+</span>
            <div class="campos" style="grid-template-columns:1fr">
                <div class="campo">
                    <label>Cantidad que entra ({{ $p['insumo']['unidad'] }})</label>
                    <input type="number" step="any" min="0" wire:model.live.debounce.400ms="{{ $base }}.cantidad" @disabled(! $pendiente)>
                </div>
            </div>
        </div>
        <p class="gris">
            Quedaría en <strong>{{ rtrim(rtrim(number_format((float) $p['insumo']['existencia'] + (float) ($p['datos']['cantidad'] ?? 0), 3, ',', '.'), '0'), ',') }}</strong>
            {{ $p['insumo']['unidad'] }}. Se registra como movimiento de entrada, con tu nombre.
        </p>
        <div class="campo">
            <label>De dónde sale (compra, donación, conteo…) *</label>
            <input type="text" wire:model="{{ $base }}.motivo" @disabled(! $pendiente)>
        </div>
    @endif

    @if ($p['error'])
        <div class="aviso rojo">{{ $p['error'] }}</div>
    @endif

    @if ($p['estado'] === 'pendiente')
        <div class="fila">
            @if ($permiso)
                <x-filament::button size="sm" icon="heroicon-o-check" wire:click="confirmar('{{ $clave }}')" wire:loading.attr="disabled" wire:target="confirmar('{{ $clave }}')">
                    Confirmar y guardar
                </x-filament::button>
            @else
                <span class="gris">Tu rol puede ver esta propuesta, pero no guardarla.</span>
            @endif
            <x-filament::button size="sm" color="gray" wire:click="descartar('{{ $clave }}')">Descartar</x-filament::button>
        </div>
    @elseif ($p['estado'] === 'sumada')
        <div class="aviso verde">
            ✓ No se creó otro: se sumó a «{{ $p['resultado']['nombre'] }}», que ya estaba inventariado. Ahora hay <strong>{{ $p['resultado']['existencia'] }}</strong>.
            @if ($p['resultado']['url'])
                <a href="{{ $p['resultado']['url'] }}" style="text-decoration:underline">Ver ficha</a>.
            @endif
        </div>
    @elseif ($p['estado'] === 'guardada')
        <div class="aviso verde">
            ✓ Guardado{{ $p['resultado']['nombre'] ? ': ' . $p['resultado']['nombre'] : '' }}.
            @if ($p['resultado']['url'])
                <a href="{{ $p['resultado']['url'] }}" style="text-decoration:underline">Ver ficha</a>
            @endif
            Quedó en el historial.
        </div>
    @else
        <p class="gris">Descartada: no se guardó nada.</p>
    @endif
</div>
