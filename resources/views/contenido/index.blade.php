@extends('layouts.app')
@section('title', 'Grabar el laboratorio · ' . config('fabos.lab.name'))

@section('content')
    <h1>Grabar el laboratorio</h1>

    <p class="help">
        Una pieza saliendo de la impresora, el primer corte que salió bien, alguien
        explicando cómo lo hizo. Se toma con la cámara y queda guardado aquí mismo.
    </p>

    @if (session('subido'))
        <div class="msg ok">
            <strong>{{ session('subido') }}
            {{ session('subido') == 1 ? 'archivo guardado' : 'archivos guardados' }}.</strong>
            @if (session('aProyecto'))
                Quedaron con «{{ session('aProyecto') }}».
            @endif
        </div>
    @endif

    @if ($errors->any())
        <div class="msg error">
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('contenido.store') }}" enctype="multipart/form-data"
          class="panel" id="captura">
        @csrf

        {{-- Tres entradas al mismo campo. En el teléfono, `capture` abre la
             cámara directamente en vez del explorador de archivos: es la
             diferencia entre documentar en diez segundos y no documentar. --}}
        <div class="camara">
            <label class="boton">
                <span class="icono">📷</span>
                <span>Tomar una foto</span>
                <input type="file" name="archivos[]" accept="image/*" capture="environment" multiple>
            </label>

            <label class="boton">
                <span class="icono">🎥</span>
                <span>Grabar un video</span>
                <input type="file" name="archivos[]" accept="video/*" capture="environment" multiple>
            </label>

            <label class="boton secundario">
                <span class="icono">📁</span>
                <span>Elegir de la galería</span>
                <input type="file" name="archivos[]" accept="image/*,video/*" multiple>
            </label>
        </div>

        <p class="foot" id="elegidos" hidden></p>

        <label class="campo">
            Qué es
            <input type="text" name="title" maxlength="160" value="{{ old('title') }}"
                   placeholder="Primera prueba de la carcasa">
        </label>

        @if ($proyectos->isNotEmpty())
            {{-- Solo los suyos: ofrecer la lista entera del laboratorio sería
                 invitar a que el material acabe en el proyecto de otro. --}}
            <label class="campo">
                ¿Es de algún proyecto tuyo?
                <select name="project_id">
                    <option value="">No, es del laboratorio en general</option>
                    @foreach ($proyectos as $proyecto)
                        <option value="{{ $proyecto->id }}" @selected(old('project_id') == $proyecto->id)>
                            {{ $proyecto->code }} · {{ $proyecto->name }}
                        </option>
                    @endforeach
                </select>
                <span class="foot">Queda con el proyecto, en su material documental.</span>
            </label>
        @endif

        <label class="campo">
            Algo más que contar
            <textarea name="description" rows="2"
                      placeholder="Qué se ve, con qué máquina, para qué era.">{{ old('description') }}</textarea>
        </label>

        <div class="derechos">
            <label class="acepto">
                <input type="checkbox" name="derechos" value="1" required @checked(old('derechos'))>
                <span>Acepto la autorización de uso</span>
            </label>

            <p class="texto">{{ $terminos }}</p>

            <p class="foot">
                Queda anotado quién autorizó, cuándo, y este texto exacto. El material
                se comparte con Comunicaciones de la Universidad para divulgación.
            </p>
        </div>

        <button type="submit" id="enviar">Subir</button>

        <p class="foot" style="margin-top:.7rem">
            Hasta 10 archivos, {{ $maxMb }} MB cada uno. Los videos largos tardan:
            no cierres la página mientras suben.
        </p>
    </form>

    @if ($mias->isNotEmpty())
        <h2>Lo que has subido</h2>

        <div class="galeria">
            @foreach ($mias as $pieza)
                <a class="pieza" href="{{ $pieza->enlace() }}" target="_blank" rel="noopener">
                    @if ($pieza->esVideo())
                        <div class="video"><span>▶</span></div>
                    @else
                        <img src="{{ $pieza->enlace() }}" alt="{{ $pieza->comoSeLlama() }}" loading="lazy">
                    @endif

                    <span class="pie">
                        {{ $pieza->comoSeLlama() }}
                        @if ($pieza->project)
                            <span class="quien">{{ $pieza->project->code }}</span>
                        @endif
                        @unless ($pieza->estaDisponible())
                            <span class="quien">retirado</span>
                        @endunless
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Rejilla propia: las utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        #captura .camara { display:grid; grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));
                           gap:.6rem; margin-bottom:1rem; }
        #captura .boton { display:flex; flex-direction:column; align-items:center; justify-content:center;
                          gap:.3rem; padding:1.1rem .8rem; border:1px solid var(--accent);
                          border-radius:6px; cursor:pointer; text-align:center;
                          font-size:.9rem; font-weight:600; color:var(--accent); }
        #captura .boton.secundario { border-color:var(--rule); color:var(--muted); }
        #captura .boton .icono { font-size:1.5rem; }
        #captura .boton input { display:none; }

        #captura .campo { display:block; margin-bottom:1rem; font-size:.9rem; font-weight:600; }
        #captura .campo input, #captura .campo select, #captura .campo textarea {
            width:100%; margin-top:.3rem; font-weight:400; }
        #captura .foot { display:block; font-weight:400; margin-top:.25rem; }

        #captura .derechos { border:1px solid var(--rule); border-left:3px solid var(--warn);
                             border-radius:6px; padding:.9rem 1rem; margin-bottom:1rem; }
        #captura .acepto { display:flex; gap:.5rem; align-items:center;
                           font-size:.95rem; font-weight:700; }
        #captura .acepto input { width:auto; margin:0; }
        #captura .derechos .texto { font-size:.82rem; margin:.6rem 0 0; color:var(--ink-soft); }

        .galeria { display:grid; grid-template-columns:repeat(auto-fill,minmax(9rem,1fr)); gap:.7rem; }
        .galeria .pieza { display:block; text-decoration:none; color:inherit; }
        .galeria img, .galeria .video { width:100%; height:8rem; object-fit:cover; display:block;
                                        border:1px solid var(--rule); border-radius:6px;
                                        background:var(--surface); }
        .galeria .video { display:flex; align-items:center; justify-content:center;
                          font-size:1.6rem; color:var(--muted); }
        .galeria .pie { display:block; font-size:.78rem; margin-top:.3rem; line-height:1.3; }
    </style>

    <script>
        // Decir cuántos se eligieron, y no dejar pulsar dos veces: un video de
        // cien megas tarda, y sin señal de que está subiendo la gente vuelve a
        // darle al botón y manda el archivo otra vez.
        (function () {
            const formulario = document.getElementById('captura');
            const aviso = document.getElementById('elegidos');
            const enviar = document.getElementById('enviar');
            if (!formulario) return;

            formulario.querySelectorAll('input[type=file]').forEach(function (campo) {
                campo.addEventListener('change', function () {
                    const n = campo.files.length;
                    if (!n) return;

                    aviso.hidden = false;
                    aviso.textContent = n === 1
                        ? 'Elegido: ' + campo.files[0].name
                        : n + ' archivos elegidos.';
                });
            });

            formulario.addEventListener('submit', function () {
                enviar.disabled = true;
                enviar.textContent = 'Subiendo…';
            });
        })();
    </script>
@endsection
