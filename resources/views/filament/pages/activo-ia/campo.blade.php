{{--
    Un campo de una propuesta: en ámbar si hay que revisarlo, con de dónde salió.

    Las opciones pueden venir agrupadas —[espacio => [id => ruta]]— y entonces
    salen con su encabezado: veinte «Repisa 1» solo se distinguen por dónde están.
--}}
<div @class(['campo', 'revisar' => $nota['revisar'], 'ancho' => $ancho ?? false])>
    <label>{{ $etiqueta }}</label>
    @if ($tipo === 'select')
        <select wire:model="{{ $modelo }}" @disabled(! $pendiente)>
            <option value="">—</option>
            @foreach ($opciones as $valor => $nombre)
                @if (is_array($nombre))
                    <optgroup label="{{ $valor }}">
                        @foreach ($nombre as $v => $n)
                            <option value="{{ $v }}">{{ $n }}</option>
                        @endforeach
                    </optgroup>
                @else
                    <option value="{{ $valor }}">{{ $nombre }}</option>
                @endif
            @endforeach
        </select>
    @else
        <input type="text" wire:model="{{ $modelo }}" @disabled(! $pendiente)>
    @endif
    @if ($nota['texto'])
        <small>{{ $nota['texto'] }}</small>
    @endif
</div>
