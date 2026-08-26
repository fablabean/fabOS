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

        @if ($tramite)
            {{-- A quien ya entró no se le pregunta: su categoría lo dice, y
                 preguntárselo sería dejar que se equivoque en una respuesta que
                 el sistema ya tiene. --}}
            <input type="hidden" name="cliente" value="{{ $tramite }}">
            <p class="help">
                Como <strong>{{ $usuario->category?->name }}</strong>, tu encargo se
                tramita como <strong>{{ mb_strtolower(\App\Models\Project::CLIENTES[$tramite]) }}</strong>.
            </p>
        @else
            <label>
                ¿Cuál es tu rol?
                <select name="cliente" id="cliente" required>
                    @foreach (\App\Models\Project::CLIENTES as $clave => $nombre)
                        <option value="{{ $clave }}" @selected(old('cliente') === $clave)>{{ $nombre }}</option>
                    @endforeach
                </select>
                <span class="foot">
                    Cambia el trámite, no el trabajo. Si ya tienes cuenta,
                    <a href="{{ route('login') }}">entra</a> y lo tomamos de tu categoría.
                </span>
            </label>
        @endif

        <label>
            ¿Para cuándo lo necesitas?
            <input type="date" name="para_cuando" value="{{ old('para_cuando') }}"
                   id="para-cuando">
            <span class="foot" id="aviso-fecha">
                Opcional, pero cambia mucho lo que se puede proponer.
            </span>
        </label>

        {{-- Las condiciones de cada rol. Enseñarle a un estudiante el circuito
             presupuestal le haría pensar que su encargo también depende de
             Planeación; esconderle a un área ese circuito la dejaría esperando
             algo que nadie pidió. --}}
        <div class="panel condiciones" data-rol="estudiante" hidden>
            <h3>Cómo funciona para un estudiante</h3>
            <ul>
                <li>Se cotiza el tiempo de máquina y el material; el trabajo del equipo no se cobra.</li>
                <li>No hay trámite presupuestal: se acuerda contigo y se arranca.</li>
                <li>El plazo depende de la agenda de las máquinas, no de un procedimiento.</li>
            </ul>
        </div>

        <div class="panel condiciones" data-rol="externo" hidden>
            <h3>Cómo funciona para una organización de fuera</h3>
            <ul>
                <li>Se cotiza con la tarifa de externo y se factura contra la propuesta aceptada.</li>
                <li>La fabricación arranca con la aceptación por escrito.</li>
                <li>Sin trámite presupuestal interno: el plazo lo marca el trabajo.</li>
            </ul>
        </div>

        {{-- El circuito de la venta interna, para que quien lo pide sepa por
             qué se le piden dos semanas. Un plazo sin explicación se lee como
             burocracia; explicado, se entiende y se planea con tiempo. --}}
        <div class="panel flujo condiciones" data-rol="interno" id="flujo-interno" hidden>
            <h3>Cómo se paga un encargo interno</h3>
            <p class="help" style="margin-top:0">
                No hay factura: hay un traslado de presupuesto entre áreas. Pasa por
                cuatro manos antes de que llegue un peso, y por eso pedimos al menos
                {{ (int) config('fabos.proyectos.dias_minimos_interno') }} días calendario.
            </p>

            <ol class="pasos">
                <li>
                    <span class="quien">Quien compra</span>
                    <strong>Formulario de pedido</strong>
                    <span class="detalle">Lo llena el área solicitante, con la cotización adjunta.</span>
                </li>
                <li>
                    <span class="quien">Quien compra</span>
                    <strong>Líder emisor</strong>
                    <span class="detalle">Da su visto bueno el líder del área que pone los recursos.</span>
                </li>
                <li>
                    <span class="quien">Quien vende</span>
                    <strong>Líder receptor</strong>
                    <span class="detalle">Avala y dice en qué cuentas presupuestales entran.</span>
                </li>
                <li>
                    <span class="quien">Planeación</span>
                    <strong>Traslado</strong>
                    <span class="detalle">Se hace la transacción presupuestal del cupo.</span>
                </li>
                <li class="fin">
                    <strong>Y ahí arranca la fabricación</strong>
                    <span class="detalle">Antes de la confirmación de Planeación no se compra material.</span>
                </li>
            </ol>
        </div>

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
        form.panel input, form.panel textarea, form.panel select { width:100%; margin-top:.3rem; font-weight:400; }
        form.panel input[type=file] { padding:.5rem; }
        form.panel .foot { display:block; font-weight:400; margin-top:.25rem; }
        form.panel .dos { display:grid; grid-template-columns:repeat(auto-fit,minmax(15rem,1fr)); gap:0 1rem; }

        .condiciones { margin-bottom:1.2rem; }
        .condiciones h3 { margin:0 0 .4rem; font-size:.95rem; }
        .condiciones ul { margin:0; padding-left:1.1rem; font-size:.88rem; }
        .condiciones ul li { margin:.3rem 0; }
        .flujo { margin-bottom:1.2rem; }
        .flujo h3 { margin:0 0 .2rem; font-size:.95rem; }
        .flujo .pasos { list-style:none; margin:0; padding:0;
                        display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:.6rem; }
        .flujo .pasos li { border:1px solid var(--rule); border-left:3px solid var(--accent);
                           border-radius:5px; padding:.6rem .7rem; background:var(--ground); }
        .flujo .pasos li.fin { border-left-color:var(--ok); }
        .flujo .pasos .quien { display:block; font-size:.65rem; letter-spacing:.1em;
                               text-transform:uppercase; color:var(--muted);
                               font-family:ui-monospace,Consolas,monospace; }
        .flujo .pasos strong { display:block; font-size:.9rem; margin:.15rem 0; }
        .flujo .pasos .detalle { font-size:.8rem; color:var(--ink-soft); }

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
        // El circuito de la venta interna solo se enseña a quien le toca. A un
        // estudiante o a una empresa de fuera esa explicación le sobra, y de
        // paso le haría pensar que su encargo también tarda dos semanas.
        (function () {
            const cliente = document.getElementById('cliente');
            const fijo = document.querySelector('input[name="cliente"][type="hidden"]');
            const fecha = document.getElementById('para-cuando');
            const aviso = document.getElementById('aviso-fecha');
            const bloques = document.querySelectorAll('.condiciones');

            const dias = {{ (int) config('fabos.proyectos.dias_minimos_interno') }};

            function minimo() {
                const d = new Date();
                d.setDate(d.getDate() + dias);
                return d.toISOString().slice(0, 10);
            }

            function ajustar() {
                const rol = cliente ? cliente.value : (fijo ? fijo.value : null);

                bloques.forEach(function (b) {
                    b.hidden = b.dataset.rol !== rol;
                });

                if (!fecha) return;

                if (rol === 'interno') {
                    fecha.min = minimo();
                    aviso.textContent = 'Al menos ' + dias + ' días calendario: el traslado presupuestal no se corre más rápido.';
                } else {
                    fecha.removeAttribute('min');
                    aviso.textContent = 'Opcional, pero cambia mucho lo que se puede proponer.';
                }
            }

            if (cliente) cliente.addEventListener('change', ajustar);
            ajustar();
        })();

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
