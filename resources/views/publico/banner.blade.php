{{--
    El banner de la portada (§3, portal público).

    Lo que se ve aquí sale de la tabla `banners`, que se edita en
    *Comunicaciones → Banner de la portada*. Si esa tabla está vacía —o si todas
    las láminas caducaron el mismo día— el modelo se cae de pie a las de
    `config/fabos.php`: una portada que de pronto no dice qué es este sitio sería
    peor que una lámina vieja.
--}}
<div class="hero" id="hero" style="--intervalo:{{ $intervalo ?? 7 }}s">
    {{-- Los fondos, apilados. Van de fondo y no como <img> porque son
         decorativos: no cuentan nada que el texto no diga. --}}
    <div class="laminas" aria-hidden="true">
        @foreach ($laminas as $i => $l)
            @php
                // El velo solo tiene sentido sobre una foto o un video. Sobre un
                // color plano oscurecería el color que alguien acaba de elegir.
                $velo  = $l->fondo_tipo === 'color' ? 0 : $l->velo / 100;
                // El video se acompaña de su cartel como fondo: es lo que se ve
                // mientras carga, y lo que se queda si no llega a reproducirse.
                $fondo = $l->esVideo() ? $l->posterUrl() : $l->fondoUrl();

                $estilo = collect([
                    '--velo:' . $velo,
                    '--pos:' . ($l->fondo_pos ?: 'center'),
                    $l->fondo_color ? '--color:' . $l->fondo_color : null,
                    $fondo ? "background-image:url('{$fondo}')" : null,
                ])->filter()->implode(';');
            @endphp
            <div class="lamina {{ $i === 0 ? 'activa' : '' }} {{ $l->alineacion === 'centro' ? 'centro' : '' }}"
                 data-lamina="{{ $i }}"
                 style="{{ $estilo }}">
                @if ($l->esVideo())
                    {{-- Sin `autoplay`: lo arranca el script cuando la lámina
                         entra, y solo esa. Seis videos reproduciéndose a la vez
                         detrás de un texto es lo que funde la batería de un
                         teléfono. El cartel se ve mientras tanto, y se queda si
                         el navegador se niega a reproducir. --}}
                    <video muted loop playsinline preload="none"
                           @if ($l->posterUrl()) poster="{{ $l->posterUrl() }}" @endif
                           src="{{ $l->fondoUrl() }}"></video>
                @endif
            </div>
        @endforeach
    </div>

    <div class="in {{ $laminas->first()?->alineacion === 'centro' ? 'centro' : '' }}" data-caja>
        <div class="texto">
            @foreach ($laminas as $i => $l)
                <div class="diapo efecto-{{ $l->efecto ?: 'ninguno' }} {{ $i === 0 ? 'activa' : '' }}"
                     data-diapo="{{ $i }}"
                     data-efecto="{{ $l->efecto ?: 'ninguno' }}"
                     data-alineacion="{{ $l->alineacion }}">
                    <p class="rotulo">{{ $l->rotuloVisible() }}</p>

                    {{-- El título lo escapa el modelo y solo interpreta los
                         asteriscos: lo que se escribe en el editor es texto, y
                         un <script> escrito ahí sale en pantalla como letras. --}}
                    <h1>{!! $l->tituloHtml() !!}</h1>

                    @if ($l->texto)
                        <p class="dice">{{ $l->texto }}</p>
                    @endif

                    <div class="acciones">
                        @forelse ($l->acciones() as $a)
                            {{-- Lo que sale del sitio, en pestaña nueva: quien
                                 lo pulsa venía mirando el laboratorio. Y con
                                 `noopener`, que la página de destino no pueda
                                 tocar la nuestra desde su javascript. --}}
                            <a class="btn {{ $loop->first ? 'claro' : 'borde' }}"
                               href="{{ $a['url'] }}"
                               @if ($a['fuera']) target="_blank" rel="noopener noreferrer" @endif>{{ $a['texto'] }}</a>
                        @empty
                            {{-- Sin botones propios, los de siempre: una lámina
                                 sin salida deja al visitante mirando. --}}
                            <a class="btn claro" href="{{ route('publico.reservas') }}">Ver los equipos</a>
                            <a class="btn borde" href="{{ route('proyectos.solicitar') }}">Proponer un proyecto</a>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        @if ($laminas->count() > 1)
            <div class="puntos" role="tablist" aria-label="Qué hace el laboratorio">
                @foreach ($laminas as $i => $l)
                    <button type="button" role="tab"
                            data-punto="{{ $i }}"
                            aria-current="{{ $i === 0 ? 'true' : 'false' }}"
                            aria-label="{{ $l->rotuloVisible() }}"></button>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- La franja de cifras: fuera del banner a propósito. Son una prueba, no un
     titular; dentro competían con la frase principal. --}}
<div class="cifras">
    <div class="cifra"><b>{{ $cifras['equipos'] }}</b><span>equipos</span></div>
    <div class="cifra"><b>{{ $cifras['libres'] }}</b><span>libres ahora</span></div>
    <div class="cifra"><b>{{ $cifras['areas'] }}</b><span>áreas</span></div>
    {{-- Una cifra pequeña resta en vez de sumar: «1 persona habilitada»
         comunica lo contrario de lo que se quiere. Aparece sola cuando ya
         cuenta una historia; el umbral vive en config/fabos.php. --}}
    @if ($cifras['personas'] >= config('fabos.showcase.min_personas'))
        <div class="cifra"><b>{{ $cifras['personas'] }}</b><span>personas habilitadas</span></div>
    @endif
    <div class="cifra"><b>Fab</b><span>Academy acreditado</span></div>
</div>

{{--
    La rotación del banner y los efectos del título.

    Sin dependencias, y con los cuidados de siempre: se detiene al pasar el
    ratón o al enfocar con el teclado —para poder leer sin que se escape—, se
    detiene si la pestaña queda en segundo plano, y quien pide menos movimiento
    lo recibe todo quieto y completo. Lo que se quita entonces es la animación,
    nunca el contenido.
--}}
<script>
(function () {
    const hero = document.getElementById('hero');
    if (! hero) return;

    const laminas = hero.querySelectorAll('[data-lamina]');
    const diapos  = hero.querySelectorAll('[data-diapo]');
    const puntos  = hero.querySelectorAll('[data-punto]');
    const caja    = hero.querySelector('[data-caja]');

    // Que el script está vivo. Hay un efecto —el brillo— que empieza con el
    // título escondido, y sin esto se quedaría invisible si el script falla.
    hero.classList.add('js');

    const quieto = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /*
     * Partir el título en palabras o en letras.
     *
     * Se camina el árbol en vez de tocar el HTML como texto: dentro del título
     * hay un <em> —lo resaltado— y reconstruirlo a mano con expresiones
     * regulares es como se acaba imprimiendo la etiqueta en pantalla.
     *
     * Cada unidad lleva su número en --i, y de ahí sale el escalonado: el
     * retardo lo calcula el CSS, no un temporizador.
     */
    function partir(elemento, unidad) {
        if (elemento.dataset.partido) return;

        let n = 0;

        const recorrer = (origen, destino) => {
            [...origen.childNodes].forEach((hijo) => {
                if (hijo.nodeType === Node.TEXT_NODE) {
                    const trozos = unidad === 'letra'
                        ? [...hijo.textContent]
                        : hijo.textContent.split(/(\s+)/);

                    trozos.forEach((trozo) => {
                        if (trozo === '') return;

                        // Los espacios se quedan como texto suelto: envueltos
                        // en un inline-block, la línea deja de poder partirse
                        // y el título se sale de la pantalla en un teléfono.
                        if (/^\s+$/.test(trozo)) {
                            destino.appendChild(document.createTextNode(trozo));
                            return;
                        }

                        const u = document.createElement('span');
                        u.className = 'u';
                        u.style.setProperty('--i', n++);

                        if (unidad === 'letra') {
                            u.textContent = trozo;
                        } else {
                            // La máscara va en el <span> y el movimiento en el
                            // <i>: un elemento no puede recortarse a sí mismo
                            // mientras se mueve.
                            const i = document.createElement('i');
                            i.textContent = trozo;
                            u.appendChild(i);
                        }

                        destino.appendChild(u);
                    });

                    return;
                }

                if (hijo.nodeType === Node.ELEMENT_NODE) {
                    const copia = hijo.cloneNode(false);
                    recorrer(hijo, copia);
                    destino.appendChild(copia);
                }
            });
        };

        const nuevo = document.createDocumentFragment();
        recorrer(elemento, nuevo);
        elemento.textContent = '';
        elemento.appendChild(nuevo);
        elemento.dataset.partido = '1';
    }

    /** Máquina de escribir: se descubre letra a letra, con el cursor detrás. */
    function escribir(diapo) {
        const titulo = diapo.querySelector('h1');
        const letras = [...titulo.querySelectorAll('.u')];
        if (! letras.length) return;

        let cursor = diapo.querySelector('.cursor');
        if (! cursor) {
            cursor = document.createElement('span');
            cursor.className = 'cursor';
            cursor.setAttribute('aria-hidden', 'true');
        }

        letras.forEach((l) => l.classList.add('oculta'));
        titulo.appendChild(cursor);

        clearInterval(diapo.escribiendo);

        let n = 0;
        diapo.escribiendo = setInterval(() => {
            if (n >= letras.length) {
                clearInterval(diapo.escribiendo);
                return;
            }

            letras[n].classList.remove('oculta');
            letras[n].after(cursor);
            n++;
        }, 45);
    }

    /** Vuelve a lanzar el efecto de una lámina desde el principio. */
    function animar(diapo) {
        if (quieto) return;

        const efecto = diapo.dataset.efecto;

        if (efecto === 'maquina') {
            partir(diapo.querySelector('h1'), 'letra');
        } else if (['subir', 'cortina', 'desenfoque'].includes(efecto)) {
            partir(diapo.querySelector('h1'), 'palabra');
        }

        // Quitar y volver a poner la clase no basta: el navegador junta los dos
        // cambios y la animación no se entera. El reflow de por medio la
        // obliga a empezar de cero.
        diapo.classList.remove('anima');
        void diapo.offsetWidth;
        diapo.classList.add('anima');

        if (efecto === 'maquina') escribir(diapo);
    }

    const INTERVALO = {{ ($intervalo ?? 7) * 1000 }};
    let actual = 0;
    let reloj = null;

    function mostrar(i) {
        actual = (i + diapos.length) % diapos.length;

        laminas.forEach((l, n) => {
            l.classList.toggle('activa', n === actual);

            // Solo se reproduce el video que se está viendo. Los demás se
            // paran y vuelven al principio: la lámina siempre empieza igual.
            const video = l.querySelector('video');
            if (! video) return;

            if (n === actual && ! quieto) {
                video.play().catch(() => {});
            } else {
                video.pause();
                video.currentTime = 0;
            }
        });

        diapos.forEach((d, n) => {
            d.classList.toggle('activa', n === actual);

            if (n === actual) {
                animar(d);
            } else {
                clearInterval(d.escribiendo);
                d.classList.remove('anima');
            }
        });

        puntos.forEach((p, n) => p.setAttribute('aria-current', n === actual ? 'true' : 'false'));

        // La alineación es de cada lámina, no del banner.
        if (caja) caja.classList.toggle('centro', diapos[actual].dataset.alineacion === 'centro');
    }

    function arrancar() {
        detener();
        reiniciarLaBarra();
        reloj = setInterval(() => mostrar(actual + 1), INTERVALO);
        hero.classList.remove('quieto');
    }

    function detener() {
        if (reloj) { clearInterval(reloj); reloj = null; }
        hero.classList.add('quieto');
    }

    /*
     * La barra del punto activo mide lo que falta para el cambio. Al reanudar
     * se reinicia el temporizador entero, así que la barra tiene que volver a
     * empezar con él: si no, se llena antes de que la lámina cambie y deja de
     * decir la verdad. No se puede tocar por estilo —es un ::after—, así que se
     * quita y se repone el atributo que la dispara.
     */
    function reiniciarLaBarra() {
        const punto = puntos[actual];
        if (! punto) return;

        punto.setAttribute('aria-current', 'false');
        void punto.offsetWidth;
        punto.setAttribute('aria-current', 'true');
    }

    // La primera se anima igual aunque sea la única: es la que se lee.
    mostrar(0);

    // Con una sola lámina no hay nada que rotar, y por tanto nada que detener:
    // sin esta salida, el reloj volvía a lanzar el efecto de la misma lámina
    // cada siete segundos.
    if (diapos.length < 2) return;

    puntos.forEach((p, n) => p.addEventListener('click', () => { mostrar(n); arrancar(); }));

    hero.addEventListener('mouseenter', detener);
    hero.addEventListener('mouseleave', arrancar);
    hero.addEventListener('focusin', detener);
    hero.addEventListener('focusout', arrancar);

    document.addEventListener('visibilitychange', () => document.hidden ? detener() : arrancar());

    arrancar();
})();
</script>
