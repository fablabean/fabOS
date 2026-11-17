{{-- El formulario de preinscripción. Lo incluye _preinscripcion-ficha, en la
     cohorte por abrir y en la abierta con registro oficial afuera. --}}
<form method="POST" action="{{ route('preinscripcion.store', $curso) }}" class="ficha">
    @csrf

    <h2>Preinscríbete</h2>
    <p class="help" style="color:var(--muted);font-size:.88rem;margin:0">
        @if ($yaEstoy)
            Ya estás en la lista. Si vuelves a enviar el formulario, corrige lo anterior en vez de duplicarlo.
        @else
            Dos minutos. Sin pago y sin compromiso.
        @endif
    </p>

    {{-- Trampa para robots: nadie la ve, nadie debería llenarla. --}}
    <div style="position:absolute;left:-9999px" aria-hidden="true">
        <label>No llenar este campo
            <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
        </label>
    </div>

    <label>
        Nombre completo
        <input type="text" name="nombre" required maxlength="160" autocomplete="name"
               value="{{ old('nombre', auth()->user()?->name) }}">
    </label>

    <label>
        Correo
        <input type="email" name="correo" required maxlength="160" autocomplete="email"
               value="{{ old('correo', $registro['correo'] ?? auth()->user()?->email) }}">
        <span class="help">Aquí te avisamos si la cohorte se abre.</span>
    </label>

    {{-- Con indicativo: a un nodo único en el país le escribe gente
         de fuera, y un número sin país no se puede marcar. --}}
    @include('partials.telefono', ['valor' => auth()->user()?->phone])

    <label>
        Ciudad
        <input type="text" name="ciudad" required maxlength="120"
               value="{{ old('ciudad') }}" placeholder="Bogotá">
        <span class="help">El trabajo de cada semana es presencial, en el laboratorio.</span>
    </label>

    <label>
        A qué te dedicas
        <input type="text" name="ocupacion" required maxlength="160"
               value="{{ old('ocupacion') }}" placeholder="Diseñadora industrial, docente, ingeniero…">
    </label>

    <label>
        Empresa o universidad
        <input type="text" name="institucion" maxlength="160" value="{{ old('institucion') }}">
        <span class="help">Si vienes de parte de una, o si sería la que lo paga.</span>
    </label>

    <label>
        Por qué quieres hacerlo
        <textarea name="motivacion" rows="4" required maxlength="2000">{{ old('motivacion') }}</textarea>
        <span class="help">Qué quieres aprender o qué te gustaría construir. No hace falta que sea largo.</span>
    </label>

    <label>
        Cómo piensas financiarlo
        <select name="financiacion" required>
            <option value="">Elige una</option>
            @foreach (\App\Models\Preenrollment::FINANCIACION as $clave => $texto)
                <option value="{{ $clave }}" @selected(old('financiacion') === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
        <span class="help">No te compromete a nada: nos dice si la cohorte es viable y qué apoyos buscar.</span>
    </label>

    <label>
        Portafolio o LinkedIn
        <input type="url" name="portafolio" maxlength="255" value="{{ old('portafolio') }}"
               placeholder="https://">
        <span class="help">Si tienes. No es requisito.</span>
    </label>

    {{-- Ley 1581 de 2012. Con el para qué escrito: una
         autorización que no dice para qué no autoriza gran cosa. --}}
    <label class="casilla">
        <input type="checkbox" name="autoriza" value="1" required {{ old('autoriza') ? 'checked' : '' }}>
        <span>
            Autorizo a {{ config('fabos.lab.name') }} a guardar estos datos y a escribirme
            sobre la apertura de esta cohorte. Puedo pedir que los borren cuando quiera.
        </span>
    </label>

    <x-captcha accion="preinscripcion.store"/>

    <button type="submit" class="btn">Preinscribirme</button>
</form>
