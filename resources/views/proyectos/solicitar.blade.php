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
            máquina: para eso estamos.
            @if ($usuario)
                Quedará en tu cuenta, con todo lo que adjuntes.
            @else
                Al enviarlo se crea tu cuenta con el correo que escribas, para que
                puedas seguir el proyecto desde aquí.
            @endif
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

    <form method="POST" action="{{ route('proyectos.solicitar.store') }}" class="panel"
          enctype="multipart/form-data" id="solicitud">
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

        <h2>Enséñanoslo</h2>

        <p class="help" style="margin-top:-.4rem">
            Una idea contada solo con palabras se entiende de tantas formas como
            personas la lean. Una foto de la pieza rota, un plano, o un garabato con
            dos medidas ahorra tres correos de ida y vuelta.
        </p>

        <label>
            Archivos de soporte
            <input type="file" name="soportes[]" multiple
                   accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.dxf,.stl,.step,.stp,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
            <span class="foot">
                Hasta {{ \App\Services\Projects\SoportesDeSolicitud::MAXIMO }} archivos,
                10 MB cada uno. Fotos, PDF, planos, .stl o documentos de oficina.
            </span>
        </label>

        <div class="dibujo">
            <span class="rotulo-campo">O dibújalo aquí</span>

            <canvas id="lienzo" width="900" height="420"></canvas>

            <div class="herramientas">
                <button type="button" id="borrar" class="secundario">Borrar el dibujo</button>
                <span class="foot" id="estado-dibujo">Se manda solo si dibujas algo.</span>
            </div>

            <input type="hidden" name="dibujo" id="dibujo">
        </div>

        <h2>Quién eres</h2>

        @if ($usuario)
            {{-- A quien ya entró no se le vuelve a preguntar quién es. --}}
            <p class="msg ok" style="margin-top:0">
                Lo pides como <strong>{{ $usuario->name }}</strong> ({{ $usuario->email }}).
                Quedará en <a href="{{ route('home') }}">tu cuenta</a>.
            </p>

            <div class="dos">
                <label>
                    Teléfono
                    <input type="text" name="telefono" maxlength="40"
                           value="{{ old('telefono', $usuario->phone) }}">
                </label>

                <label>
                    Organización
                    <input type="text" name="organizacion" maxlength="160" value="{{ old('organizacion') }}"
                           placeholder="Si escribes a nombre de una empresa o una facultad">
                </label>
            </div>
        @else
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
        @endif

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
        form.panel input[type=file] { padding:.5rem; }
        form.panel .foot { display:block; font-weight:400; margin-top:.25rem; }
        form.panel .dos { display:grid; grid-template-columns:repeat(auto-fit,minmax(15rem,1fr)); gap:0 1rem; }

        .dibujo { margin-bottom:1.2rem; }
        .dibujo .rotulo-campo { display:block; font-size:.9rem; font-weight:600; margin-bottom:.3rem; }
        .dibujo canvas { width:100%; max-width:100%; height:auto; aspect-ratio:900/420;
                         background:var(--surface); border:1px solid var(--rule);
                         border-radius:5px; touch-action:none; cursor:crosshair; display:block; }
        .dibujo .herramientas { display:flex; gap:.7rem; align-items:center;
                                flex-wrap:wrap; margin-top:.5rem; }
        .dibujo .herramientas button { margin:0; }
        .dibujo .herramientas .foot { margin:0; }
    </style>

    <script>
        // Un lienzo a mano alzada, sin librerías: un garabato con dos medidas
        // explica en un segundo lo que un párrafo no consigue.
        //
        // Solo viaja si de verdad se dibujó algo. Mandar un PNG en blanco por
        // el hecho de que el lienzo exista sería llenar el proyecto de ruido.
        (function () {
            const lienzo = document.getElementById('lienzo');
            if (!lienzo) return;

            const ctx = lienzo.getContext('2d');
            const campo = document.getElementById('dibujo');
            const estado = document.getElementById('estado-dibujo');
            const formulario = document.getElementById('solicitud');

            // Fondo blanco explícito: un PNG transparente se ve negro en
            // cualquier visor con tema oscuro, y el trazo desaparece.
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, lienzo.width, lienzo.height);
            ctx.strokeStyle = '#1a1a1a';
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            let trazando = false;
            let hayDibujo = false;

            function punto(e) {
                const caja = lienzo.getBoundingClientRect();

                // El lienzo se muestra escalado: sin esta corrección el trazo
                // aparece desplazado de donde está el dedo.
                return {
                    x: (e.clientX - caja.left) * (lienzo.width / caja.width),
                    y: (e.clientY - caja.top) * (lienzo.height / caja.height),
                };
            }

            lienzo.addEventListener('pointerdown', function (e) {
                trazando = true;
                lienzo.setPointerCapture(e.pointerId);
                const p = punto(e);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
            });

            lienzo.addEventListener('pointermove', function (e) {
                if (!trazando) return;
                const p = punto(e);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                hayDibujo = true;
                estado.textContent = 'Se enviará con la solicitud.';
            });

            ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (evento) {
                lienzo.addEventListener(evento, function () { trazando = false; });
            });

            document.getElementById('borrar').addEventListener('click', function () {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, lienzo.width, lienzo.height);
                hayDibujo = false;
                campo.value = '';
                estado.textContent = 'Se manda solo si dibujas algo.';
            });

            formulario.addEventListener('submit', function () {
                campo.value = hayDibujo ? lienzo.toDataURL('image/png') : '';
            });
        })();
    </script>
@endsection
