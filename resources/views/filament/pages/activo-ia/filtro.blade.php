{{--
    El primer filtro: antes de buscar en el inventario, la persona dice qué es
    de verdad un objeto. Lo que no, se olvida; si falta algo, se busca otro en
    la foto. Las partes y los otros ángulos siguen a su objeto.
--}}
@php
    $deEste = collect($msg['propuestas'])->map(fn ($c) => ['clave' => $c, 'p' => $propuestas[$c] ?? null])
        ->filter(fn ($x) => ($x['p']['clase'] ?? null) === 'reconocimiento');
    $aRevisar = $deEste->filter(fn ($x) => $x['p']['estado'] === 'por_revisar');
    $faltan = $aRevisar->filter(fn ($x) => ($x['p']['filtro'] ?? null) === null)->count();
    $variasFotos = count($msg['fotos_filtro'] ?? []) > 1;
    $colgadosDe = fn (string $clave) => $deEste->filter(fn ($x) => ($x['p']['parte_de']['clave'] ?? $x['p']['repetida_de']['clave'] ?? null) === $clave);
@endphp

<div class="tarjeta filtro" wire:key="filtro-{{ $i }}">
    <h4 style="margin-bottom:.15rem">¿Cuáles son objetos del inventario?</h4>
    <p class="gris" style="font-size:.8rem;margin-top:0">
        Marca cada uno. Lo que no sea un objeto se olvida. Cuando termines, seguimos a buscarlos en el inventario.
    </p>

    @foreach ($aRevisar->groupBy(fn ($x) => (int) ($x['p']['objeto']['foto'] ?? 1)) as $numeroDeFoto => $deLaFoto)
        @if ($variasFotos)
            <strong style="font-size:.8rem;display:block;margin-top:.6rem">Foto {{ $numeroDeFoto }}</strong>
        @endif

        @foreach ($deLaFoto as ['clave' => $clave, 'p' => $p])
            @php($decision = $p['filtro'] ?? null)
            @php($miniatura = $p['objeto']['miniatura'] ?? ($msg['fotos_filtro'][$numeroDeFoto]['miniatura'] ?? null))
            @php($colgados = $colgadosDe($clave))
            <div @class(['candidato', 'probable' => $decision === 'si']) style="{{ $decision === 'no' ? 'opacity:.55' : '' }}" wire:key="filtro-{{ $clave }}">
                <div class="fila" style="gap:.6rem;flex-wrap:nowrap;min-width:0">
                    @if ($miniatura)
                        <img class="recorte" style="height:3.2rem" src="{{ $miniatura }}" alt="Recorte del objeto">
                    @endif
                    <div style="min-width:0">
                        <strong style="font-size:.86rem">{{ $p['propuesta']['nombre'] ?? 'Objeto sin nombre' }}</strong>
                        <span class="gris" style="font-size:.74rem">· objeto {{ $p['objeto']['indice'] ?? 1 }}{{ filled($p['objeto']['posicion'] ?? null) ? ', ' . $p['objeto']['posicion'] : '' }}</span>
                        {{-- Lo que va con él: las partes que ya vinieron juntas, y las que se juntaron aquí (se pueden separar). --}}
                        @if (($p['objeto']['incluye'] ?? []) !== [] || $colgados->isNotEmpty())
                            <br><span class="gris" style="font-size:.74rem">Incluye:
                                {{ implode(', ', $p['objeto']['incluye'] ?? []) }}{{ ($p['objeto']['incluye'] ?? []) !== [] && $colgados->isNotEmpty() ? ',' : '' }}
                                @foreach ($colgados as ['clave' => $parte, 'p' => $deLaParte])
                                    {{ $deLaParte['propuesta']['nombre'] ?? 'parte' }}
                                    @if ($deLaParte['estado'] === 'parte')
                                        (<button type="button" wire:click="noEsUnaPieza('{{ $parte }}')" style="text-decoration:underline" title="Es otro objeto: revisarlo aparte">separar</button>){{ $loop->last ? '' : ',' }}
                                    @endif
                                @endforeach
                            </span>
                        @endif
                    </div>
                </div>
                <div class="fila" style="gap:.35rem;flex:none">
                    @if ($decision === null)
                        <x-filament::button size="xs" wire:click="decidirObjeto('{{ $clave }}', 'si')" icon="heroicon-m-check">Sí, es un objeto</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="decidirObjeto('{{ $clave }}', 'no')" icon="heroicon-m-x-mark">No, olvídalo</x-filament::button>
                    @else
                        <span class="chip {{ $decision === 'si' ? 'alta' : '' }}">{{ $decision === 'si' ? '✓ Es un objeto' : 'Se olvida' }}</span>
                        <x-filament::button size="xs" color="gray" wire:click="decidirObjeto('{{ $clave }}', null)">Cambiar</x-filament::button>
                    @endif
                </div>
            </div>
        @endforeach

        <div style="margin-top:.4rem">
            <x-filament::button size="xs" color="gray" icon="heroicon-m-magnifying-glass" wire:click="buscarOtro({{ $i }}, {{ $numeroDeFoto }})"
                                wire:loading.attr="disabled" wire:target="buscarOtro" :disabled="(bool) $this->enCurso">
                Busca otro en la foto{{ $variasFotos ? ' ' . $numeroDeFoto : '' }}
            </x-filament::button>
            <span wire:loading wire:target="buscarOtro" class="gris" style="font-size:.76rem">Enviando…</span>
        </div>
    @endforeach

    <div class="fila" style="gap:.5rem;margin-top:.8rem;flex-wrap:wrap;align-items:center">
        @if ($faltan > 0)
            <x-filament::button size="sm" color="gray" wire:click="todosSonObjetos({{ $i }})">Todos los que faltan son objetos</x-filament::button>
        @endif
        <x-filament::button size="sm" wire:click="seguirAlInventario({{ $i }})" icon="heroicon-m-arrow-right" :disabled="$faltan > 0">
            Seguir: ¿ya están en el inventario?
        </x-filament::button>
        @if ($faltan > 0)
            <span class="gris" style="font-size:.76rem">Faltan {{ $faltan }} por marcar.</span>
        @endif
    </div>
</div>
