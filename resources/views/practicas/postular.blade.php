@extends('layouts.app')
@section('title', 'Postularme · ' . $convocatoria->name . ' · ' . config('fabos.lab.name'))

@section('content')
    <a class="volver" href="{{ route('practicas.index') }}">← Volver a las convocatorias</a>

    <h1 style="margin-top:.6rem">{{ $convocatoria->name }}</h1>

    @if ($convocatoria->description)
        <p class="help">{{ $convocatoria->description }}</p>
    @endif

    @if (! $convocatoria->admitePostulaciones())
        <div class="panel" style="border-left:4px solid var(--warn)">
            <p style="margin:0">{{ $convocatoria->porQueNoAdmite() }}</p>
        </div>
    @else
        @if ($errors->any())
            <div class="panel" style="border-left:4px solid var(--error)">
                <ul style="margin:0;padding-left:1.1rem">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('practicas.postular.store', $convocatoria) }}"
              class="panel" enctype="multipart/form-data">
            @csrf

            {{-- Trampa para robots: nadie la ve, nadie debería llenarla. Es lo
                 que separa un formulario abierto de un buzón de spam. --}}
            <div style="position:absolute;left:-9999px" aria-hidden="true">
                <label>No llenar este campo
                    <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
                </label>
            </div>

            <h2 style="margin-top:0">Quién eres</h2>

            <label>
                Nombre completo
                <input type="text" name="nombre" required maxlength="160" value="{{ old('nombre') }}">
            </label>

            <label>
                Correo
                <input type="email" name="correo" required maxlength="160" value="{{ old('correo') }}">
                <span class="help">
                    Si estudias en {{ config('fabos.lab.institution') ?: 'la Universidad' }},
                    usa tu correo institucional.
                </span>
            </label>

            <label>
                Teléfono
                <input type="text" name="telefono" maxlength="40" value="{{ old('telefono') }}">
            </label>

            <label>
                Documento de identidad
                <input type="text" name="documento" maxlength="40" value="{{ old('documento') }}">
            </label>

            <h2>Qué estudias</h2>

            <label>
                Programa
                <input type="text" name="programa" required maxlength="160"
                       value="{{ old('programa') }}" placeholder="Diseño industrial">
            </label>

            <label>
                Universidad
                <input type="text" name="institucion" maxlength="160" value="{{ old('institucion') }}">
                <span class="help">Solo si no es {{ config('fabos.lab.institution') ?: 'la nuestra' }}.</span>
            </label>

            <label>
                Semestre
                <input type="text" name="semestre" maxlength="20" value="{{ old('semestre') }}">
            </label>

            <label>
                Horas que te exige la universidad
                <input type="number" name="horas" min="1" max="2000" value="{{ old('horas') }}">
            </label>

            <label>
                Cuándo puedes
                <input type="text" name="disponibilidad" maxlength="160"
                       value="{{ old('disponibilidad') }}"
                       placeholder="Mañanas, de lunes a jueves">
            </label>

            <h2>Por qué y con qué</h2>

            <label>
                Por qué quieres hacer tu práctica aquí
                <textarea name="motivacion" rows="4" required maxlength="2000">{{ old('motivacion') }}</textarea>
                <span class="help">Es lo que de verdad leemos. No hace falta que sea largo.</span>
            </label>

            <label>
                Hoja de vida
                <input type="file" name="hoja_de_vida" accept=".pdf,.doc,.docx">
                <span class="help">PDF o Word, hasta 10 MB.</span>
            </label>

            <label>
                O el enlace a tu hoja de vida
                <input type="url" name="hoja_url" maxlength="255" value="{{ old('hoja_url') }}"
                       placeholder="https://">
            </label>

            <label>
                Portafolio
                <input type="url" name="portafolio" maxlength="255" value="{{ old('portafolio') }}"
                       placeholder="https://">
                <span class="help">Si tienes. Behance, Drive, tu web: lo que sea.</span>
            </label>

            {{-- Ley 1581 de 2012. Se pide antes de guardar nada y con el para
                 qué escrito: una autorización que no dice para qué no autoriza
                 gran cosa. --}}
            <label style="margin-top:1.2rem">
                <input type="checkbox" name="autoriza" value="1" required {{ old('autoriza') ? 'checked' : '' }}>
                Autorizo a {{ config('fabos.lab.name') }} a guardar y revisar estos datos y mi
                hoja de vida con el único fin de evaluar mi postulación a esta práctica.
                <span class="help">
                    Puedes pedirnos que los borremos cuando quieras.
                </span>
            </label>

            <p style="margin-bottom:0">
                <button type="submit" class="boton">Enviar mi postulación</button>
            </p>
        </form>
    @endif
@endsection
