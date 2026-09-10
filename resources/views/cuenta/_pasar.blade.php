{{--
    Pasarle esta atención a otra persona del equipo (§10).

    Tres estados, y solo uno enseña el formulario:
      · hay una propuesta esperando: se dice a quién, y se puede retirar;
      · se puede pasar: el desplegable con la lista del equipo;
      · no se puede (ya empezó, no está confirmada, no hay a quién): nada.

    Espera $reserva y $candidatos (los de esa reserva, o nulo).
--}}
@if ($reserva->traspasoPendiente)
    <span class="pill warn" title="Sigue a tu nombre hasta que responda">Propuesta a {{ $reserva->traspasoPendiente->to?->name }}</span>
    <form method="POST" action="{{ route('traspaso.retirar', $reserva->traspasoPendiente) }}" style="display:inline">
        @csrf
        <button type="submit" class="secundario" style="margin-top:0;padding:.35rem .7rem;font-size:.85rem">Retirar</button>
    </form>
@elseif ($candidatos && $candidatos->isNotEmpty())
    <details class="plegable">
        <summary><x-icono nombre="pasar"/>Pasar a otra persona</summary>
        <form method="POST" action="{{ route('traspaso.proponer', $reserva) }}">
            @csrf
            <label for="a-{{ $reserva->id }}">A quién</label>
            <select id="a-{{ $reserva->id }}" name="a" required>
                @foreach ($candidatos as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}{{ $c->sugerido ? ' · asesora esto' : '' }}</option>
                @endforeach
            </select>
            <label for="nota-{{ $reserva->id }}">Por qué (opcional)</label>
            <input id="nota-{{ $reserva->id }}" name="nota" type="text" maxlength="500" placeholder="Tengo clase a esa hora">
            <button type="submit">Proponer</button>
            <span class="help" style="margin:0;font-size:.82rem">Sigue a tu nombre hasta que acepte.</span>
        </form>
    </details>
@endif
