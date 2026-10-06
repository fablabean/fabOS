@extends('layouts.app')
@section('title', 'Proponer un proyecto · ' . config('fabos.lab.name'))

@section('content')
    <a class="volver" href="{{ route('publico.home') }}">← Volver al inicio</a>

    <h1 style="margin-top:.6rem">Proponer un proyecto</h1>

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
        <p class="foot" style="margin:-.2rem 0 1rem">Lo marcado con <span class="obligatorio" aria-hidden="true">*</span> es obligatorio. Lo demás ayuda, pero puede esperar.</p>

        <label>
            Nombre del proyecto <span class="obligatorio" aria-hidden="true">*</span>
            <input type="text" name="titulo" required maxlength="180"
                   value="{{ old('titulo') }}"
                   placeholder="Señalética para el edificio de Bienestar">
        </label>

        <label>
            De qué se trata <span class="obligatorio" aria-hidden="true">*</span>
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

        {{-- Con qué tiene que ver (§11). Opcional a propósito: quien pide un
             proyecto no siempre sabe con qué máquina se hace —para eso lo
             pide—, y exigirlo sería pedirle que acierte antes de preguntar.
             Cuando lo sabe, decide a qué equipo le llega. --}}
        <label>
            ¿Con qué tiene que ver?
            <select name="area">
                <option value="">No estoy seguro</option>
                @foreach ($areas as $area)
                    <option value="{{ $area->slug }}" @selected(old('area') === $area->slug)>
                        {{ $area->name }}
                    </option>
                @endforeach
            </select>
            <span class="foot">
                Opcional. Si lo sabes, le llega antes a quien lleva esa área; si no, lo
                miramos nosotros.
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
            {{-- Se pregunta la CATEGORÍA de la persona, no el trámite: un
                 profesor no sabe que su encargo «se tramita como interno», pero
                 sí sabe que es profesor. El trámite sale de la categoría, y la
                 cuenta que se crea nace ya con ella, pendiente de confirmar. --}}
            <label>
                ¿Quién eres? <span class="obligatorio" aria-hidden="true">*</span>
                <select name="categoria" id="cliente" required>
                    <option value="" disabled @selected(! old('categoria'))>Elige una opción</option>
                    @foreach ($categorias as $categoria)
                        <option value="{{ $categoria->slug }}"
                                data-tramite="{{ $categoria->tramiteDeCliente() }}"
                                @selected(old('categoria') === $categoria->slug)>
                            {{ $categoria->name }}
                            · {{ match ($categoria->tramiteDeCliente()) {
                                'estudiante' => 'se acuerda contigo y se arranca',
                                'interno'    => 'de la Universidad; si mueve presupuesto, va por traslado',
                                default      => 'de fuera: cotización y contrato',
                            } }}
                        </option>
                    @endforeach
                </select>
                <span class="foot">
                    Cambia el trámite y las condiciones, no el trabajo. Si ya tienes cuenta,
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
                cuatro manos antes de que llegue un peso, y por eso, si el encargo mueve
                presupuesto, hacen falta al menos
                {{ (int) config('fabos.proyectos.dias_presupuesto') }} días calendario. Si no lo
                mueve, la fecha puede ser antes.
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

        {{-- Los que se apartaron cuando el formulario rebotó: un navegador no
             vuelve a llenar un campo de archivo, así que se guardan aquí. --}}
        @php $pendientes = (array) session('soportes_pendientes', []); @endphp
        @if ($pendientes)
            <div class="ya-adjuntos">
                <strong>Ya adjuntos</strong> — se envían con la solicitud. Desmarca los que no quieras.
                @foreach ($pendientes as $p)
                    <label class="adjunto">
                        <input type="checkbox" name="mantener[]" value="{{ $p['token'] }}" checked>
                        <span class="nombre">{{ $p['nombre'] }}</span>
                        <span class="foot">{{ $p['peso'] < 1048576 ? max(1, (int) round($p['peso'] / 1024)) . ' KB' : number_format($p['peso'] / 1048576, 1, ',', '.') . ' MB' }}</span>
                    </label>
                @endforeach
            </div>
        @endif

        <label>
            {{ $pendientes ? 'Agregar más archivos' : 'Archivos de soporte' }}
            <input type="file" name="soportes[]" multiple
                   accept="{{ \App\Services\Projects\SoportesDeSolicitud::accept() }}">
            <span class="foot">
                Hasta {{ \App\Services\Projects\SoportesDeSolicitud::maximo() }} archivos,
                {{ intdiv(\App\Services\Projects\SoportesDeSolicitud::tamanoKb(), 1024) }} MB cada uno.
                Fotos, PDF, planos y vectores (DXF, SVG, AI), modelos 3D (STL, STEP, 3MF, OBJ),
                documentos de oficina o un ZIP con todo.
            </span>
        </label>

        <div class="dibujo">
            <span class="rotulo-campo">O dibújalo aquí</span>

            <canvas id="lienzo" width="900" height="420"></canvas>

            <div class="herramientas">
                <button type="button" id="borrar" class="secundario">Borrar el dibujo</button>
                <span class="foot" id="estado-dibujo">Se manda solo si dibujas algo.</span>
            </div>

            {{-- Si el formulario rebota, el dibujo vuelve al lienzo. --}}
            <input type="hidden" name="dibujo" id="dibujo" value="{{ old('dibujo') }}">
        </div>

        <h2>Quién eres</h2>

        @if ($usuario)
            {{-- A quien ya entró no se le vuelve a preguntar quién es. --}}
            <p class="msg ok" style="margin-top:0">
                Lo pides como <strong>{{ $usuario->name }}</strong> ({{ $usuario->email }}).
                Quedará en <a href="{{ route('home') }}">tu cuenta</a>.
            </p>

            <div class="dos">
                @include('partials.telefono', ['valor' => $usuario->phone])

                <label>
                    Organización
                    <input type="text" name="organizacion" maxlength="160" value="{{ old('organizacion') }}"
                           placeholder="Si escribes a nombre de una empresa o una facultad">
                </label>
            </div>
        @else
            <div class="dos">
                <label>
                    Tu nombre <span class="obligatorio" aria-hidden="true">*</span>
                    <input type="text" name="nombre" required maxlength="120" value="{{ old('nombre') }}">
                </label>

                <label>
                    Correo <span class="obligatorio" aria-hidden="true">*</span>
                    <input type="email" name="correo" required maxlength="180" value="{{ old('correo') }}">
                    <span class="foot">Con este correo se crea tu cuenta y entras sin contraseña.</span>
                </label>

                @include('partials.telefono', ['valor' => null])

                <label>
                    Organización
                    <input type="text" name="organizacion" maxlength="160" value="{{ old('organizacion') }}"
                           placeholder="Si escribes a nombre de una empresa o una facultad">
                </label>
            </div>
        @endif

        {{-- Quién firma. Un proyecto aceptado pasa a contrato, y un contrato se
             firma con alguien concreto: una persona con su cédula o una empresa
             con su NIT y su representante. Pedirlo aquí ahorra el correo de
             después; nada de esto es obligatorio para preguntar. --}}
        {{-- Plegado: abierto parecía parte de lo que hay que llenar, y la
             gente se detenía ahí. Se abre solo si ya trae algo —un rebote—. --}}
        @php
            $hayContrato = collect(['persona', 'documento', 'razon_social', 'representante', 'direccion'])
                ->contains(fn ($c) => filled(old($c)));
        @endphp
        <details class="panel contrato" style="margin-top:1rem" @if ($hayContrato) open @endif>
            <summary>
                <span>Si el proyecto sigue, ¿a nombre de quién iría el contrato?</span>
                <span class="opcional">Opcional</span>
            </summary>
            <p class="foot" style="margin:.6rem 0 .8rem">Nos ahorra un correo después. Si no lo sabes todavía, déjalo: se completa más adelante.</p>

            <div class="dos">
                <label>
                    Pides como
                    <select name="persona" id="persona">
                        <option value="">Todavía no lo sé</option>
                        @foreach (\App\Models\Project::PERSONAS as $clave => $nombre)
                            <option value="{{ $clave }}" @selected(old('persona') === $clave)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Documento
                    <span style="display:flex;gap:.4rem">
                        <select name="documento_tipo" style="max-width:8rem">
                            <option value="">Tipo</option>
                            @foreach (\App\Models\Project::DOCUMENTOS as $clave => $nombre)
                                <option value="{{ $clave }}" @selected(old('documento_tipo') === $clave)>{{ $clave }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="documento" maxlength="40" value="{{ old('documento') }}" placeholder="Número">
                    </span>
                </label>

                <label data-juridica hidden>
                    Razón social
                    <input type="text" name="razon_social" maxlength="180" value="{{ old('razon_social') }}"
                           placeholder="Como aparece en el RUT">
                </label>

                <label data-juridica hidden>
                    Representante legal
                    <input type="text" name="representante" maxlength="120" value="{{ old('representante') }}">
                </label>

                <label>
                    Dirección
                    <input type="text" name="direccion" maxlength="200" value="{{ old('direccion') }}">
                </label>
            </div>
        </details>

        <script>
            // Razón social y representante solo tienen sentido en una empresa.
            (function () {
                const sel = document.getElementById('persona');
                if (! sel) return;
                const pintar = () => document.querySelectorAll('[data-juridica]')
                    .forEach((el) => { el.hidden = sel.value !== 'juridica'; });
                sel.addEventListener('change', pintar);
                pintar();
            })();
        </script>

        <x-captcha accion="proyectos.solicitar.store"/>

        {{-- El error también aquí, al lado del botón: arriba del todo, en
             una página así de larga, quien acababa de pulsar «enviar» no lo
             veía y creía que no había pasado nada. --}}
        @if ($errors->any())
            <div class="msg error" style="margin:.6rem 0">
                <strong>No se envió:</strong> {{ $errors->first() }}
                @if ($errors->count() > 1) (y {{ $errors->count() - 1 }} más, arriba) @endif
            </div>
        @endif

        {{-- Se apaga al pulsarlo: con la red lenta del teléfono, un segundo
             toque mandaba la misma solicitud dos veces. --}}
        <button type="submit" id="enviar-solicitud">Enviar la solicitud</button>

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
        /* El estilo de arriba pone cada label en bloque, y eso le ganaba al
           atributo hidden: razón social y representante salían también a
           una persona natural. */
        form.panel [hidden] { display:none !important; }
        form.panel .obligatorio { color:#b91c1c; font-weight:700; margin-left:.1rem; }

        details.contrato > summary { cursor:pointer; list-style:none; display:flex; gap:.8rem;
                                     justify-content:space-between; align-items:center; font-weight:600; font-size:.92rem; }
        details.contrato > summary::-webkit-details-marker { display:none; }
        details.contrato > summary::before { content:"+"; color:var(--accent); font-weight:700; width:1rem; flex:none; }
        details.contrato[open] > summary::before { content:"−"; }
        details.contrato > summary > span:first-child { flex:1; }
        details.contrato .opcional { font-size:.68rem; letter-spacing:.12em; text-transform:uppercase; font-weight:600;
                                     color:var(--muted); border:1px solid var(--rule); border-radius:999px; padding:.1rem .55rem; }
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
        .ya-adjuntos { margin:0 0 1rem; padding:.7rem .9rem; border-left:3px solid var(--ok);
                       background:color-mix(in srgb, var(--ok) 8%, transparent); font-size:.9rem; }
        .ya-adjuntos .adjunto { display:flex; gap:.5rem; align-items:center; margin:.4rem 0 0;
                                font-family:inherit; font-size:.9rem; letter-spacing:0; text-transform:none; color:var(--ink); }
        .ya-adjuntos .foot { margin:0; }
        /* El estilo general pone cada input al 100 % y cada label en bloque:
           la casilla se estiraba a todo el ancho y empujaba el nombre fuera. */
        form.panel .ya-adjuntos .adjunto { display:flex; font-weight:400; margin:.4rem 0 0; }
        form.panel .ya-adjuntos .adjunto input { width:auto; margin:0; flex:none; }
        form.panel .ya-adjuntos .adjunto .nombre { overflow-wrap:anywhere; }
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

            // Los dias que se exigen a cada tipo de cliente, y los que tarda
            // el traslado presupuestal de la Universidad.
            const minimos = @json(config('fabos.proyectos.dias_minimos'));
            const presupuesto = {{ (int) config('fabos.proyectos.dias_presupuesto') }};

            function enDias(n) {
                const d = new Date();
                d.setDate(d.getDate() + n);
                return d.toISOString().slice(0, 10);
            }

            function ajustar() {
                // El desplegable lista categorias; el tramite viene en cada
                // opcion. Con sesion, el tramite ya esta fijo.
                const opcion = cliente ? cliente.selectedOptions[0] : null;
                const rol = opcion
                    ? (opcion.dataset.tramite || opcion.value || null)
                    : (fijo ? fijo.value : null);

                bloques.forEach(function (b) {
                    b.hidden = b.dataset.rol !== rol;
                });

                if (!fecha) return;

                const dias = minimos[rol] || 0;

                if (dias > 0) {
                    fecha.min = enDias(dias);
                } else {
                    fecha.removeAttribute('min');
                }

                if (rol === 'interno') {
                    // No se le exige minimo, porque no todo encargo interno
                    // mueve presupuesto. Pero si lo mueve, el traslado tiene
                    // sus tiempos, y conviene saberlo antes de pedir.
                    const antes = fecha.value && fecha.value < enDias(presupuesto);
                    aviso.textContent = (antes ? 'Ojo: ' : '')
                        + 'si el proyecto exige presupuesto, hay que cumplir los tiempos de la Universidad: '
                        + 'el traslado presupuestal necesita al menos ' + presupuesto + ' días calendario. '
                        + 'Sin presupuesto de por medio, puede ser antes.';
                } else if (dias > 0) {
                    aviso.textContent = 'Al menos ' + dias + ' días calendario' + (rol === 'externo'
                        ? ': hay cotización, contrato y compra de material.'
                        : '.') + ' Opcional, pero cambia mucho lo que se puede proponer.';
                } else {
                    aviso.textContent = 'Opcional, pero cambia mucho lo que se puede proponer.';
                }
            }

            if (cliente) cliente.addEventListener('change', ajustar);
            if (fecha) fecha.addEventListener('change', ajustar);
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

            // El dibujo de un intento anterior, si el formulario rebotó.
            if (campo.value.startsWith('data:image/png;base64,')) {
                const previo = new Image();
                previo.onload = function () {
                    ctx.drawImage(previo, 0, 0, lienzo.width, lienzo.height);
                    hayDibujo = true;
                    estado.textContent = 'Tu dibujo sigue aquí: se enviará con la solicitud.';
                };
                previo.src = campo.value;
            }

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

                const boton = document.getElementById('enviar-solicitud');
                boton.disabled = true;
                boton.textContent = 'Enviando…';
            });
        })();
    </script>
@endsection
