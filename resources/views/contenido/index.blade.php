@extends('layouts.app')
@section('title', 'Aportes · ' . config('fabos.lab.name'))

@section('content')
    <h1>Tus aportes al laboratorio</h1>

    <p class="help">
        Una pieza saliendo de la impresora, el primer corte que salió bien, alguien
        explicando cómo lo hizo. Se toma con la cámara y queda guardado aquí mismo,
        con tu nombre: lo que documenta el laboratorio lo aporta alguien.
    </p>

    @if ($aportes > 0)
        {{-- Lo ganado se cuenta sobre TODOS sus aportes y no sobre los que
             caben en la galería: este número tiene que cuadrar con su saldo. --}}
        <p class="resumen">
            <strong>{{ $aportes }}</strong> {{ $aportes === 1 ? 'aporte' : 'aportes' }}
            @if ($ganado > 0)
                · te han reconocido
                <strong>{{ number_format($ganado / config('fabos.currency.minor_units'), 2, ',', '.') }}
                {{ config('fabos.currency.code') }}</strong>
            @endif
        </p>
    @endif

    @php
        $aProyecto = session('aProyecto')
            ?? $proyectos->firstWhere('id', request()->integer('proyecto'))?->name;
    @endphp

    @if ($subidos > 0)
        <div class="msg ok">
            <strong>{{ $subidos }}
            {{ $subidos == 1 ? 'archivo guardado' : 'archivos guardados' }}.</strong>
            @if ($aProyecto)
                Quedaron con «{{ $aProyecto }}».
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

        {{-- Lo elegido se acumula aquí: varias fotos seguidas con la cámara,
             una tanda de la galería y otra del computador van al mismo lote. --}}
        <ul class="lista" id="lista" hidden></ul>

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

        @if (config('fabos.contenido.reconocimiento_minor') > 0)
            {{-- Se dice lo que es y no se promete lo que no es: el
                 reconocimiento lo decide el laboratorio mirando el aporte. Un
                 «gana X por foto» convertiria esto en subir por subir. --}}
            <p class="foot" style="margin-top:.7rem">
                El laboratorio puede reconocer un aporte con
                {{ config('fabos.currency.name') }}s: no por subir, sino cuando lo que
                subiste sirve para contar lo que aquí se hace. Ponle título y ligalo a
                tu proyecto — así se entiende qué es sin tener que abrirlo.
            </p>
        @endif

        <p class="foot" style="margin-top:.7rem">
            Puedes subir muchos a la vez: se suman a la lista cada vez que tomas o eliges,
            y suben de uno en uno, hasta {{ $maxMb }} MB cada uno. El título, el proyecto y
            la autorización valen para todo el lote. No cierres la página mientras suben.
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
                        @if ($pieza->estaReconocido())
                            <span class="reconocido">
                                +{{ number_format($pieza->recognized_minor / config('fabos.currency.minor_units'), 2, ',', '.') }}
                                {{ config('fabos.currency.code') }}
                            </span>
                        @endif
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
        .galeria .reconocido { display:inline-block; margin-top:.15rem; font-weight:700;
                               font-size:.72rem; color:var(--accent); }

        .resumen { margin:-.4rem 0 1.2rem; font-size:.92rem; color:var(--ink-soft); }

        #captura .lista { list-style:none; margin:0 0 1rem; padding:0; display:grid; gap:.35rem;
                          max-height:22rem; overflow-y:auto; }
        #captura .lista li { display:grid; grid-template-columns:2.6rem minmax(0,1fr) auto; gap:.6rem;
                             align-items:center; padding:.3rem .4rem; border:1px solid var(--rule);
                             border-radius:6px; font-size:.82rem; }
        #captura .lista .mini { width:2.6rem; height:2.6rem; border-radius:4px; object-fit:cover;
                                background:var(--surface); display:flex; align-items:center;
                                justify-content:center; font-size:1.1rem; }
        #captura .lista .nombre { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        #captura .lista .estado { display:block; color:var(--muted); font-size:.74rem; }
        #captura .lista li.listo .estado { color:var(--accent); font-weight:600; }
        #captura .lista li.error .estado,
        #captura .lista li.rechazado .estado { color:var(--bad); font-weight:600; }
        #captura .lista .quitar { background:none; border:0; color:var(--muted); cursor:pointer;
                                  font-size:1.2rem; padding:.2rem .5rem; line-height:1; }
    </style>

    <script>
        // El lote: lo elegido se acumula en una lista y sube de a un archivo por
        // petición. Todo junto en un solo envío chocaba con el tope del túnel
        // —100 MB por petición, no por archivo—: diez fotos de teléfono o dos
        // videos y fallaba al final, sin decir por qué. Y la cámara del
        // teléfono entrega una foto cada vez: sin acumular, la segunda
        // reemplazaba a la primera.
        //
        // Sin JavaScript el formulario sigue funcionando como antes.
        (function () {
            const formulario = document.getElementById('captura');
            if (!formulario || !window.FormData || !window.XMLHttpRequest) return;

            const lista = document.getElementById('lista');
            const aviso = document.getElementById('elegidos');
            const enviar = document.getElementById('enviar');
            const maxMb = {{ $maxMb }};
            const tipos = @json(array_merge(\App\Services\Contenido\BancoDeContenido::TIPOS_FOTO, \App\Services\Contenido\BancoDeContenido::TIPOS_VIDEO));
            const TOPE = 100;

            let cola = [];
            let guardados = 0;
            let subiendo = false;

            function peso(b) {
                return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
            }

            function porSubir() {
                return cola.filter(i => i.estado === 'pendiente' || i.estado === 'error');
            }

            function marcar(item, estado, texto) {
                item.estado = estado;
                item.li.className = estado;
                item.li.querySelector('.estado').textContent = texto;
                item.li.querySelector('.quitar').hidden = estado === 'subiendo' || estado === 'listo';
            }

            function resumen() {
                const turno = porSubir();
                lista.hidden = cola.length === 0;
                aviso.hidden = cola.length === 0;
                aviso.textContent = turno.length === 1
                    ? '1 archivo para subir (' + peso(turno[0].file.size) + ').'
                    : turno.length + ' archivos para subir (' + peso(turno.reduce((t, i) => t + i.file.size, 0)) + ').';
                if (!subiendo) {
                    enviar.textContent = turno.length > 1 ? 'Subir los ' + turno.length : 'Subir';
                }
            }

            function agregar(file) {
                if (cola.some(i => i.file.name === file.name && i.file.size === file.size && i.file.lastModified === file.lastModified)) return;
                if (porSubir().length >= TOPE) return false;

                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const esImagen = /^image\/(jpeg|png|webp|gif)$/.test(file.type);

                const li = document.createElement('li');
                const mini = document.createElement(esImagen ? 'img' : 'span');
                mini.className = 'mini';
                if (esImagen) {
                    mini.src = URL.createObjectURL(file);
                    mini.alt = '';
                } else {
                    mini.textContent = file.type.startsWith('video/') ? '🎥' : '📷';
                }

                const texto = document.createElement('span');
                const nombre = document.createElement('span');
                nombre.className = 'nombre';
                nombre.textContent = file.name;
                const estado = document.createElement('span');
                estado.className = 'estado';
                texto.append(nombre, estado);

                const quitar = document.createElement('button');
                quitar.type = 'button';
                quitar.className = 'quitar';
                quitar.title = 'Quitar de la lista';
                quitar.setAttribute('aria-label', 'Quitar ' + file.name);
                quitar.textContent = '×';

                li.append(mini, texto, quitar);
                lista.append(li);

                const item = {file: file, li: li, estado: 'pendiente'};
                cola.push(item);

                quitar.addEventListener('click', function () {
                    cola = cola.filter(i => i !== item);
                    li.remove();
                    resumen();
                });

                if (!tipos.includes(extension)) {
                    marcar(item, 'rechazado', 'Solo fotos y videos: este no se sube.');
                } else if (file.size > maxMb * 1048576) {
                    marcar(item, 'rechazado', 'Pesa ' + peso(file.size) + ' y el tope es ' + maxMb + ' MB: no se sube.');
                } else {
                    marcar(item, 'pendiente', peso(file.size) + ' · en espera');
                }
            }

            formulario.querySelectorAll('input[type=file]').forEach(function (campo) {
                campo.addEventListener('change', function () {
                    const sobran = Array.from(campo.files).filter(f => agregar(f) === false).length;
                    // Vacío, para que la próxima foto se sume y no reemplace.
                    campo.value = '';
                    resumen();
                    if (sobran) {
                        aviso.textContent += ' Quedaron fuera ' + sobran + ': hasta ' + TOPE + ' por lote. Sube estos y luego sigue.';
                    }
                });
            });

            // Un archivo, una petición. Devuelve null si salió bien, o el
            // motivo; «fatal» para lo que tumbaría también a los siguientes.
            function subir(item) {
                return new Promise(function (listo) {
                    const datos = new FormData();
                    formulario.querySelectorAll('input:not([type=file]), select, textarea').forEach(function (c) {
                        if (!c.name || (c.type === 'checkbox' && !c.checked)) return;
                        datos.append(c.name, c.value);
                    });
                    datos.append('archivos[]', item.file, item.file.name);

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', formulario.action);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    xhr.upload.onprogress = function (e) {
                        if (e.lengthComputable) {
                            marcar(item, 'subiendo', 'Subiendo… ' + Math.round(e.loaded / e.total * 100) + ' %');
                        }
                    };

                    xhr.onload = function () {
                        if (xhr.status >= 200 && xhr.status < 300) return listo(null);

                        let errores = {};
                        let mensaje = '';
                        try {
                            const r = JSON.parse(xhr.responseText);
                            errores = r.errors || {};
                            mensaje = r.message || '';
                        } catch (e) {}

                        const campos = Object.keys(errores);
                        const delLote = campos.find(c => !c.startsWith('archivos'));

                        if (xhr.status === 419) return listo({fatal: true, texto: 'La sesión venció: recarga la página y vuelve a elegir lo que falta.'});
                        if (xhr.status === 429) return listo({fatal: true, texto: 'Demasiadas subidas seguidas: espera un rato y dale a reintentar.'});
                        if (xhr.status === 413) return listo({texto: 'Pesa más de lo que deja pasar el servidor.'});
                        // La autorización o el proyecto: fallarían igual en todos.
                        if (delLote) return listo({fatal: true, texto: errores[delLote][0]});
                        if (campos.length) return listo({texto: errores[campos[0]][0]});

                        listo({texto: mensaje || 'No se pudo subir (error ' + xhr.status + ').'});
                    };

                    xhr.onerror = function () {
                        listo({texto: 'Se cortó la conexión.'});
                    };

                    marcar(item, 'subiendo', 'Subiendo…');
                    xhr.send(datos);
                });
            }

            window.addEventListener('beforeunload', function (e) {
                if (subiendo) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            formulario.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (subiendo) return;

                const turno = porSubir();
                if (!turno.length) {
                    aviso.hidden = false;
                    aviso.textContent = 'Elige una foto o un video, o tómalo con la cámara.';
                    return;
                }

                subiendo = true;
                enviar.disabled = true;
                let fatal = null;

                for (let n = 0; n < turno.length; n++) {
                    enviar.textContent = 'Subiendo ' + (n + 1) + ' de ' + turno.length + '…';
                    const fallo = await subir(turno[n]);

                    if (!fallo) {
                        guardados++;
                        marcar(turno[n], 'listo', '✓ Guardado');
                        continue;
                    }

                    marcar(turno[n], 'error', fallo.texto);
                    if (fallo.fatal) {
                        fatal = fallo.texto;
                        break;
                    }
                }

                subiendo = false;
                enviar.disabled = false;

                const quedan = porSubir();

                // Todo arriba: a la galería, que ya lo enseña.
                if (!quedan.length && !fatal) {
                    const p = formulario.querySelector('select[name=project_id]');
                    const destino = new URL(formulario.action, location.href);
                    destino.searchParams.set('subidos', guardados);
                    if (p && p.value) destino.searchParams.set('proyecto', p.value);
                    location.href = destino.toString();
                    return;
                }

                // Algo falló: lo guardado queda marcado y lo demás espera a
                // que se reintente, sin volver a subir lo que ya está.
                aviso.hidden = false;
                aviso.textContent = (guardados ? guardados + ' guardados. ' : '')
                    + (fatal || (quedan.length + ' no se pudieron subir: el motivo está en la lista.'));
                enviar.textContent = quedan.length === 1 ? 'Reintentar el que falta' : 'Reintentar los ' + quedan.length + ' que faltan';
            });
        })();
    </script>
@endsection
