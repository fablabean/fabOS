@extends('layouts.app')
@section('title', 'Proponer un proyecto · ' . config('fabos.lab.name'))

@section('content')
    <a class="volver" href="{{ route('publico.home') }}">← Volver al inicio</a>

    <h1 style="margin-top:.6rem">Proponer un proyecto</h1>

    @if (session('recibido'))
        <div class="panel" style="border-left:4px solid var(--ok)">
            <h2 style="margin-top:0">Quedó anotado</h2>
            <p>
                Tu solicitud es la <strong>{{ session('recibido') }}</strong>. Te mandamos
                un correo con ese código.
            </p>
            <p class="help" style="margin-bottom:0">
                Ahora alguien del laboratorio la va a mirar: si cabe, con qué máquinas y
                cuánto tomaría. Cuando tengamos una propuesta te llega por correo, con un
                enlace donde la ves completa. También puedes entrar a
                <a href="{{ route('home') }}">tu cuenta</a> —creada con este mismo
                correo— para seguirla.
            </p>
        </div>
    @else
        <p class="help">
            Cuéntanos qué necesitas. No hace falta que sepas cómo se hace ni con qué
            máquina: para eso estamos. Al enviarlo se crea tu cuenta con el correo que
            escribas, para que puedas seguir el proyecto desde aquí.
        </p>
    @endif

    @if ($errors->any())
        <div class="msg error">
            <strong>Falta algo:</strong>
            <ul style="margin:.4rem 0 0;padding-left:1.1rem">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('proyectos.solicitar.store') }}" class="panel">
        @csrf

        {{-- Trampa para robots: nadie la ve, nadie debería llenarla. Es lo que
             separa un formulario abierto de un buzón de spam en una semana. --}}
        <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label>No llenar este campo
                <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
            </label>
        </div>

        <h2 style="margin-top:0">Qué necesitas</h2>

        <label>
            Nombre del proyecto
            <input type="text" name="titulo" required maxlength="180"
                   value="{{ old('titulo') }}"
                   placeholder="Señalética para el edificio de Bienestar">
        </label>

        <label>
            De qué se trata
            <textarea name="resumen" rows="4" required
                      placeholder="Qué es, para qué lo necesitas y para quién. Con dos o tres frases basta.">{{ old('resumen') }}</textarea>
        </label>

        <label>
            Qué esperas recibir
            <textarea name="entregables" rows="4"
                      placeholder="Uno por renglón:&#10;20 letreros en acrílico&#10;Los archivos de corte&#10;Instalación">{{ old('entregables') }}</textarea>
            <span class="foot">
                Uno por renglón. Si todavía no lo sabes, déjalo en blanco: se define juntos.
            </span>
        </label>

        <label>
            ¿Para cuándo lo necesitas?
            <input type="date" name="para_cuando" value="{{ old('para_cuando') }}">
            <span class="foot">Opcional, pero cambia mucho lo que se puede proponer.</span>
        </label>

        <h2>Quién eres</h2>

        <div class="dos">
            <label>
                Tu nombre
                <input type="text" name="nombre" required maxlength="120" value="{{ old('nombre') }}">
            </label>

            <label>
                Correo
                <input type="email" name="correo" required maxlength="180" value="{{ old('correo') }}">
                <span class="foot">Con este correo se crea tu cuenta y entras sin contraseña.</span>
            </label>

            <label>
                Teléfono
                <input type="text" name="telefono" maxlength="40" value="{{ old('telefono') }}">
            </label>

            <label>
                Organización
                <input type="text" name="organizacion" maxlength="160" value="{{ old('organizacion') }}"
                       placeholder="Si escribes a nombre de una empresa o una facultad">
            </label>
        </div>

        <button type="submit">Enviar la solicitud</button>

        <p class="foot" style="margin-top:.8rem">
            Enviarla no compromete a nada, ni a ti ni al laboratorio. Es el punto de
            partida de una conversación.
        </p>
    </form>

    {{-- Rejilla propia: las utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        form.panel label { display:block; margin-bottom:1rem; font-size:.9rem; font-weight:600; }
        form.panel input, form.panel textarea { width:100%; margin-top:.3rem; font-weight:400; }
        form.panel .foot { display:block; font-weight:400; margin-top:.25rem; }
        form.panel .dos { display:grid; grid-template-columns:repeat(auto-fit,minmax(15rem,1fr)); gap:0 1rem; }
    </style>
@endsection
