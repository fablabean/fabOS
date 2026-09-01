@extends('layouts.app')
@section('title', 'Pedir asesoría · ' . $titulo . ' · fabOS')

@section('content')
    <p class="rotulo">Asesoría</p>
    <h1>{{ $titulo }}</h1>

    <p class="help">{{ $explicacion }} Dura {{ $minutos }} minutos.</p>

    @error('inicio') <p class="msg error">{{ $message }}</p> @enderror

    @if ($franjas->isEmpty())
        <div class="panel">
            <h2 style="margin-top:0">No hay horas disponibles</h2>
            <p class="help">
                Quienes asesoran no tienen huecos en los próximos días.
                Vuelve a mirar mañana, o escribe a la coordinación del laboratorio.
            </p>
            <p class="foot"><a href="{{ $volver }}">← Volver</a></p>
        </div>
    @else
        <form method="POST" action="{{ $accion }}">
            @csrf

            <div class="panel">
                <h2 style="margin-top:0">Elige una hora</h2>
                <p class="help">
                    Solo aparecen horas en las que alguien puede atenderte de verdad.
                </p>

                @foreach ($franjas as $dia => $delDia)
                    @php($fecha = \Illuminate\Support\Carbon::parse($dia))

                    <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">
                        {{ ucfirst($fecha->locale('es')->isoFormat('dddd D [de] MMMM')) }}
                    </h3>

                    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                        @foreach ($delDia as $f)
                            <label style="cursor:pointer">
                                <input type="radio" name="inicio" required
                                       value="{{ $f['inicio']->format('Y-m-d H:i:s') }}"
                                       style="position:absolute;opacity:0">
                                <span class="chip">
                                    {{ $f['inicio']->format('H:i') }}
                                    @if ($f['cuantos'] > 1)
                                        <small style="opacity:.6">· {{ $f['cuantos'] }} disponibles</small>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="panel">
                <label for="motivo">¿Qué quieres hacer? <small style="opacity:.6">(opcional)</small></label>
                <textarea id="motivo" name="motivo" rows="3" maxlength="500"
                          placeholder="Cortar unas piezas de MDF para un prototipo…"></textarea>
                <p class="help">
                    Decirlo antes ayuda a quien te atienda a llegar preparado.
                </p>

                <button type="submit">Pedir la asesoría</button>
            </div>
        </form>
    @endif

    <style>
        .chip { display:inline-block; padding:.45rem .8rem; border-radius:.5rem;
                border:1px solid rgba(128,128,128,.4); font-variant-numeric:tabular-nums; }
        label:has(input:checked) .chip { border-color:#0f766e; background:rgba(15,118,110,.12);
                font-weight:700; }
        label:has(input:focus-visible) .chip { outline:2px solid #0f766e; outline-offset:2px; }
    </style>
@endsection
