{{-- El teléfono en dos partes: el indicativo del país y el número. Espera
     $valor (el teléfono guardado, si lo hay); usa old() por encima. --}}
@php
    $partes = \App\Support\Telefono::partir($valor ?? null);
    $indicativoActual = old('telefono_indicativo', $partes['indicativo']);
@endphp
<label>
    Teléfono
    <span style="display:flex;gap:.5rem;align-items:stretch">
        <select name="telefono_indicativo" aria-label="Indicativo del país" style="width:auto;flex:none">
            @foreach (\App\Support\Telefono::INDICATIVOS as $codigo => $nombre)
                <option value="{{ $codigo }}" @selected($indicativoActual === $codigo)>{{ $nombre }}</option>
            @endforeach
        </select>
        <input type="tel" name="telefono" maxlength="30" inputmode="tel" placeholder="3001234567"
               value="{{ old('telefono', $partes['numero']) }}" style="flex:1">
    </span>
</label>
