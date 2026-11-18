@extends('layouts.app')
@section('title', 'Salón ' . $pantalla->salon . ' · fabOS')

@php $tz = config('fabos.lab.timezone'); @endphp

@section('content')
    <p class="rotulo">Salón{{ $pantalla->ubicacion ? ' · ' . $pantalla->ubicacion : '' }}</p>
    <h1>{{ $pantalla->salon }}</h1>

    @if (session('status')) <p class="msg ok">{{ session('status') }}</p> @endif
    @error('llegada') <p class="msg error">{{ $message }}</p> @enderror

    <div class="panel">
        @if ($actual)
            <p class="rotulo" style="margin:0">Ahora</p>
            <h2 style="margin:.2rem 0 .4rem">{{ $actual->titulo }}</h2>
            <p class="help" style="margin:0">
                {{ $actual->horario() }} · {{ $actual->duracion() }}
                @if ($actual->responsable) · Dirige: <strong>{{ $actual->responsable }}</strong> @endif
            </p>
        @else
            <h2 style="margin-top:0">El salón está libre</h2>
        @endif

        @if ($siguiente)
            <p class="help" style="margin:.8rem 0 0">
                Después: <strong>{{ $siguiente->titulo }}</strong>,
                {{ $siguiente->inicia_at->copy()->timezone($tz)->locale('es')->isoFormat('dddd D [a las] HH:mm') }}.
            </p>
        @endif
    </div>

    @if ($abierta)
        <div class="panel">
            <h2 style="margin-top:0">Marcar mi llegada</h2>
            @if ($llegada)
                <p class="help">Ya registraste tu llegada a «{{ $abierta->titulo }}» a las {{ $llegada->created_at->timezone($tz)->format('H:i') }}.</p>
            @else
                <p class="help">Para «{{ $abierta->titulo }}». Quien la dirige y quienes asisten marcan aquí, con su cuenta.</p>
                <form method="GET" action="{{ route('salon.llegada', $pantalla->codigo) }}">
                    <button type="submit">{{ auth()->check() ? 'Marcar mi llegada' : 'Ingresar y marcar mi llegada' }}</button>
                </form>
            @endif
        </div>
    @endif

    <div class="panel">
        <h2 style="margin-top:0">Reportar una novedad del salón</h2>
        <p class="help">¿Algo dañado, algo que falta, un problema con el espacio? Cuéntalo y le llega a quien cuida este salón. No hace falta tener cuenta.</p>

        <form method="POST" action="{{ route('salon.novedad', $pantalla->codigo) }}" enctype="multipart/form-data">
            @csrf
            <label for="descripcion">Qué pasa</label>
            <textarea id="descripcion" name="descripcion" rows="4" maxlength="2000" required
                      placeholder="El video beam no prende; falta el cable HDMI…">{{ old('descripcion') }}</textarea>
            @error('descripcion') <p class="msg error">{{ $message }}</p> @enderror

            <label for="foto">Una foto <small style="opacity:.6">(opcional)</small></label>
            <input id="foto" type="file" name="foto" accept="image/*" style="width:100%;max-width:100%;box-sizing:border-box">
            @error('foto') <p class="msg error">{{ $message }}</p> @enderror

            @guest
                <label for="contacto">Tu correo <small style="opacity:.6">(opcional, por si hay que preguntarte algo)</small></label>
                <input id="contacto" type="email" name="contacto" value="{{ old('contacto') }}" maxlength="255">
                @error('contacto') <p class="msg error">{{ $message }}</p> @enderror
            @endguest

            <x-captcha accion="salon.novedad"/>

            <button type="submit">Enviar la novedad</button>
        </form>
    </div>
@endsection
