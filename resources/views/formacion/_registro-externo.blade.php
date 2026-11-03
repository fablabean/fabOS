{{-- La cohorte abrió y el registro oficial es afuera, en el sitio de Fab
     Academy (PreinscripcionController::registroExterno).

     Lo primero es el correo: quien ya está en la lista pasa al registro
     oficial; quien no, se preinscribe aquí y cuenta para el mínimo. --}}
@php
    $r = $registro;
    $minimo = $cohorte->minimum_to_open;
@endphp

@if ($errors->any())
    <div class="error">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (! $r['correo'])
    <form method="POST" action="{{ route('fab-academy.correo') }}" class="ficha">
        @csrf
        <h2>Empieza con tu correo</h2>
        <p class="help" style="color:var(--muted);font-size:.88rem;margin:0">
            Si ya te preinscribiste, te mostramos el siguiente paso. Si no, te
            preinscribes aquí mismo.
        </p>
        <label>
            Correo
            <input type="email" name="correo" required maxlength="160" autocomplete="email" value="{{ old('correo') }}">
        </label>
        <button type="submit" class="btn">Continuar</button>
    </form>

@elseif ($r['mia'] && $r['habilitado'])
    <div class="ficha">
        <h2>Ya estás preinscrito</h2>
        <p>
            El siguiente paso es el <strong>registro oficial en Fab Academy</strong>:
            completa su formulario y acepta sus condiciones.
        </p>
        @if ($r['condiciones'])
            <ul style="margin:0 0 1rem;padding-left:1.1rem;font-size:.92rem">
                @foreach ($r['condiciones'] as $c)
                    <li>{{ $c }}</li>
                @endforeach
            </ul>
        @endif
        <a class="btn" href="{{ $r['url'] }}" target="_blank" rel="noopener">Ir al registro de Fab Academy</a>
        @unless ($r['conSesion'])
            <p class="help" style="margin-top:.8rem;font-size:.84rem">
                {{ $r['correo'] }} · <a href="{{ route('fab-academy', ['otro' => 1]) }}">No soy yo</a>
            </p>
        @endunless
    </div>

@elseif ($r['mia'])
    <div class="ficha">
        <h2>Ya estás preinscrito</h2>
        <p style="margin-bottom:0">
            El registro oficial en Fab Academy se habilita cuando seamos {{ $minimo }}
            @if ($faltan) —faltan {{ $faltan }}—@endif.
            Vuelve a esta página con tu correo: el botón aparece aquí en cuanto se complete.
        </p>
        @unless ($r['conSesion'])
            <p class="help" style="margin-top:.8rem;font-size:.84rem">
                {{ $r['correo'] }} · <a href="{{ route('fab-academy', ['otro' => 1]) }}">No soy yo</a>
            </p>
        @endunless
    </div>

@else
    <div class="aviso">
        No encontramos una preinscripción con <strong>{{ $r['correo'] }}</strong>.
        Déjanos tus datos@if ($minimo): cuando seamos {{ $minimo }}, se habilita el registro oficial en Fab Academy@endif.
        @unless ($r['conSesion'])
            <a href="{{ route('fab-academy', ['otro' => 1]) }}">Usar otro correo</a>
        @endunless
    </div>
    @include('formacion._preinscripcion-formulario')
@endif
