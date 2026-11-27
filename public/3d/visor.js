/*
 * Galería 3D de fabOS: el visor, la colección y la realidad aumentada.
 *
 * Un solo módulo sin compilar, con three.js servido desde
 * /3d/vendor/three-r186 (mapa de importación en la página). Así no
 * depende de Vite ni de ningún CDN: lo que está en el repositorio es lo que
 * corre en el navegador.
 *
 * Decisiones que conviene saber antes de tocarlo:
 *
 *  - Solo se redibuja cuando algo cambia (el usuario gira, hay animación,
 *    gira solo). Con el modelo quieto la tarjeta gráfica descansa y el
 *    teléfono no se calienta. Fuera de pantalla o con la pestaña oculta, el
 *    bucle se detiene del todo.
 *  - Cambiar de modelo no recarga la página: se carga el nuevo, se libera
 *    la memoria del anterior y la dirección cambia con history.pushState,
 *    para que «atrás» y compartir el enlace funcionen como se espera.
 *  - Realidad aumentada, por orden: iPhone/iPad → Vista rápida (.usdz);
 *    Android con WebXR → en la propia página, con los materiales elegidos;
 *    Android sin WebXR → Scene Viewer de Google (-ar.glb); computador → QR.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import { ajustes, MedidorDeCuadros } from 'fabos/capacidad';

const $ = (id) => document.getElementById(id);
const datos = JSON.parse($('galeria-datos').textContent);
const MODELOS = datos.modelos;
const porSlug = Object.fromEntries(MODELOS.map((m) => [m.slug, m]));
const reducirMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const numero = (n) => (n ?? 0).toLocaleString('es-CO');
const peso = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.round(b / 1024).toLocaleString('es-CO')} KB`);
const segundos = (ms) => `${(ms / 1000).toFixed(2).replace('.', ',')} s`;
const sinTildes = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const colorCSS = (nombre, otro) => getComputedStyle(document.documentElement).getPropertyValue(nombre).trim() || otro;

function hayWebGL() {
    try {
        const c = document.createElement('canvas');
        return !!(window.WebGL2RenderingContext && c.getContext('webgl2')) || !!c.getContext('webgl');
    } catch (e) {
        return false;
    }
}

/* ================================================================== */
/* Entornos de luz                                                      */
/* ================================================================== */

/** Un cielo en degradado con un sol: para la luz «exterior» y «atardecer». */
function escenaCielo({ cenit, horizonte, suelo, sol, dirSol, intensidad }) {
    const escena = new THREE.Scene();
    const material = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        depthWrite: false,
        uniforms: {
            cenit: { value: new THREE.Color(cenit) },
            horizonte: { value: new THREE.Color(horizonte) },
            suelo: { value: new THREE.Color(suelo) },
        },
        vertexShader: 'varying vec3 vDir; void main(){ vDir = normalize(position); gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
        fragmentShader: `uniform vec3 cenit; uniform vec3 horizonte; uniform vec3 suelo; varying vec3 vDir;
            void main(){ float h = vDir.y;
                vec3 c = h > 0.0 ? mix(horizonte, cenit, pow(h, 0.6)) : mix(horizonte, suelo, pow(-h, 0.35));
                gl_FragColor = vec4(c, 1.0); }`,
    });
    escena.add(new THREE.Mesh(new THREE.SphereGeometry(10, 32, 16), material));
    const disco = new THREE.Mesh(new THREE.SphereGeometry(0.7, 16, 8), new THREE.MeshBasicMaterial({ color: new THREE.Color(sol).multiplyScalar(intensidad) }));
    disco.position.copy(new THREE.Vector3(...dirSol).normalize().multiplyScalar(8));
    escena.add(disco);
    return escena;
}

const LUCES = {
    estudio: { dir: [0.6, 1.4, 0.9], intensidad: 1.1, crear: () => new RoomEnvironment() },
    exterior: {
        dir: [0.5, 0.85, 0.3], intensidad: 1.6,
        crear: () => escenaCielo({ cenit: '#5f97d6', horizonte: '#dfe8ee', suelo: '#6f6a5c', sol: '#fff3dc', dirSol: [0.5, 0.85, 0.3], intensidad: 40 }),
    },
    atardecer: {
        dir: [0.9, 0.22, -0.35], intensidad: 1.4,
        crear: () => escenaCielo({ cenit: '#283766', horizonte: '#f0a25b', suelo: '#3b2f2b', sol: '#ffb067', dirSol: [0.9, 0.22, -0.35], intensidad: 30 }),
    },
};

/* ================================================================== */
/* El visor                                                             */
/* ================================================================== */

class Visor {
    constructor(contenedor) {
        this.contenedor = contenedor;
        this.poster = $('visor-poster');
        this.barra = $('visor-barra');
        this.aviso = $('visor-aviso');
        this.token = 0;
        this.sucio = true;
        this.animando = true;
        this.modo = 'realista';
        this.luz = 'estudio';
        this.entornos = {};
        this.fps = { cuadros: 0, desde: performance.now(), valor: 0 };

        // Resolución, suavizado de bordes y sombras según la gama (capacidad.js).
        const r = (this.renderer = new THREE.WebGLRenderer({ antialias: ajustes.antialias, alpha: true, powerPreference: 'high-performance' }));
        r.setPixelRatio(Math.min(window.devicePixelRatio || 1, ajustes.pixelRatio));
        r.outputColorSpace = THREE.SRGBColorSpace;
        r.toneMapping = THREE.NeutralToneMapping;
        r.toneMappingExposure = 1;
        r.shadowMap.enabled = true;
        r.shadowMap.type = THREE.PCFShadowMap;
        r.xr.enabled = true;
        r.domElement.setAttribute('aria-hidden', 'true');
        contenedor.prepend(r.domElement);

        this.escena = new THREE.Scene();
        this.camara = new THREE.PerspectiveCamera(35, 1, 0.001, 100);
        this.pmrem = new THREE.PMREMGenerator(r);

        // La luz que proyecta la sombra; el resto de la luz la da el entorno.
        this.sol = new THREE.DirectionalLight(0xffffff, 1.1);
        this.sol.castShadow = true;
        this.sol.shadow.mapSize.set(ajustes.sombra, ajustes.sombra);
        this.sol.shadow.radius = 5;
        this.sol.shadow.bias = -0.0004;
        this.escena.add(this.sol, this.sol.target);

        this.suelo = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.ShadowMaterial({ opacity: 0.2 }));
        this.suelo.rotation.x = -Math.PI / 2;
        this.suelo.receiveShadow = true;
        this.suelo.name = 'Sombra';
        this.escena.add(this.suelo);

        const c = (this.controles = new OrbitControls(this.camara, r.domElement));
        c.enableDamping = true;
        c.dampingFactor = 0.08;
        c.rotateSpeed = 0.9;
        c.zoomSpeed = 0.9;
        c.autoRotateSpeed = 1.6;
        c.screenSpacePanning = true;
        c.maxPolarAngle = Math.PI * 0.56;   // un poco por debajo del suelo, no más
        c.addEventListener('change', () => (this.sucio = true));
        c.addEventListener('start', () => {
            $('visor-ayuda').classList.add('oculta');
            this.zoom = null;
        });

        this.reloj = new THREE.Timer();
        this.ponerLuz('estudio');

        new ResizeObserver(() => this.ajustar()).observe(contenedor);
        this.ajustar();

        // Fuera de pantalla o con la pestaña oculta, no se dibuja nada.
        this.visible = true;
        new IntersectionObserver(([e]) => { this.visible = e.isIntersecting; this.bucle(); }).observe(contenedor);
        document.addEventListener('visibilitychange', () => this.bucle());
        r.domElement.addEventListener('webglcontextlost', (e) => {
            e.preventDefault();
            this.mostrarAviso('El navegador liberó la memoria gráfica. <button type="button" class="btn" data-recargar>Volver a cargar</button>');
        });
        // Si la devuelve sola (pasa al volver de otra app), se recarga la figura sin pedir nada.
        r.domElement.addEventListener('webglcontextrestored', () => {
            if (this.datos) this.cargar(this.datos);
        });
        this.bucle();
        this.teclado();
    }

    /* ---------- bucle de dibujo ---------- */

    bucle() {
        const activo = this.renderer.xr.isPresenting || (this.visible && document.visibilityState === 'visible');
        this.renderer.setAnimationLoop(activo ? (t, frame) => this.cuadro(t, frame) : null);
        if (activo) this.reloj.update();
    }

    cuadro(t, frame) {
        this.reloj.update(t);
        const dt = Math.min(this.reloj.getDelta(), 0.1);
        if (frame && this.xr) return this.xr.cuadro(frame, dt);

        let cambia = this.sucio;
        if (this.zoom) cambia = this.pasoZoom() || cambia;
        if (this.controles.update(dt)) cambia = true;
        if (this.capas) cambia = this.capas.paso(dt) || cambia;
        if (this.mezclador && this.animando) {
            this.mezclador.update(dt);
            this.renderer.shadowMap.needsUpdate = true;
            cambia = true;
        }
        if (!cambia) return;
        this.sucio = false;
        this.renderer.render(this.escena, this.camara);
        this.contarCuadro();
        if (this.informarLuego) { this.informarLuego = false; this.informar(); }
    }

    contarCuadro() {
        const f = this.fps;
        f.cuadros++;
        const ahora = performance.now();
        if (ahora - f.desde > 1000) {
            f.valor = Math.round((f.cuadros * 1000) / (ahora - f.desde));
            f.cuadros = 0; f.desde = ahora;
            this.informar();
        }
    }

    pedirDibujo() { this.sucio = true; }

    ajustar() {
        const { clientWidth: w, clientHeight: h } = this.contenedor;
        if (!w || !h || this.renderer.xr.isPresenting) return;
        this.renderer.setSize(w, h, false);
        this.camara.aspect = w / h;
        this.camara.updateProjectionMatrix();
        this.sucio = true;
    }

    /* ---------- luz y sombra ---------- */

    ponerLuz(nombre) {
        const def = LUCES[nombre] || LUCES.estudio;
        if (!this.entornos[nombre]) {
            const escena = def.crear();
            this.entornos[nombre] = this.pmrem.fromScene(escena, 0.04).texture;
        }
        this.luz = nombre;
        this.escena.environment = this.entornos[nombre];
        this.sol.intensity = def.intensidad;
        this.colocarSol();
        this.renderer.shadowMap.needsUpdate = true;
        this.sucio = true;
    }

    colocarSol() {
        const r = this.radio || 0.2, c = this.centro || new THREE.Vector3();
        const dir = new THREE.Vector3(...LUCES[this.luz].dir).normalize();
        this.sol.position.copy(c).addScaledVector(dir, r * 4);
        this.sol.target.position.copy(c);
        const cam = this.sol.shadow.camera;
        Object.assign(cam, { left: -r * 1.6, right: r * 1.6, top: r * 1.6, bottom: -r * 1.6, near: r * 0.5, far: r * 9 });
        cam.updateProjectionMatrix();
    }

    /* ---------- cargar un modelo ---------- */

    async cargar(modelo) {
        const token = ++this.token;
        this.ocultarAviso();
        this.contenedor.classList.remove('cargado');
        this.poster.src = modelo.urls.poster;
        this.poster.alt = modelo.nombre;
        this.progreso(0.02);

        const t0 = performance.now();
        let gltf;
        try {
            gltf = await new Promise((ok, mal) => {
                const loader = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
                loader.load(modelo.urls.glb, ok, (e) => {
                    const total = e.total || modelo.bytes.glb;
                    if (token === this.token && total) this.progreso(Math.min(0.95, e.loaded / total));
                }, mal);
            });
        } catch (e) {
            if (token !== this.token) return;
            console.error(e);
            this.progreso(0);
            this.mostrarAviso(`<p>No se pudo cargar «${modelo.nombre}». Revisa la conexión.</p><button type="button" class="btn" data-recargar>Intentar de nuevo</button>`);
            return;
        }
        if (token !== this.token) { liberar(gltf.scene); return; }

        this.quitarModelo();
        this.modelo = gltf.scene;
        this.datos = modelo;
        this.msCarga = performance.now() - t0;
        this.escena.add(this.modelo);
        this.modelo.traverse((o) => {
            if (!o.isMesh) return;
            o.castShadow = true;
            o.receiveShadow = true;
            o.userData.materialOriginal = o.material;
        });

        // encuadre: la cámara en la misma dirección que la foto de la tarjeta
        const caja = new THREE.Box3().setFromObject(this.modelo);
        this.caja = caja;
        this.centro = caja.getCenter(new THREE.Vector3());
        this.radio = caja.getBoundingSphere(new THREE.Sphere()).radius;
        const r = this.radio;
        this.camara.near = r / 100;
        this.camara.far = r * 100;
        this.camara.updateProjectionMatrix();
        const alejar = modelo.alejar || 1;
        this.distanciaEncuadre = () => {
            // en un visor más alto que ancho manda el ángulo horizontal; en el
            // teléfono, además, un margen para la barra y el botón de RA
            const v = THREE.MathUtils.degToRad(this.camara.fov / 2);
            const h = Math.atan(Math.tan(v) * Math.min(1, this.camara.aspect));
            // `alejar` (config/galeria3d.php → vista.alejar): las piezas compactas,
            // como un cubo, llenan casi toda su esfera y se verían más cerca que las demás
            return (r / Math.sin(Math.min(v, h))) * (this.contenedor.clientWidth < 640 ? 1.18 : 1.02) * alejar;
        };
        const distancia = this.distanciaEncuadre();
        const dir = new THREE.Vector3(0.9, 0.62, 1.25).normalize();
        this.vistaInicial = {
            posicion: this.centro.clone().addScaledVector(dir, distancia),
            objetivo: this.centro.clone(),
        };
        this.controles.minDistance = r * 0.3;
        this.controles.maxDistance = r * 6;
        this.reiniciarVista(false);

        this.suelo.scale.setScalar(r * 8);
        this.suelo.position.set(this.centro.x, caja.min.y, this.centro.z);
        this.colocarSol();

        // animación
        this.mezclador = null;
        if (gltf.animations.length) {
            this.mezclador = new THREE.AnimationMixer(this.modelo);
            gltf.animations.forEach((clip) => this.mezclador.clipAction(clip).play());
            this.animando = !reducirMovimiento;
        }

        this.registrarMateriales();
        this.ponerModo(this.modo);
        this.cajaMedidas(this.medidasVisibles);
        this.renderer.shadowMap.needsUpdate = true;
        this.renderer.compile(this.escena, this.camara);
        this.sucio = true;
        this.progreso(1);
        this.contenedor.classList.add('cargado');
        this.informarLuego = true;
        this.alCargar?.(this);
    }

    quitarModelo() {
        this.capas?.terminar();
        this.comparador?.terminar(true);
        if (!this.modelo) return;
        this.escena.remove(this.modelo);
        liberar(this.modelo);
        this.modelo = null;
        this.mezclador = null;
        this.materiales = new Map();
    }

    progreso(f) {
        this.barra.style.width = `${Math.round(f * 100)}%`;
        this.barra.setAttribute('aria-valuenow', String(Math.round(f * 100)));
    }

    mostrarAviso(html) { this.aviso.innerHTML = html; this.aviso.hidden = false; }
    ocultarAviso() { this.aviso.hidden = true; this.aviso.innerHTML = ''; }

    /* ---------- cámara ---------- */

    reiniciarVista(suave = true) {
        if (!this.vistaInicial) return;
        if (suave && !reducirMovimiento) {
            this.zoom = { desde: this.camara.position.clone(), hasta: this.vistaInicial.posicion.clone(), objDesde: this.controles.target.clone(), objHasta: this.vistaInicial.objetivo.clone(), t: 0 };
        } else {
            this.camara.position.copy(this.vistaInicial.posicion);
            this.controles.target.copy(this.vistaInicial.objetivo);
            this.controles.update();
        }
        this.sucio = true;
    }

    acercar(factor) {
        const c = this.controles;
        const offset = this.camara.position.clone().sub(c.target);
        const d = THREE.MathUtils.clamp(offset.length() * factor, c.minDistance, c.maxDistance);
        const hasta = c.target.clone().addScaledVector(offset.normalize(), d);
        if (reducirMovimiento) { this.camara.position.copy(hasta); this.sucio = true; return; }
        this.zoom = { desde: this.camara.position.clone(), hasta, objDesde: c.target.clone(), objHasta: c.target.clone(), t: 0 };
    }

    pasoZoom() {
        const z = this.zoom;
        z.t = Math.min(1, z.t + 0.08);
        const k = 1 - Math.pow(1 - z.t, 3);
        this.camara.position.lerpVectors(z.desde, z.hasta, k);
        this.controles.target.lerpVectors(z.objDesde, z.objHasta, k);
        if (z.t >= 1) this.zoom = null;
        return true;
    }

    girar(azimut, polar = 0) {
        const c = this.controles;
        const offset = this.camara.position.clone().sub(c.target);
        const s = new THREE.Spherical().setFromVector3(offset);
        s.theta += azimut;
        s.phi = THREE.MathUtils.clamp(s.phi + polar, 0.05, c.maxPolarAngle);
        this.camara.position.copy(c.target).add(new THREE.Vector3().setFromSpherical(s));
        c.update();
        this.sucio = true;
    }

    teclado() {
        this.contenedor.addEventListener('keydown', (e) => {
            if (e.target !== this.contenedor) return;
            const paso = Math.PI / 12;
            const acciones = {
                ArrowLeft: () => this.girar(-paso), ArrowRight: () => this.girar(paso),
                ArrowUp: () => this.girar(0, -paso / 2), ArrowDown: () => this.girar(0, paso / 2),
                '+': () => this.acercar(1 / 1.3), '=': () => this.acercar(1 / 1.3), '-': () => this.acercar(1.3),
                r: () => this.reiniciarVista(), R: () => this.reiniciarVista(),
            };
            if (acciones[e.key]) { e.preventDefault(); acciones[e.key](); $('visor-ayuda').classList.add('oculta'); }
        });
    }

    girarSolo(si) { this.controles.autoRotate = si; this.sucio = true; }

    /* ---------- materiales ---------- */

    registrarMateriales() {
        this.materiales = new Map();
        this.modelo.traverse((o) => {
            if (!o.isMesh) return;
            for (const m of [].concat(o.userData.materialOriginal)) {
                const nombre = m.name || 'Material';
                if (!this.materiales.has(nombre)) this.materiales.set(nombre, { nombre, lista: [], original: null });
                const reg = this.materiales.get(nombre);
                if (!reg.lista.includes(m)) reg.lista.push(m);
            }
        });
        for (const reg of this.materiales.values()) {
            const m = reg.lista[0];
            reg.original = {
                color: m.color?.clone(), metal: m.metalness, rugosidad: m.roughness,
                emision: m.emissiveIntensity, atenuacion: m.attenuationColor?.clone(),
            };
            reg.textura = m.map ? `${m.map.image?.width}×${m.map.image?.height}` : null;
        }
    }

    /** Cambia una propiedad de todos los materiales con ese nombre. */
    cambiarMaterial(nombre, prop, valor) {
        const reg = this.materiales.get(nombre);
        if (!reg) return;
        for (const m of reg.lista) {
            if (prop === 'color' && m.color) m.color.set(valor);
            if (prop === 'metal' && 'metalness' in m) m.metalness = +valor;
            if (prop === 'rugosidad' && 'roughness' in m) m.roughness = +valor;
            if (prop === 'emision' && 'emissiveIntensity' in m) m.emissiveIntensity = +valor;
            if (prop === 'atenuacion' && m.attenuationColor) m.attenuationColor.set(valor);
            m.needsUpdate = prop === 'emision';
        }
        this.modificado = true;
        this.sucio = true;
    }

    restaurarMateriales() {
        for (const reg of this.materiales.values()) {
            const o = reg.original;
            for (const m of reg.lista) {
                if (o.color) m.color.copy(o.color);
                if (o.metal !== undefined) m.metalness = o.metal;
                if (o.rugosidad !== undefined) m.roughness = o.rugosidad;
                if (o.emision !== undefined) m.emissiveIntensity = o.emision;
                if (o.atenuacion) m.attenuationColor.copy(o.atenuacion);
            }
        }
        this.modificado = false;
        this.sucio = true;
    }

    aplicarAcabado(acabado) {
        this.restaurarMateriales();
        for (const [nombre, props] of Object.entries(acabado.materiales || {})) {
            for (const [p, v] of Object.entries(props)) this.cambiarMaterial(nombre, p, v);
        }
        this.modificado = Object.keys(acabado.materiales || {}).length > 0;
    }

    /** Un destello en el material elegido, para encontrarlo en el modelo. */
    resaltar(nombre) {
        const reg = this.materiales.get(nombre);
        if (!reg || reducirMovimiento) return;
        const antes = reg.lista.map((m) => [m, m.emissive?.clone(), m.emissiveIntensity]);
        const acento = new THREE.Color(colorCSS('--accent', '#0D6E63'));
        let t = 0;
        clearInterval(this._destello);
        this._destello = setInterval(() => {
            t += 1;
            const k = Math.sin((t / 12) * Math.PI);
            for (const [m, e, i] of antes) if (m.emissive) { m.emissive.copy(e).lerp(acento, k * 0.8); m.emissiveIntensity = Math.max(i, k); }
            this.sucio = true;
            if (t >= 12) {
                clearInterval(this._destello);
                for (const [m, e, i] of antes) if (m.emissive) { m.emissive.copy(e); m.emissiveIntensity = i; }
            }
        }, 40);
    }

    /* ---------- modos de vista ---------- */

    ponerModo(modo) {
        this.modo = modo;
        if (!this.modelo) return;
        const acento = colorCSS('--accent', '#0D6E63');
        const sustituto = {
            arcilla: () => new THREE.MeshStandardMaterial({ color: '#c9c5ba', roughness: 0.85, metalness: 0 }),
            malla: () => new THREE.MeshBasicMaterial({ color: acento, wireframe: true }),
            normales: () => new THREE.MeshNormalMaterial(),
        }[modo];
        this._sustituto?.dispose();
        this._sustituto = sustituto ? sustituto() : null;
        this.modelo.traverse((o) => {
            if (!o.isMesh) return;
            const orig = o.userData.materialOriginal;
            o.material = this._sustituto ? (Array.isArray(orig) ? orig.map(() => this._sustituto) : this._sustituto) : orig;
        });
        this.suelo.visible = this.sombra !== false && modo !== 'malla';
        this.renderer.shadowMap.needsUpdate = true;
        this.sucio = true;
        this.informar();
    }

    ponerSombra(si) {
        this.sombra = si;
        this.suelo.visible = si && this.modo !== 'malla';
        this.sucio = true;
    }

    cajaMedidas(si) {
        this.medidasVisibles = si;
        if (this._ayudaCaja) { this.escena.remove(this._ayudaCaja); this._ayudaCaja.dispose?.(); this._ayudaCaja = null; }
        if (si && this.caja) {
            this._ayudaCaja = new THREE.Box3Helper(this.caja, new THREE.Color(colorCSS('--accent', '#0D6E63')));
            this.escena.add(this._ayudaCaja);
        }
        this.sucio = true;
    }

    /* ---------- otras acciones ---------- */

    captura(nombre) {
        this.renderer.render(this.escena, this.camara);
        const a = document.createElement('a');
        a.href = this.renderer.domElement.toDataURL('image/png');
        a.download = `${nombre}.png`;
        a.click();
    }

    informar() {
        const el = $('rendimiento');
        if (!el || !this.modelo) return;
        let llamadas = 0;
        this.modelo.traverse((o) => { if (o.isMesh && o.visible) llamadas += Array.isArray(o.material) ? Math.max(1, o.geometry.groups.length) : 1; });
        const quieto = !this.controles.autoRotate && !(this.mezclador && this.animando);
        el.textContent = [
            `${numero(this.datos.triangulos)} triángulos · ${llamadas} llamadas de dibujo`,
            `cargado en ${segundos(this.msCarga)} · ${peso(this.datos.bytes.glb)}`,
            quieto ? 'en reposo: no redibuja si no se mueve' : `${this.fps.valor || '…'} cuadros por segundo`,
        ].join('\n');
        el.style.whiteSpace = 'pre-line';
    }
}

/** Libera geometrías, materiales y texturas: la memoria gráfica no la recoge nadie más. */
function liberar(objeto) {
    objeto.traverse((o) => {
        if (!o.isMesh) return;
        o.geometry?.dispose();
        for (const m of [].concat(o.userData.materialOriginal || o.material)) {
            for (const k in m) if (m[k] && m[k].isTexture) m[k].dispose();
            m.dispose?.();
        }
    });
}

/* ================================================================== */
/* Realidad aumentada                                                   */
/* ================================================================== */

const AR = {
    ios: (() => { const a = document.createElement('a'); return !!(a.relList && a.relList.supports && a.relList.supports('ar')); })(),
    android: /android/i.test(navigator.userAgent),
    webxr: false,
};

// iPhone o iPad (los iPad nuevos se presentan como Mac con pantalla táctil).
// Dentro de otra app (Google, WhatsApp, Instagram) no tienen Vista rápida.
const esIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

// Chrome «de verdad» (no el navegador interno de un lector de QR o de una red social).
// Brave se presenta como Chrome (se reconoce por navigator.brave) y puede traer WebXR bloqueado.
const esBrave = !!navigator.brave;
const esChrome = AR.android && !esBrave && /Chrome\/\d+/.test(navigator.userAgent) && !/; wv\)|Version\/\d|GSA\/|SamsungBrowser|EdgA|OPR\/|MiuiBrowser|FBAN|FBAV|Instagram|Line\//.test(navigator.userAgent);

async function detectarWebXR() {
    try {
        if (navigator.xr) {
            AR.webxr = !!(await navigator.xr.isSessionSupported('immersive-ar'));
            // El navegador implementa WebXR y contestó: «no» es que no hay ARCore.
            AR.xrContesto = true;
        }
    } catch (e) { AR.webxr = false; }
}

const urlAbsoluta = (u) => new URL(u, window.location.href).href;

function intentSceneViewer(modelo) {
    const params = new URLSearchParams({ file: urlAbsoluta(modelo.urls.glb_ar), mode: 'ar_preferred', title: modelo.nombre });
    if (modelo.escalaFija) params.set('resizable', 'false');
    const volver = `${window.location.href.split('#')[0]}#sin-ar`;
    return `intent://arvr.google.com/scene-viewer/1.0?${params}#Intent;scheme=https;package=com.google.android.googlequicksearchbox;action=android.intent.action.VIEW;S.browser_fallback_url=${encodeURIComponent(volver)};end;`;
}

/** Vista rápida con los materiales que se eligieron: se genera el .usdz al vuelo. */
async function abrirQuickLookPersonalizado(visor, modelo) {
    const { USDZExporter } = await import('three/addons/exporters/USDZExporter.js');
    const copia = visor.modelo.clone(true);
    copia.traverse((o) => {
        if (!o.isMesh) return;
        const conv = (m) => (m.transmission ? new THREE.MeshStandardMaterial({ name: m.name, color: m.color, roughness: m.roughness, transparent: true, opacity: 0.55 }) : m);
        o.material = Array.isArray(o.userData.materialOriginal) ? o.userData.materialOriginal.map(conv) : conv(o.userData.materialOriginal);
    });
    const datosUsdz = await new USDZExporter().parseAsync(copia, { quickLookCompatible: true, maxTextureSize: 1024 });
    const url = URL.createObjectURL(new Blob([datosUsdz], { type: 'model/vnd.usdz+zip' }));
    abrirQuickLook(url, modelo.escalaFija);
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/**
 * La Vista rápida se abre con un enlace rel="ar" cuyo único hijo es una
 * imagen: así lo exige Safari. No hace falta que esté en la página.
 */
function abrirQuickLook(url, escalaFija) {
    const a = document.createElement('a');
    a.rel = 'ar';
    a.href = url + (escalaFija ? '#allowsContentScaling=0' : '');
    a.appendChild(document.createElement('img'));
    a.click();
}

/** La sesión WebXR: la cámara del teléfono, el suelo detectado y el modelo encima. */
class SesionAR {
    constructor(visor, modelo) {
        this.visor = visor;
        this.modelo = modelo;
        this.capa = $('xr-capa');
        this.mensaje = $('xr-mensaje');
        this.colocado = false;
        this.escala = 1;
    }

    async iniciar() {
        const v = this.visor;
        v.capas?.terminar();   // en la habitación, la figura entera
        v.comparador?.terminar();
        const sesion = await navigator.xr.requestSession('immersive-ar', {
            requiredFeatures: ['hit-test'],
            optionalFeatures: ['dom-overlay'],
            domOverlay: { root: this.capa },
        });
        this.sesion = sesion;
        // Desde ya: si se cierra mientras se prepara, igual se deja todo como estaba.
        sesion.addEventListener('end', () => this.terminar());
        this.capa.hidden = false;
        // en la habitación la escala es otra: de 1 cm a 30 m
        this.planos = [v.camara.near, v.camara.far];
        v.camara.near = 0.01; v.camara.far = 30; v.camara.updateProjectionMatrix();
        v.renderer.xr.setReferenceSpaceType('local');
        // resolución de la cámara según la gama del teléfono
        v.renderer.xr.setFramebufferScaleFactor(ajustes.escalaXR);
        try {
            await this.preparar(sesion);
        } catch (err) {
            // A mitad de camino: todo como estaba, y que el botón pruebe Scene Viewer.
            this.terminar();
            sesion.end().catch(() => {});
            throw err;
        }
    }

    async preparar(sesion) {
        const v = this.visor;
        await v.renderer.xr.setSession(sesion);
        v.xr = this;
        v.bucle();

        // el modelo y su sombra cuelgan de un ancla que se mueve al tocar
        this.ancla = new THREE.Group();
        this.ancla.visible = false;
        v.escena.add(this.ancla);
        this.posicionModelo = v.modelo.position.clone();
        this.sueloAntes = { pos: v.suelo.position.clone(), escala: v.suelo.scale.clone(), visible: v.suelo.visible };
        v.modelo.position.set(-v.centro.x, -v.caja.min.y, -v.centro.z);
        v.suelo.position.set(0, 0.0005, 0);
        v.suelo.visible = true;
        this.ancla.add(v.modelo, v.suelo);
        this.solAntes = v.sol.parent;
        this.ancla.add(v.sol, v.sol.target);
        v.sol.position.set(...LUCES[v.luz].dir).normalize().multiplyScalar(v.radio * 4);
        v.sol.target.position.set(0, 0, 0);
        this.modoAntes = v.modo;
        v.ponerModo('realista');
        v._ayudaCaja && (v._ayudaCaja.visible = false);

        this.reticula = new THREE.Mesh(
            new THREE.RingGeometry(0.06, 0.075, 40).rotateX(-Math.PI / 2),
            new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.9 }),
        );
        this.reticula.matrixAutoUpdate = false;
        v.escena.add(this.reticula);
        this.vidrioLiviano(v.modelo);

        // Los materiales se preparan ahora, con todo a la vista un instante, y
        // no en el cuadro en que la persona pone la figura (sería un tirón).
        this.ancla.visible = true;
        this.reticula.visible = true;
        try { v.renderer.compile(v.escena, v.camara); } catch (e) { /* se compila al dibujar */ }
        this.ancla.visible = false;
        this.reticula.visible = false;
        this.ultimoImpacto = new THREE.Matrix4();
        this.hayImpacto = false;
        // Si el teléfono no da los cuadros: sin sombra desde ya, y la próxima
        // vez, menos resolución (o Scene Viewer en los más modestos).
        this.medidor = new MedidorDeCuadros(() => this.aliviar());

        const espacioVista = await sesion.requestReferenceSpace('viewer');
        this.fuente = await sesion.requestHitTestSource({ space: espacioVista });

        sesion.addEventListener('select', () => this.tocar());
        $('xr-salir').onclick = () => sesion.end().catch(() => this.terminar());
        $('xr-reiniciar').onclick = () => { this.colocado = false; this.ancla.visible = false; $('xr-reiniciar').hidden = true; };
        // los botones no deben colocar el modelo al tocarlos
        this.capa.querySelector('.acciones').addEventListener('beforexrselect', (e) => e.preventDefault());
        this.gestos();
        this.decir('Mueve el teléfono despacio para encontrar el suelo');
    }

    /** El mensaje de arriba; solo se toca la página si cambia (no en cada cuadro). */
    decir(t) {
        if (this.texto === t) return;
        this.texto = t;
        this.mensaje.textContent = t;
    }

    /**
     * El vidrio de verdad (transmission) obliga a dibujar la escena dos veces
     * por cuadro. En la realidad aumentada se cambia por un vidrio
     * transparente que se ve casi igual y cuesta la mitad; al salir vuelve.
     */
    vidrioLiviano(modelo) {
        this.vidrios = [];
        modelo.traverse((o) => {
            for (const m of [].concat(o.material || [])) {
                if (!(m.transmission > 0) || this.vidrios.some((x) => x.m === m)) continue;
                this.vidrios.push({ m, antes: { transmission: m.transmission, transparent: m.transparent, opacity: m.opacity, depthWrite: m.depthWrite } });
                m.opacity = Math.min(m.opacity, 0.25 + 0.6 * (1 - m.transmission));
                m.transmission = 0;
                m.transparent = true;
                m.depthWrite = false;
                m.needsUpdate = true;
            }
        });
    }

    /** El teléfono va justo: se quita la sombra, lo más costoso que queda. */
    aliviar() {
        const v = this.visor;
        if (this.sinSombra) return;
        this.sinSombra = { castShadow: v.sol.castShadow, suelo: v.suelo.visible };
        v.sol.castShadow = false;
        v.suelo.visible = false;
    }

    tocar() {
        if (this.arrastrando || !this.hayImpacto) return;
        this.ancla.position.setFromMatrixPosition(this.ultimoImpacto);
        if (!this.colocado) {
            // de frente a quien mira
            const cam = this.visor.renderer.xr.getCamera();
            const p = new THREE.Vector3().setFromMatrixPosition(cam.matrixWorld);
            this.ancla.rotation.y = Math.atan2(p.x - this.ancla.position.x, p.z - this.ancla.position.z);
        }
        this.colocado = true;
        this.ancla.visible = true;
        $('xr-reiniciar').hidden = false;
        this.visor.renderer.shadowMap.needsUpdate = true;
        this.decir(this.modelo.escalaFija ? 'A tamaño real · arrastra para girarlo' : 'Arrastra para girarlo · pellizca para cambiar el tamaño');
    }

    gestos() {
        const zona = $('xr-zona');
        const toques = new Map();
        let inicio = null;
        zona.onpointerdown = (e) => {
            toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
            inicio = { rot: this.ancla.rotation.y, escala: this.escala, puntos: [...toques.values()].map((p) => ({ ...p })), movido: false };
        };
        zona.onpointermove = (e) => {
            if (!toques.has(e.pointerId) || !inicio || !this.colocado) return;
            toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
            const p = [...toques.values()];
            if (p.length === 1 && inicio.puntos.length === 1) {
                const dx = p[0].x - inicio.puntos[0].x;
                if (Math.abs(dx) > 8) { inicio.movido = true; this.arrastrando = true; }
                this.ancla.rotation.y = inicio.rot + dx * 0.012;
            } else if (p.length >= 2 && inicio.puntos.length >= 2 && !this.modelo.escalaFija) {
                const d0 = Math.hypot(inicio.puntos[0].x - inicio.puntos[1].x, inicio.puntos[0].y - inicio.puntos[1].y);
                const d1 = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                this.escala = THREE.MathUtils.clamp(inicio.escala * (d1 / Math.max(d0, 1)), 0.25, 4);
                this.ancla.scale.setScalar(this.escala);
                inicio.movido = true; this.arrastrando = true;
            }
        };
        const soltar = (e) => {
            toques.delete(e.pointerId);
            if (toques.size === 0) setTimeout(() => (this.arrastrando = false), 350);
            inicio = toques.size ? { rot: this.ancla.rotation.y, escala: this.escala, puntos: [...toques.values()].map((p) => ({ ...p })) } : null;
        };
        zona.onpointerup = soltar;
        zona.onpointercancel = soltar;
    }

    cuadro(frame, dt) {
        const v = this.visor;
        const espacio = v.renderer.xr.getReferenceSpace();
        if (this.fuente) {
            const impactos = frame.getHitTestResults(this.fuente);
            const pose = impactos.length ? impactos[0].getPose(espacio) : null;
            if (pose) {
                // la misma matriz siempre: crear una por cuadro hace pausas para limpiar memoria
                this.ultimoImpacto.fromArray(pose.transform.matrix);
                this.hayImpacto = true;
                this.reticula.matrix.copy(this.ultimoImpacto);
                this.reticula.visible = !this.colocado;
                if (!this.colocado) this.decir('Toca la pantalla para colocarlo aquí');
            } else {
                this.reticula.visible = false;
            }
        }
        if (v.mezclador && v.animando) { v.mezclador.update(dt); v.renderer.shadowMap.needsUpdate = true; }
        v.renderer.render(v.escena, v.camara);
        this.medidor?.cuadro(dt, this.colocado);
    }

    /** Deja todo como antes. Sirve también si la sesión se cayó a medio preparar. */
    terminar() {
        if (this.terminada) return;
        this.terminada = true;
        const v = this.visor;
        this.fuente?.cancel?.();
        this.capa.hidden = true;
        if (this.reticula) {
            v.escena.remove(this.reticula);
            this.reticula.geometry.dispose();
            this.reticula.material.dispose();
        }
        for (const { m, antes } of this.vidrios || []) {
            Object.assign(m, antes);
            m.needsUpdate = true;
        }
        if (this.sinSombra) v.sol.castShadow = this.sinSombra.castShadow;
        if (this.ancla) {
            v.escena.add(v.modelo, v.suelo, v.sol, v.sol.target);
            v.escena.remove(this.ancla);
            v.modelo.position.copy(this.posicionModelo);
            v.suelo.position.copy(this.sueloAntes.pos);
            v.suelo.scale.copy(this.sueloAntes.escala);
            v.suelo.visible = this.sueloAntes.visible;
            v.colocarSol();
            v._ayudaCaja && (v._ayudaCaja.visible = true);
        }
        v.xr = null;
        [v.camara.near, v.camara.far] = this.planos;
        v.camara.updateProjectionMatrix();
        if (this.modoAntes) v.ponerModo(this.modoAntes);
        v.ajustar();
        v.renderer.shadowMap.needsUpdate = true;
        v.sucio = true;
        v.bucle();
    }
}

/* ================================================================== */
/* «¿Cómo se imprime?»: la figura capa por capa                         */
/* ================================================================== */

/**
 * La figura se arma de abajo hacia arriba, como en la impresora: un plano de
 * corte sube capa a capa (0,2 mm cada una) y todo lo que está por encima no se
 * dibuja. Por dentro, la pieza se ve llena del color de su material (las caras
 * de atrás, dibujadas de un color plano), y una franja marca la capa que se
 * está imprimiendo, con la boquilla yendo y viniendo encima.
 *
 * No toca los materiales de la figura más que para ponerles el plano de corte,
 * y al terminar los deja como estaban.
 */
class CapaPorCapa {
    constructor(visor, alCambiar) {
        this.v = visor;
        this.alCambiar = alCambiar;
        const caja = new THREE.Box3().setFromObject(visor.modelo);
        this.min = caja.min.y;
        this.alto = Math.max(1e-6, caja.max.y - caja.min.y);
        this.altoMm = this.alto * 1000;
        this.capaMm = 0.2;
        this.total = Math.max(1, Math.ceil(this.altoMm / this.capaMm - 1e-6));
        // entre 6 y 14 segundos, sin importar cuántas capas tenga
        this.duracion = THREE.MathUtils.clamp(this.total * 0.05, 6, 14);
        this.t = 0;
        this.jugando = true;
        this.plano = new THREE.Plane(new THREE.Vector3(0, -1, 0), this.min);
        this.planos = [this.plano];
        this.animabaAntes = visor.animando;
        visor.animando = false;   // la figura quieta mientras se imprime
        visor.renderer.localClippingEnabled = true;

        // relleno: las caras de atrás, de un color plano, se ven como el corte de la pieza
        this.extras = [];
        visor.modelo.traverse((o) => {
            if (!o.isMesh || o.isSkinnedMesh || o.userData.esRelleno) return;
            const base = [].concat(o.userData.materialOriginal || o.material)[0];
            const color = (base?.color ? base.color.clone() : new THREE.Color('#cccccc')).multiplyScalar(0.8);
            const relleno = new THREE.Mesh(o.geometry, new THREE.MeshBasicMaterial({ color, side: THREE.BackSide, clippingPlanes: this.planos }));
            relleno.userData.esRelleno = true;
            relleno.raycast = () => {};
            o.add(relleno);
            this.extras.push(relleno);
        });

        // la capa que se imprime: una franja del color de acento sobre toda la base
        const tam = caja.getSize(new THREE.Vector3());
        const acento = new THREE.Color(colorCSS('--accent', '#0D6E63'));
        this.franja = new THREE.Mesh(
            new THREE.PlaneGeometry(1, 1).rotateX(-Math.PI / 2),
            new THREE.MeshBasicMaterial({ color: acento, transparent: true, opacity: 0.16, depthWrite: false, side: THREE.DoubleSide }),
        );
        this.franja.scale.set(tam.x * 1.25, 1, tam.z * 1.25);
        this.centro = caja.getCenter(new THREE.Vector3());
        this.tam = tam;
        // la boquilla: un cono con la punta hacia abajo
        const r = Math.max(tam.x, tam.z) * 0.035 + this.alto * 0.01;
        this.boquilla = new THREE.Mesh(
            new THREE.ConeGeometry(r, r * 2.4, 20).rotateX(Math.PI).translate(0, r * 1.2, 0),
            new THREE.MeshStandardMaterial({ color: '#c9a227', metalness: 0.8, roughness: 0.3 }),
        );
        visor.escena.add(this.franja, this.boquilla);
        this.aplicarCorte();
        this.ponerEn(0);
    }

    /** El plano de corte en todos los materiales de la figura (también si se cambió el acabado o el modo). */
    aplicarCorte() {
        this.v.modelo?.traverse((o) => {
            if (!o.isMesh || o.userData.esRelleno) return;
            // clipShadows: la sombra en el suelo también crece capa a capa
            for (const m of [].concat(o.material)) if (m && m.clippingPlanes !== this.planos) { m.clippingPlanes = this.planos; m.clipShadows = true; }
        });
    }

    /** Lleva la impresión a la capa `k` (0 = nada, total = la figura entera). */
    ponerEn(k) {
        this.capa = THREE.MathUtils.clamp(Math.round(k), 0, this.total);
        const h = this.min + (this.capa / this.total) * this.alto;
        this.plano.constant = h + 1e-7;
        this.franja.position.set(this.centro.x, h, this.centro.z);
        this.franja.visible = this.capa > 0 && this.capa < this.total;
        this.boquilla.visible = this.capa < this.total;
        this.v.renderer.shadowMap.needsUpdate = true;
        this.v.sucio = true;
        this.alCambiar?.(this);
    }

    paso(dt) {
        this.aplicarCorte();
        // la boquilla va y viene sobre la capa, como cuando imprime
        const fase = performance.now() / 260;
        this.boquilla.position.set(
            this.centro.x + Math.sin(fase) * this.tam.x * 0.42,
            this.plano.constant,
            this.centro.z + Math.sin(fase * 0.73 + 1) * this.tam.z * 0.42,
        );
        if (this.jugando) {
            this.t = Math.min(1, this.t + dt / this.duracion);
            const k = Math.floor(this.t * this.total);
            if (k !== this.capa) this.ponerEn(k);
            if (this.t >= 1) { this.jugando = false; this.alCambiar?.(this); }
        }
        return this.boquilla.visible || this.jugando;
    }

    alternar() {
        if (!this.jugando && this.capa >= this.total) { this.t = 0; this.ponerEn(0); }   // al final: otra vez
        this.jugando = !this.jugando;
        this.alCambiar?.(this);
        this.v.sucio = true;
    }

    irA(k) {
        this.jugando = false;
        this.t = k / this.total;
        this.ponerEn(k);
    }

    terminar() {
        const v = this.v;
        if (this.terminado) return;
        this.terminado = true;
        v.modelo?.traverse((o) => {
            if (!o.isMesh) return;
            for (const m of [].concat(o.material)) if (m && m.clippingPlanes === this.planos) { m.clippingPlanes = null; m.clipShadows = false; }
        });
        for (const r of this.extras) { r.parent?.remove(r); r.material.dispose(); }
        v.escena.remove(this.franja, this.boquilla);
        this.franja.geometry.dispose(); this.franja.material.dispose();
        this.boquilla.geometry.dispose(); this.boquilla.material.dispose();
        v.renderer.localClippingEnabled = false;
        v.animando = this.animabaAntes;
        v.capas = null;
        v.renderer.shadowMap.needsUpdate = true;
        v.sucio = true;
        this.alCambiar?.(null);
    }
}

/* ================================================================== */
/* Comparar el tamaño: un objeto conocido al lado de la figura          */
/* ================================================================== */

/**
 * Objetos que todos conocen, a su tamaño real (en metros, como los modelos)
 * y en el mismo estilo sencillo de la galería. `alto`: para elegir uno
 * parecido a la figura y para el texto.
 */
const OBJETOS_COMPARAR = {
    moneda: {
        texto: 'una moneda de 26 mm',
        crear() {
            const g = new THREE.Group();
            const m = new THREE.Mesh(new THREE.CylinderGeometry(0.013, 0.013, 0.002, 24), mate('#c9a64b', 0.6));
            m.position.y = 0.001;
            g.add(m);
            return g;
        },
    },
    tarjeta: {
        // tamaño de las tarjetas bancarias y los carnés (ISO 7810)
        texto: 'una tarjeta de 85,6 × 54 mm',
        crear() {
            const g = new THREE.Group();
            const t = new THREE.Mesh(new THREE.BoxGeometry(0.0856, 0.0008, 0.05398), mate('#2f5d9e'));
            t.position.y = 0.0004;
            const chip = new THREE.Mesh(new THREE.BoxGeometry(0.012, 0.0003, 0.009), mate('#d4b25c', 0.6));
            chip.position.set(-0.025, 0.00095, -0.004);
            g.add(t, chip);
            return g;
        },
    },
    celular: {
        texto: 'un celular de 15 cm',
        crear() {
            const g = new THREE.Group();
            const c = new THREE.Mesh(new THREE.BoxGeometry(0.072, 0.15, 0.008), mate('#22262b'));
            c.position.y = 0.075;
            const p = new THREE.Mesh(new THREE.PlaneGeometry(0.066, 0.142), mate('#3c5266', 0.1));
            p.position.set(0, 0.075, 0.00405);
            g.add(c, p);
            g.rotation.y = -0.5;   // la pantalla hacia quien mira
            return g;
        },
    },
    lata: {
        texto: 'una lata de gaseosa de 12 cm',
        crear() {
            const g = new THREE.Group();
            const l = new THREE.Mesh(new THREE.CylinderGeometry(0.033, 0.033, 0.112, 28), mate('#c0392b'));
            l.position.y = 0.061;
            const tapa = new THREE.Mesh(new THREE.CylinderGeometry(0.029, 0.033, 0.005, 28), mate('#c7ccd1', 0.7));
            tapa.position.y = 0.1195;
            const base = new THREE.Mesh(new THREE.CylinderGeometry(0.033, 0.029, 0.005, 28), mate('#c7ccd1', 0.7));
            base.position.y = 0.0025;
            g.add(l, tapa, base);
            return g;
        },
    },
    persona: {
        texto: 'una persona de 1,70 m',
        crear() {
            // una silueta sencilla: cuerpo, cabeza y piernas
            const g = new THREE.Group();
            const ropa = mate('#5b6b7a'), piel = mate('#c99b77'), jean = mate('#34495e');
            const piernas = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.82, 0.18), jean);
            piernas.position.y = 0.41;
            const torso = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.62, 0.22), ropa);
            torso.position.y = 0.82 + 0.31;
            const cabeza = new THREE.Mesh(new THREE.IcosahedronGeometry(0.11, 1), piel);
            cabeza.position.y = 1.44 + 0.13;
            g.add(piernas, torso, cabeza);
            return g;
        },
    },
};

function mate(color, metal = 0) {
    return new THREE.MeshStandardMaterial({ color, roughness: 0.7, metalness: metal, flatShading: true });
}

/** El objeto que mejor se compara con una figura de este tamaño. */
function objetoParaComparar(tam) {
    const mayor = Math.max(tam.x, tam.y, tam.z);
    if (mayor < 0.05) return 'moneda';
    if (mayor < 0.12) return 'tarjeta';
    if (mayor < 0.3) return 'celular';
    if (mayor < 0.6) return 'lata';
    return 'persona';
}

class Comparador {
    constructor(visor, clave) {
        this.v = visor;
        this.clave = clave;
        const caja = new THREE.Box3().setFromObject(visor.modelo);
        const tam = caja.getSize(new THREE.Vector3());
        this.objeto = OBJETOS_COMPARAR[clave].crear();
        this.objeto.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; o.raycast = () => {}; } });
        // al lado derecho de la figura, en el mismo suelo, con un espacio entre los dos
        const suyo = new THREE.Box3().setFromObject(this.objeto).getSize(new THREE.Vector3());
        const espacio = Math.max(0.005, Math.max(tam.x, tam.z, suyo.x) * 0.15);
        this.objeto.position.set(caja.max.x + espacio + suyo.x / 2, caja.min.y, caja.getCenter(new THREE.Vector3()).z);
        visor.escena.add(this.objeto);

        // la cámara se aleja para que quepan los dos
        this.antes = {
            vista: visor.vistaInicial, radio: visor.radio, centro: visor.centro,
            min: visor.controles.minDistance, max: visor.controles.maxDistance,
            suelo: visor.suelo.scale.clone(), sueloPos: visor.suelo.position.clone(),
        };
        const juntos = caja.clone().union(new THREE.Box3().setFromObject(this.objeto));
        const centro = juntos.getCenter(new THREE.Vector3());
        const r = juntos.getBoundingSphere(new THREE.Sphere()).radius;
        const vf = THREE.MathUtils.degToRad(visor.camara.fov / 2);
        const h = Math.atan(Math.tan(vf) * Math.min(1, visor.camara.aspect));
        const distancia = (r / Math.sin(Math.min(vf, h))) * (visor.contenedor.clientWidth < 640 ? 1.3 : 1.15);
        const dir = new THREE.Vector3(0.35, 0.45, 1.25).normalize();   // más de frente: se ven los dos lado a lado
        // el panel de arriba tapa un poco: los dos un poco más abajo en el cuadro
        const objetivo = centro.clone().add(new THREE.Vector3(0, r * 0.14, 0));
        visor.vistaInicial = { posicion: objetivo.clone().addScaledVector(dir, distancia), objetivo };
        visor.controles.minDistance = r * 0.3;
        visor.controles.maxDistance = Math.max(this.antes.max, r * 6);
        visor.camara.far = Math.max(visor.camara.far, r * 100);
        visor.camara.near = Math.min(visor.camara.near, r / 100);
        visor.camara.updateProjectionMatrix();
        visor.suelo.scale.setScalar(r * 8);
        visor.suelo.position.set(centro.x, caja.min.y, centro.z);
        visor.radio = r; visor.centro = centro;
        visor.colocarSol();
        visor.renderer.shadowMap.needsUpdate = true;
        visor.reiniciarVista();
    }

    terminar(silencioso = false) {
        if (this.terminado) return;
        this.terminado = true;
        const v = this.v;
        v.escena.remove(this.objeto);
        liberar(this.objeto);
        const a = this.antes;
        v.vistaInicial = a.vista; v.radio = a.radio; v.centro = a.centro;
        v.controles.minDistance = a.min; v.controles.maxDistance = a.max;
        v.suelo.scale.copy(a.suelo); v.suelo.position.copy(a.sueloPos);
        v.colocarSol();
        v.renderer.shadowMap.needsUpdate = true;
        v.comparador = null;
        if (!silencioso) v.reiniciarVista();
        v.sucio = true;
    }
}

/* ================================================================== */
/* La página: ficha, colección, pestañas y navegación                   */
/* ================================================================== */

const contenedor = $('visor');
let actual = porSlug[datos.actual];
let visor = null;

function pintarFicha(m) {
    $('ficha-categoria').textContent = [m.categoria, m.tecnica].filter(Boolean).join(' · ');
    $('ficha-nombre').textContent = m.nombre;
    $('ficha-resumen').textContent = m.resumen;
    $('ficha-descripcion').textContent = m.descripcion;
    $('dato-tecnica').textContent = m.tecnica || '—';
    $('dato-maquina').textContent = m.maquina || '—';
    $('dato-material').textContent = m.material || '—';
    $('dato-medidas').textContent = m.medidas;
    $('dato-triangulos').textContent = m.triangulos ? numero(m.triangulos) : '—';
    $('dato-peso').textContent = peso(m.bytes.glb);
    if (m.fuente) $('dato-peso').title = `Antes de optimizar: ${peso(m.fuente.bytes)} y ${numero(m.fuente.triangulos)} triángulos`;
    else $('dato-peso').removeAttribute('title');
    const glb = $('descarga-glb');
    glb.href = m.urls.glb_ar; glb.download = `${m.slug}.glb`;
    $('descarga-estado').textContent = '';
    contenedor.setAttribute('aria-label', `Visor 3D de ${m.nombre}. Flechas para girar, más y menos para acercar, R para volver a la vista inicial.`);
    $('medidas-texto').textContent = `Mostrar caja de medidas (${m.medidas})`;

    // acabados
    const cajaAcabados = $('acabados');
    cajaAcabados.innerHTML = '';
    (m.acabados.length ? m.acabados : [{ nombre: 'Original', materiales: {} }]).forEach((a, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'chip';
        b.setAttribute('aria-pressed', i === 0 ? 'true' : 'false');
        const color = Object.values(a.materiales || {})[0]?.color;
        b.innerHTML = `${color ? `<span class="muestra" style="background:${color}"></span>` : ''}<span></span>`;
        b.lastChild.textContent = a.nombre;
        b.addEventListener('click', () => {
            if (!visor?.modelo) return;
            visor.aplicarAcabado(a);
            cajaAcabados.querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', c === b ? 'true' : 'false'));
            pintarMateriales();
        });
        cajaAcabados.appendChild(b);
    });

    pintarQr(m);
    $('propio').hidden = !m.local;
    pintarSocial(m);
    pintarCalculadora();
}

/**
 * La caja del QR en el computador: el código de la figura, cómo encender el
 * enlace público (abierta como localhost) o, para un archivo propio, que no
 * hay QR posible porque el modelo solo existe en este navegador.
 */
function pintarQr(m) {
    if ($('qr').hidden) return;
    const local = !!m.local;
    const alcanzable = datos.qrAlcanzable !== false;
    $('qr-propio').hidden = !local;
    $('qr-imagen').hidden = local || !alcanzable;
    $('qr-ampliar').hidden = local || !alcanzable;
    $('qr-ampliar').dataset.nombre = m.nombre || '';
    $('qr-texto').hidden = local || !alcanzable;
    $('qr-local').hidden = local || alcanzable;
    if (!local && alcanzable) $('qr-imagen').src = m.qr;
}

/** Un clic en el QR lo muestra en grande; se cierra con «Cerrar», Esc o tocando fuera. */
function abrirQrGrande() {
    const d = $('qr-grande');
    if (!d || !$('qr-imagen').src) return;
    $('qr-grande-imagen').src = $('qr-imagen').src;
    $('qr-grande-nombre').textContent = $('qr-ampliar').dataset.nombre || '';
    if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
    $('qr-grande-cerrar').focus();
}
function cerrarQrGrande() {
    const d = $('qr-grande');
    if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
}
$('qr-ampliar')?.addEventListener('click', abrirQrGrande);
$('qr-grande-cerrar')?.addEventListener('click', cerrarQrGrande);
$('qr-grande')?.addEventListener('click', (e) => {
    // solo fuera del recuadro (el fondo oscuro), no en su margen interior
    const r = e.currentTarget.getBoundingClientRect();
    if (e.target === e.currentTarget && (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom)) cerrarQrGrande();
});
$('qr-grande')?.addEventListener('close', () => $('qr-ampliar')?.focus({ preventScroll: true }));

function hex(c) { return c ? `#${c.getHexString()}` : '#ffffff'; }

function pintarMateriales() {
    const lista = $('materiales');
    lista.innerHTML = '';
    if (!visor?.materiales?.size) return;
    for (const reg of visor.materiales.values()) {
        const m = reg.lista[0];
        const li = document.createElement('li');
        const id = `mat-${Math.random().toString(36).slice(2, 8)}`;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'material-boton';
        b.setAttribute('aria-expanded', 'false');
        b.setAttribute('aria-controls', id);
        const detalle = [m.metalness >= 0.5 ? 'metal' : null, reg.textura ? `textura ${reg.textura}` : null, m.transmission ? 'transparente' : null, m.emissiveIntensity > 0 && m.emissive?.getHex() ? 'emite luz' : null].filter(Boolean).join(' · ');
        b.innerHTML = `<span class="muestra"></span><span class="nombre"></span><small></small>`;
        b.querySelector('.muestra').style.background = hex(m.color);
        b.querySelector('.nombre').textContent = reg.nombre;
        b.querySelector('small').textContent = detalle;

        const ed = document.createElement('div');
        ed.className = 'editor';
        ed.id = id;
        ed.hidden = true;
        const fila = (etq, input, salida) => {
            const l = document.createElement('label');
            l.textContent = etq;
            l.htmlFor = input.id = `${id}-${etq}`;
            ed.append(l, input, salida || document.createElement('span'));
        };
        const color = Object.assign(document.createElement('input'), { type: 'color', value: hex(m.color) });
        color.addEventListener('input', () => { visor.cambiarMaterial(reg.nombre, 'color', color.value); b.querySelector('.muestra').style.background = color.value; });
        fila('Color', color);
        for (const [etq, prop, valor] of [['Metal', 'metal', m.metalness], ['Rugosidad', 'rugosidad', m.roughness]]) {
            if (valor === undefined) continue;
            const r = Object.assign(document.createElement('input'), { type: 'range', min: 0, max: 1, step: 0.01, value: valor });
            const o = document.createElement('output');
            o.textContent = (+valor).toFixed(2).replace('.', ',');
            r.addEventListener('input', () => { visor.cambiarMaterial(reg.nombre, prop, r.value); o.textContent = (+r.value).toFixed(2).replace('.', ','); });
            fila(etq, r, o);
        }
        b.addEventListener('click', () => {
            const abrir = ed.hidden;
            lista.querySelectorAll('.editor').forEach((x) => (x.hidden = true));
            lista.querySelectorAll('.material-boton').forEach((x) => x.setAttribute('aria-expanded', 'false'));
            ed.hidden = !abrir;
            b.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            if (abrir) visor.resaltar(reg.nombre);
        });
        li.append(b, ed);
        lista.appendChild(li);
    }
}

function marcarTarjeta(slug) {
    document.querySelectorAll('.tarjeta').forEach((t) => {
        if (t.dataset.slug === slug) t.setAttribute('aria-current', 'true');
        else t.removeAttribute('aria-current');
    });
}

function seleccionar(slug, { historial = true, desplazar = false } = {}) {
    const m = porSlug[slug];
    if (!m) return;
    actual = m;
    pintarFicha(m);
    marcarTarjeta(slug);
    document.title = document.title.replace(/^[^·]+·( Galería 3D ·)?/, `${m.nombre} · Galería 3D ·`);
    $('titulo').textContent = m.nombre;
    // Un archivo propio no tiene dirección: la barra se queda en la galería.
    if (historial) history.pushState({ slug }, '', m.pagina || datos.base);
    if (desplazar) {
        const caja = $('estudio').getBoundingClientRect();
        if (caja.top < 0 || caja.top > window.innerHeight * 0.4) $('estudio').scrollIntoView({ behavior: reducirMovimiento ? 'auto' : 'smooth', block: 'start' });
    }
    if (visor) {
        $('materiales').innerHTML = '<li><span style="font-size:.85rem;color:var(--muted)">Cargando…</span></li>';
        visor.cargar(m);
    }
}

function vecino(paso) {
    const visibles = [...document.querySelectorAll('.tarjeta:not([hidden])')].map((t) => t.dataset.slug);
    const lista = visibles.length ? visibles : MODELOS.map((m) => m.slug);
    const i = lista.indexOf(actual.slug);
    return lista[(i + paso + lista.length) % lista.length];
}

/* ---------- me gusta, vistas, descargas ---------- */

const numeroEntero = (n) => new Intl.NumberFormat('es-CO').format(n || 0);

function pintarSocial(m) {
    // un archivo propio solo existe en este navegador: no se cuenta ni se comparte
    $('social').hidden = !!m.local;
    if (m.local) return;
    const c = m.cifras || { vistas: 0, me_gusta: 0, descargas: 0 };
    $('me-gusta').setAttribute('aria-pressed', m.meGusta ? 'true' : 'false');
    $('me-gusta').title = m.meGusta ? 'Ya no me gusta' : 'Me gusta';
    $('me-gusta-num').textContent = numeroEntero(c.me_gusta);
    $('vistas-num').textContent = numeroEntero(c.vistas);
    $('descargas-num').textContent = numeroEntero(c.descargas);
    $('compartir-estado').textContent = '';
    $('compartir-whatsapp').href = `https://wa.me/?text=${encodeURIComponent(`${m.nombre}, en 3D y en realidad aumentada: ${enlaceCompartir(m)}`)}`;
}

/** Actualiza las cifras de una figura en su ficha y en su tarjeta. */
function ponerCifras(m, c) {
    m.cifras = { vistas: c.vistas, me_gusta: c.me_gusta, descargas: c.descargas };
    if (typeof c.meGusta === 'boolean') m.meGusta = c.meGusta;
    const t = document.querySelector(`.tarjeta[data-slug="${CSS.escape(m.slug)}"]`);
    if (t) {
        t.dataset.vistas = c.vistas; t.dataset.meGusta = c.me_gusta; t.dataset.descargas = c.descargas;
        const num = t.querySelector('.num-me-gusta');
        if (num) num.textContent = numeroEntero(c.me_gusta);
    }
    if (actual === m) pintarSocial(m);
}

const contadas = new Set();
async function contar(m, accion) {
    if (!m || m.local || !m.contar) return null;
    if (accion === 'vista') {
        if (contadas.has(m.slug)) return null;
        contadas.add(m.slug);
    }
    try {
        const r = await fetch(m.contar, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': datos.token },
            body: JSON.stringify({ accion }),
            keepalive: true,
        });
        if (!r.ok) return null;
        const c = await r.json();
        ponerCifras(m, c);
        return c;
    } catch (e) {
        return null;   // sin conexión: la página sigue igual
    }
}

$('me-gusta').addEventListener('click', async () => {
    const m = actual;
    const poner = !m.meGusta;
    // se ve al instante; si el servidor no contesta, vuelve como estaba
    const antes = { ...(m.cifras || {}), meGusta: m.meGusta };
    ponerCifras(m, { ...m.cifras, me_gusta: Math.max(0, (m.cifras?.me_gusta || 0) + (poner ? 1 : -1)), meGusta: poner });
    if (poner) { const b = $('me-gusta'); b.classList.remove('late'); void b.offsetWidth; b.classList.add('late'); }
    if (!(await contar(m, poner ? 'me-gusta' : 'ya-no'))) ponerCifras(m, antes);
});

/* ---------- compartir ---------- */

/** La dirección que abre en cualquier parte: el dominio o el enlace del túnel. */
function enlaceCompartir(m) {
    return m.compartir || new URL(m.pagina || datos.base, window.location.href).href;
}
function enlaceLocal(url) {
    try { const h = new URL(url).hostname; return h === 'localhost' || h === '127.0.0.1' || h.endsWith('.localhost') || /^(10|192\.168|172\.(1[6-9]|2\d|3[01]))\./.test(h); } catch (e) { return false; }
}
function avisoCompartir(texto) {
    const url = enlaceCompartir(actual);
    $('compartir-estado').textContent = enlaceLocal(url)
        ? `${texto} Ojo: es una dirección de este computador; para que abra en otro aparato, enciende el QR para el celular.`
        : texto;
}
$('compartir-copiar').addEventListener('click', async () => {
    const url = enlaceCompartir(actual);
    try {
        await navigator.clipboard.writeText(url);
    } catch (e) {
        // sin permiso para el portapapeles (http, navegador viejo): a la antigua
        const campo = Object.assign(document.createElement('textarea'), { value: url });
        campo.style.cssText = 'position:fixed;opacity:0';
        document.body.append(campo); campo.select();
        try { document.execCommand('copy'); } catch (x) { /* nada */ }
        campo.remove();
    }
    avisoCompartir('Enlace copiado.');
});
if (navigator.share) {
    $('compartir-nativo').hidden = false;
    $('compartir-nativo').addEventListener('click', () => {
        const m = actual;
        navigator.share({ title: `${m.nombre} · Galería 3D`, text: `${m.nombre}, en 3D y en realidad aumentada`, url: enlaceCompartir(m) }).catch(() => {});
    });
}
$('compartir-whatsapp').addEventListener('click', () => avisoCompartir(''));

/* ---------- la calculadora «Imprimir» ---------- */

/**
 * Volumen y superficie del modelo cargado, en cm³ y cm² (el modelo está en
 * metros). Volumen por la suma de tetraedros con el origen: exacto en una
 * malla cerrada, que es como quedan las figuras al optimizarlas.
 */
function medirModelo(raiz) {
    let vol = 0, area = 0;
    const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3();
    const ab = new THREE.Vector3(), ac = new THREE.Vector3();
    raiz.updateMatrixWorld(true);
    raiz.traverse((o) => {
        if (!o.isMesh || !o.geometry?.attributes?.position) return;
        const pos = o.geometry.attributes.position, idx = o.geometry.index;
        const n = idx ? idx.count : pos.count;
        let v = 0;
        for (let i = 0; i + 2 < n; i += 3) {
            const i0 = idx ? idx.getX(i) : i, i1 = idx ? idx.getX(i + 1) : i + 1, i2 = idx ? idx.getX(i + 2) : i + 2;
            a.fromBufferAttribute(pos, i0).applyMatrix4(o.matrixWorld);
            b.fromBufferAttribute(pos, i1).applyMatrix4(o.matrixWorld);
            c.fromBufferAttribute(pos, i2).applyMatrix4(o.matrixWorld);
            v += a.dot(ab.copy(b).cross(c)) / 6;
            area += ab.subVectors(b, a).cross(ac.subVectors(c, a)).length() / 2;
        }
        vol += Math.abs(v);
    });
    const caja = new THREE.Box3().setFromObject(raiz);
    const t = caja.getSize(new THREE.Vector3());
    return { volumen: vol * 1e6, area: area * 1e4, mm: [t.x * 1000, t.z * 1000, t.y * 1000] };
}

const IMP = datos.impresion || {};
const dinero = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: IMP.moneda || 'COP', maximumFractionDigits: 0 }).format(n);
const mmTexto = (v) => (v >= 100 ? Math.round(v) : Math.round(v * 10) / 10).toLocaleString('es-CO');
function duracion(seg) {
    const min = Math.max(1, Math.round(seg / 60));
    if (min < 60) return `${min} min`;
    const h = Math.floor(min / 60), m = min % 60;
    return m ? `${h} h ${m} min` : `${h} h`;
}
let calcMaterialDe = null;   // el modelo cuyo material ya se puso en la calculadora

function pintarCalculadora() {
    const m = actual;
    const mat = IMP.materiales?.[$('calc-material').value];
    // al cambiar de figura, empieza con el material que ella nombra
    if (calcMaterialDe !== m.slug && m.materialImpresion && IMP.materiales?.[m.materialImpresion]) {
        $('calc-material').value = m.materialImpresion;
        calcMaterialDe = m.slug;
        return pintarCalculadora();
    }
    const escala = +$('calc-escala').value / 100;
    const relleno = +$('calc-relleno').value / 100;
    $('calc-escala-valor').textContent = `${Math.round(escala * 100)} %`;
    $('calc-relleno-valor').textContent = `${Math.round(relleno * 100)} %`;
    const resina = mat?.tipo === 'resina';
    // modo vaso (el jarrón): una sola pared en espiral, sin relleno ni techo
    const vaso = !resina && (m.etiquetas || []).includes('vaso');
    $('calc-fila-relleno').hidden = resina || vaso;
    $('calc-caja-metros').hidden = resina;

    const g = m.geo;
    const dims = g?.mm || (m.mm ? [m.mm[0], m.mm[1], m.mm[2]] : null);
    $('calc-medidas').textContent = dims ? `${dims.map((v) => mmTexto(v * escala)).join(' × ')} mm` : '—';
    if (!g || !mat || !(g.volumen > 0.001)) {
        for (const id of ['calc-gramos', 'calc-tiempo', 'calc-metros', 'calc-costo']) $(id).textContent = '—';
        $('calc-caja-costo').hidden = true;
        $('calc-nota').textContent = g ? 'Este modelo no es un sólido cerrado: no se puede calcular cuánto material lleva.' : 'Calculando con el modelo…';
        return;
    }
    // el volumen crece con el cubo de la escala; la superficie, con el cuadrado
    const vol = g.volumen * escala ** 3;
    const area = g.area * escala ** 2;
    const alto = (dims ? dims[2] : 0) * escala;
    let cm3, segundos;
    if (resina) {
        cm3 = vol;   // sólida
        segundos = Math.ceil(alto / IMP.resina.capa_mm) * IMP.resina.segundos_por_capa;
        $('calc-nota').textContent = `Pieza sólida en resina, capas de ${String(IMP.resina.capa_mm).replace('.', ',')} mm. Es una aproximación: el laminador da la cifra exacta.`;
    } else if (vaso) {
        const f = IMP.fdm;
        // la superficie exterior por el ancho de una línea (0,45 mm); el piso va en ella
        cm3 = Math.min(vol, area * 0.045);
        segundos = (cm3 * 1000) / f.mm3_por_segundo + Math.ceil(alto / f.capa_mm) * 1;
        const radio = f.filamento_mm / 20;
        const metros = cm3 / (Math.PI * radio * radio) / 100;
        $('calc-metros').textContent = `${metros.toLocaleString('es-CO', { maximumFractionDigits: metros < 10 ? 1 : 0 })} m`;
        $('calc-nota').textContent = 'Modo vaso: una sola pared en espiral, sin relleno. Se calcula con la superficie real del modelo y capas de 0,2 mm. Es una aproximación: el laminador da la cifra exacta.';
    } else {
        const f = IMP.fdm;
        // paredes (perímetros, piso y techo) llenas; el interior, al porcentaje de relleno
        const pared = Math.min(vol, area * (f.pared_mm / 10));
        cm3 = pared + (vol - pared) * relleno;
        segundos = (cm3 * 1000) / f.mm3_por_segundo + Math.ceil(alto / f.capa_mm) * f.segundos_por_capa;
        const radio = f.filamento_mm / 20;   // en cm
        const metros = cm3 / (Math.PI * radio * radio) / 100;
        $('calc-metros').textContent = `${metros.toLocaleString('es-CO', { maximumFractionDigits: metros < 10 ? 1 : 0 })} m`;
        $('calc-nota').textContent = 'Se calcula con el volumen real del modelo, boquilla de 0,4 mm, capas de 0,2 mm y 3 perímetros. Es una aproximación: el laminador da la cifra exacta.';
    }
    const gramos = cm3 * mat.densidad;
    $('calc-gramos').textContent = `${gramos.toLocaleString('es-CO', { maximumFractionDigits: gramos < 10 ? 1 : 0 })} g`;
    $('calc-gramos-texto').textContent = resina ? 'de resina' : `de ${mat.nombre}`;
    $('calc-tiempo').textContent = duracion(segundos);
    const precio = parseFloat(mat.precio_gramo);
    $('calc-caja-costo').hidden = !(precio > 0);
    if (precio > 0) $('calc-costo').textContent = dinero(gramos * precio);
}
for (const id of ['calc-escala', 'calc-relleno', 'calc-material']) $(id).addEventListener('input', pintarCalculadora);

/* ---------- colección: filtros y búsqueda ---------- */

// El filtro de la colección: «Todas» o la técnica (Impresión 3D).
let categoria = '';
function cumpleFiltro(t) {
    return !categoria || t.dataset.categoria === categoria;
}
function filtrar() {
    // cada palabra buscada tiene que aparecer (en cualquier orden)
    const palabras = sinTildes($('buscar').value.trim()).split(/\s+/).filter(Boolean);
    const todas = [...document.querySelectorAll('.tarjeta')];
    let visibles = 0;
    todas.forEach((t) => {
        const ok = cumpleFiltro(t) && palabras.every((p) => t.dataset.buscar.includes(p));
        t.hidden = !ok;
        if (ok) visibles++;
    });
    $('sin-resultados').hidden = visibles > 0;
    $('conteo').textContent = (palabras.length || categoria) && visibles
        ? `${visibles} de ${todas.length} ${todas.length === 1 ? 'modelo' : 'modelos'}`
        : '';
}
$('filtros').addEventListener('click', (e) => {
    const b = e.target.closest('[data-categoria]');
    if (!b) return;
    categoria = b.dataset.categoria;
    $('filtros').querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', c === b ? 'true' : 'false'));
    filtrar();
});
$('buscar').addEventListener('input', filtrar);

/** Ordena las tarjetas: destacadas (el orden de siempre), populares, vistas… */
function ordenar() {
    const criterio = $('orden').value;
    const n = (t, campo) => +(t.dataset[campo] || 0);
    const clave = {
        populares: (t) => n(t, 'meGusta') * 5 + n(t, 'descargas') * 3 + n(t, 'vistas'),
        vistas: (t) => n(t, 'vistas'),
        descargas: (t) => n(t, 'descargas'),
    }[criterio];
    const caja = $('tarjetas');
    const lista = [...caja.querySelectorAll('.tarjeta')];
    lista.sort((a, b) => {
        // las que están solo en este navegador, siempre primero
        const pa = a.classList.contains('propia'), pb = b.classList.contains('propia');
        if (pa !== pb) return pa ? -1 : 1;
        if (criterio === 'nombre') return a.querySelector('h3').textContent.localeCompare(b.querySelector('h3').textContent, 'es');
        if (clave) { const d = clave(b) - clave(a); if (d) return d; }
        return n(a, 'orden') - n(b, 'orden');
    });
    lista.forEach((t) => caja.appendChild(t));
}
$('orden').addEventListener('change', ordenar);

$('tarjetas').addEventListener('click', (e) => {
    const t = e.target.closest('.tarjeta');
    if (!t || !visor || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    e.preventDefault();
    seleccionar(t.dataset.slug, { desplazar: true });
});
window.addEventListener('popstate', (e) => {
    const slug = e.state?.slug || window.location.pathname.split('/').filter(Boolean).pop();
    seleccionar(porSlug[slug] ? slug : MODELOS[0].slug, { historial: false });
    if (window.location.href.split(/[?#]/)[0] === datos.base) $('titulo').textContent = $('titulo').dataset.general;
});

/* ---------- pestañas (patrón ARIA, con flechas) ---------- */

const pestanas = [...document.querySelectorAll('.pestana')];
function abrirPestana(p) {
    pestanas.forEach((x) => {
        const si = x === p;
        x.setAttribute('aria-selected', si ? 'true' : 'false');
        x.tabIndex = si ? 0 : -1;
        $(x.getAttribute('aria-controls')).hidden = !si;
    });
}
pestanas.forEach((p, i) => {
    p.addEventListener('click', () => abrirPestana(p));
    p.addEventListener('keydown', (e) => {
        const d = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
        if (!d) return;
        const sig = pestanas[(i + d + pestanas.length) % pestanas.length];
        abrirPestana(sig);
        sig.focus();
    });
});

/* ================================================================== */
/* Arranque                                                             */
/* ================================================================== */

history.replaceState({ slug: actual.slug }, '', window.location.href);
pintarFicha(actual);

/* ================================================================== */
/* Descargas: STEP, STL y FBX se preparan aquí, a partir del .glb        */
/* ================================================================== */

const TIPOS_DESCARGA = {
    step: { extension: 'step', mime: 'model/step', nombre: 'STEP' },
    stl: { extension: 'stl', mime: 'model/stl', nombre: 'STL' },
    '3mf': { extension: '3mf', mime: 'model/3mf', nombre: '3MF' },
    fbx: { extension: 'fbx', mime: 'application/octet-stream', nombre: 'FBX' },
};
// Junto a este archivo y con su misma versión (?v=), para que no quede en caché uno viejo.
const URL_EXPORTAR = new URL(`exportar.js${new URL(import.meta.url).search}`, import.meta.url).href;
let escenaDescarga = null; // { slug, escena }: se reutiliza si piden otro formato del mismo modelo

async function escenaParaDescargar(m) {
    if (escenaDescarga?.slug === m.slug) return escenaDescarga.escena;
    // Se carga aparte (y no la del visor) para que salga con sus materiales
    // originales, sin el modo de vista ni el acabado que se esté mirando.
    const cargador = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
    const { scene, parser } = await cargador.loadAsync(m.urls.glb_ar);
    // Una malla con varios materiales llega como un grupo de mallas; se marca
    // para que el STEP la exporte como un solo cuerpo.
    parser.associations.forEach((a, objeto) => {
        if (objeto.isGroup && a.meshes !== undefined && a.primitives === undefined) objeto.userData.unaPieza = true;
    });
    escenaDescarga = { slug: m.slug, escena: scene };
    return scene;
}

function guardarArchivo(datos, nombre, mime) {
    const url = URL.createObjectURL(new Blob([datos], { type: mime }));
    const a = Object.assign(document.createElement('a'), { href: url, download: nombre });
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

$('descargas').addEventListener('click', async (e) => {
    if (e.target.closest('#descarga-glb')) { $('descarga-estado').textContent = ''; contar(actual, 'descarga'); return; }
    const boton = e.target.closest('button[data-formato]');
    if (!boton || boton.getAttribute('aria-busy') === 'true') return;
    const tipo = TIPOS_DESCARGA[boton.dataset.formato];
    const m = actual;
    const estado = $('descarga-estado');
    const archivo = `${m.slug}.${tipo.extension}`;
    boton.setAttribute('aria-busy', 'true');
    estado.textContent = `Preparando ${archivo}…`;
    try {
        const [escena, exportar] = await Promise.all([escenaParaDescargar(m), import(URL_EXPORTAR)]);
        // Deja que el mensaje se pinte antes del cálculo, que puede tardar un poco.
        await new Promise((r) => setTimeout(r, 30));
        let datosArchivo;
        if (tipo.extension === 'stl') datosArchivo = exportar.exportarSTL(escena, { nombre: m.nombre });
        else if (tipo.extension === '3mf') datosArchivo = await exportar.exportar3MF(escena, { nombre: m.nombre });
        else if (tipo.extension === 'step') datosArchivo = await exportar.exportarSTEP(escena, { nombre: m.nombre, archivo });
        else datosArchivo = await exportar.exportarFBX(escena, { nombre: m.nombre });
        guardarArchivo(datosArchivo, archivo, tipo.mime);
        const bytes = typeof datosArchivo === 'string' ? new Blob([datosArchivo]).size : datosArchivo.byteLength;
        if (actual === m) estado.textContent = `Listo: ${archivo} (${peso(bytes)}).`;
        contar(m, 'descarga');
    } catch (error) {
        console.error(error);
        if (actual === m) estado.textContent = `No se pudo preparar el ${tipo.nombre}. Prueba otra vez o descarga el GLB.`;
    } finally {
        boton.removeAttribute('aria-busy');
    }
});

/* ---------- el túnel del celular, desde la ficha ---------- */

/** Con el enlace público ya encendido, los QR lo usan: se cambian todos y se muestra. */
function mostrarQrConTunel(version, enlace) {
    datos.qrAlcanzable = true;
    for (const m of MODELOS) {
        if (enlace && !m.local) m.compartir = `${enlace.replace(/\/$/, '')}/galeria-3d/${encodeURIComponent(m.slug)}`;
        if (!m.qr) continue;
        const u = new URL(m.qr, window.location.href);
        u.searchParams.set('b', version);
        m.qr = u.href;
    }
    pintarQr(actual);
    pintarSocial(actual);
}

async function encenderTunel() {
    const boton = $('qr-encender'), estado = $('qr-estado');
    const t = datos.tunel;
    if (!t) return;
    boton.disabled = true;
    estado.textContent = 'Encendiendo el enlace público… La primera vez descarga un programa de Cloudflare (unos 30 MB) y puede tardar un minuto.';
    const fallo = (texto) => { estado.textContent = texto; boton.disabled = false; boton.textContent = 'Intentar otra vez'; };
    try {
        const r = await fetch(t.encender, { method: 'POST', headers: { 'X-CSRF-TOKEN': t.token, Accept: 'application/json' } });
        const j = await r.json().catch(() => ({}));
        if (j.enlace) { mostrarQrConTunel(j.version, j.enlace); return; }
        if (!r.ok) { fallo(j.error || `No se pudo encender (error ${r.status}).`); return; }
        estado.textContent = 'Conectando con Cloudflare…';
        let ultimo = null;
        for (let i = 0; i < 45; i++) {
            await new Promise((ok) => setTimeout(ok, 2000));
            const e = await (await fetch(t.estado, { headers: { Accept: 'application/json' } })).json();
            if (e.enlace) { mostrarQrConTunel(e.version, e.enlace); return; }
            if (!e.corriendo) {
                fallo('Cloudflare no dio un enlace. ¿Hay internet en este computador? ' + (e.error ? `(${e.error})` : ''));
                return;
            }
            ultimo = e.error || ultimo;
            // Encendido pero sin un enlace que abra: se dice por qué, sin
            // esperar el minuto y medio completo.
            if (ultimo && i >= 14) { fallo(ultimo); return; }
        }
        fallo(ultimo || 'Cloudflare está tardando más de lo normal. Espera un momento y prueba otra vez.');
    } catch (err) {
        console.error(err);
        fallo('No se pudo encender el enlace público. Revisa que fabOS siga abierto y prueba otra vez.');
    }
}

async function prepararAR() {
    await detectarWebXR();
    const boton = $('boton-ar');
    const puede = AR.ios || AR.android || AR.webxr || esIOS;
    boton.hidden = !puede;
    $('qr').hidden = puede;
    if (!puede) {
        // Si el QR llevaría a localhost (y el túnel del celular está
        // apagado), en vez de un QR que no abre se dice cómo encenderlo.
        $('qr-encender')?.addEventListener('click', encenderTunel);
        pintarQr(actual);
    }

    let abriendo = false;
    boton.addEventListener('click', async (e) => {
        // Un segundo toque mientras arranca abriría dos sesiones: se ignora.
        if (abriendo) return;
        abriendo = true;
        setTimeout(() => { abriendo = false; }, 1500);
        const m = actual;
        // Un archivo propio no tiene .usdz ni dirección pública: en iPhone se
        // genera el .usdz al momento y en Android solo sirve la cámara en la
        // página (Scene Viewer necesitaría descargarlo de un servidor).
        if (m.local) {
            try {
                if (AR.ios && visor?.modelo) { await abrirQuickLookPersonalizado(visor, m); return; }
                if (AR.webxr && visor?.modelo) { await new SesionAR(visor, m).iniciar(); return; }
            } catch (err) {
                console.warn('La realidad aumentada no arrancó con el archivo propio', err);
            }
            visor?.mostrarAviso('<p>Este teléfono no pudo abrir tu archivo en realidad aumentada. Funciona en Android con Chrome y ARCore, y en iPhone o iPad.</p><button type="button" class="btn" data-cerrar>Seguir en 3D</button>');
            return;
        }
        // iPhone sin Vista rápida (la galería está dentro de otra app): la
        // página de la figura explica qué le falta y ofrece el modo cámara.
        if (esIOS && !AR.ios) {
            window.location.href = `${m.ra}#camara`;
            return;
        }
        if (AR.ios) {
            // Con los materiales originales se abre el .usdz ya preparado; si
            // se cambiaron, se genera uno con los elegidos.
            if (visor?.modificado && visor.modelo) {
                try { await abrirQuickLookPersonalizado(visor, m); return; } catch (err) { console.error(err); }
            }
            abrirQuickLook(m.urls.usdz, m.escalaFija);
            return;
        }
        // En gama baja, directo a Scene Viewer: nativo y mucho más liviano
        // que dibujar la cámara en la página (capacidad.js).
        if (AR.webxr && ajustes.webxr && visor?.modelo) {
            try {
                await new SesionAR(visor, m).iniciar();
                return;
            } catch (err) {
                console.warn('WebXR no disponible, se abre Scene Viewer', err);
            }
        }
        // Chrome dice que este teléfono no tiene ARCore (muchos Android de gama
        // baja y media, como el Honor X8a): ni WebXR ni Scene Viewer abren la
        // cámara. La página de la figura la muestra sobre la cámara.
        if (AR.android && (esChrome || (AR.xrContesto && !esBrave)) && !AR.webxr && window.isSecureContext) {
            window.location.href = `${m.ra}#camara`;
            return;
        }
        window.location.href = intentSceneViewer(m);
        // Si al rato la página sigue a la vista, Scene Viewer no se abrió (el
        // navegador interno de otra app, o falta la de Google): la página de
        // realidad aumentada de la figura explica qué hacer.
        let seFue = false;
        const marcar = () => { seFue = true; };
        window.addEventListener('blur', marcar, { once: true });
        document.addEventListener('visibilitychange', marcar, { once: true });
        setTimeout(() => {
            window.removeEventListener('blur', marcar);
            document.removeEventListener('visibilitychange', marcar);
            if (!seFue && document.visibilityState === 'visible') window.location.href = `${m.ra}#sin-ar`;
        }, 2800);
    });

    // Llegó escaneando el QR: se le indica dónde está el botón.
    const params = new URLSearchParams(window.location.search);
    if (params.get('ar') === '1' && puede) {
        boton.animate?.([{ transform: 'scale(1)' }, { transform: 'scale(1.08)' }, { transform: 'scale(1)' }], { duration: 900, iterations: 3 });
        history.replaceState({ slug: actual.slug }, '', actual.pagina);
    }
    if (window.location.hash === '#sin-ar') {
        visor?.mostrarAviso(`<p>Este teléfono no pudo abrir la realidad aumentada de Google (le faltan los Servicios de Google Play para RA). Puedes ver la figura sobre la imagen de la cámara.</p><a class="btn" href="${actual.ra}#camara">Verla con la cámara</a> <button type="button" class="btn" data-cerrar>Seguir en 3D</button>`);
        history.replaceState({ slug: actual.slug }, '', actual.pagina);
    }
}

if (!hayWebGL()) {
    contenedor.classList.add('sin-webgl');
    $('visor-aviso').innerHTML = '<p>Este navegador no puede mostrar modelos 3D (WebGL está desactivado o no está disponible). Puedes ver la foto, descargar el archivo y, en iPhone, abrirlo en realidad aumentada.</p>';
    $('visor-aviso').hidden = false;
    document.querySelectorAll('.herramientas, .flecha').forEach((x) => (x.hidden = true));
} else {
    visor = new Visor(contenedor);
    visor.cargar(actual);

    // barra de herramientas
    const herramientas = contenedor.querySelector('.herramientas');
    const botonAnimar = herramientas.querySelector('[data-accion="animar"]');
    visor.alCargar = () => {
        const m = visor.datos;
        if (m && !m.geo) {
            try { m.geo = medirModelo(visor.modelo); } catch (e) { m.geo = null; }
        }
        if (m === actual) pintarCalculadora();
        contar(m, 'vista');
        pintarMateriales();
        botonAnimar.hidden = !visor.mezclador;
        pintarAnimar();
    };
    function pintarAnimar() {
        botonAnimar.setAttribute('aria-pressed', visor.animando ? 'true' : 'false');
        const etiqueta = visor.animando ? 'Pausar la animación' : 'Reproducir la animación';
        botonAnimar.title = etiqueta; botonAnimar.setAttribute('aria-label', etiqueta);
        botonAnimar.querySelector('.icono-pausa').style.display = visor.animando ? '' : 'none';
        botonAnimar.querySelector('.icono-play').style.display = visor.animando ? 'none' : '';
    }
    herramientas.addEventListener('click', (e) => {
        const b = e.target.closest('[data-accion]');
        if (!b) return;
        const accion = b.dataset.accion;
        if (accion === 'girar') {
            const si = b.getAttribute('aria-pressed') !== 'true';
            b.setAttribute('aria-pressed', si ? 'true' : 'false');
            visor.girarSolo(si);
            visor.informar();
        }
        if (accion === 'animar') { visor.animando = !visor.animando; pintarAnimar(); visor.informar(); }
        if (accion === 'capas') {
            if (visor.capas) visor.capas.terminar();
            else if (visor.modelo) { cerrarComparar(); visor.capas = new CapaPorCapa(visor, pintarCapas); }
        }
        if (accion === 'comparar') {
            if (objetoComparar) cerrarComparar();
            else if (visor.modelo) { visor.capas?.terminar(); compararCon(null); }
        }
        if (accion === 'acercar') visor.acercar(1 / 1.35);
        if (accion === 'alejar') visor.acercar(1.35);
        if (accion === 'reiniciar') visor.reiniciarVista();
        if (accion === 'captura') visor.captura(actual.slug);
        if (accion === 'completa') pantallaCompleta(b);
    });

    // Comparar el tamaño: sigue puesto al pasar de una figura a otra
    let objetoComparar = null;
    const botonComparar = herramientas.querySelector('[data-accion="comparar"]');
    function compararCon(clave) {
        if (!visor.modelo) return;
        visor.comparador?.terminar(true);
        const tam = new THREE.Box3().setFromObject(visor.modelo).getSize(new THREE.Vector3());
        objetoComparar = clave || objetoParaComparar(tam);
        visor.comparador = new Comparador(visor, objetoComparar);
        const mm = (v) => (v >= 1 ? `${(v).toLocaleString('es-CO', { maximumFractionDigits: 2 })} m` : v >= 0.1 ? `${Math.round(v * 100).toLocaleString('es-CO')} cm` : `${Math.round(v * 1000).toLocaleString('es-CO')} mm`);
        $('comparar-texto').textContent = `${actual.nombre} (${mm(tam.y)} de alto) al lado de ${OBJETOS_COMPARAR[objetoComparar].texto}`;
        $('comparar-objetos').querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', c.dataset.objeto === objetoComparar ? 'true' : 'false'));
        $('comparar').hidden = false;
        contenedor.classList.add('con-comparar');
        botonComparar.setAttribute('aria-pressed', 'true');
    }
    function cerrarComparar() {
        objetoComparar = null;
        visor.comparador?.terminar();
        $('comparar').hidden = true;
        contenedor.classList.remove('con-comparar');
        botonComparar.setAttribute('aria-pressed', 'false');
    }
    $('comparar-objetos').addEventListener('click', (e) => {
        const c = e.target.closest('[data-objeto]');
        if (c) compararCon(c.dataset.objeto);
    });
    $('comparar-cerrar').addEventListener('click', cerrarComparar);
    // al cargar otra figura, el mismo objeto a su lado (el que mejor le quede si era automático)
    const alCargarAntes = visor.alCargar;
    visor.alCargar = (v) => {
        alCargarAntes?.(v);
        if (objetoComparar) compararCon(objetoComparar);
    };

    // «¿Cómo se imprime?»: el panel de las capas
    const panelCapas = $('capas');
    const botonCapas = herramientas.querySelector('[data-accion="capas"]');
    function pintarCapas(c) {
        panelCapas.hidden = !c;
        contenedor.classList.toggle('con-capas', !!c);
        botonCapas.setAttribute('aria-pressed', c ? 'true' : 'false');
        if (!c) { pintarAnimar(); return; }
        const barra = $('capas-barra');
        barra.max = c.total;
        barra.value = c.capa;
        const mm = (c.capa * c.capaMm).toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        $('capas-texto').textContent = c.capa >= c.total
            ? `Lista: ${c.total.toLocaleString('es-CO')} capas de 0,2 mm (${Math.round(c.altoMm).toLocaleString('es-CO')} mm de alto)`
            : `Capa ${c.capa.toLocaleString('es-CO')} de ${c.total.toLocaleString('es-CO')} · ${mm} mm`;
        const play = $('capas-play');
        const etiqueta = c.jugando ? 'Pausar' : (c.capa >= c.total ? 'Imprimir otra vez' : 'Seguir imprimiendo');
        play.title = etiqueta; play.setAttribute('aria-label', etiqueta);
        play.querySelector('.icono-pausa').style.display = c.jugando ? '' : 'none';
        play.querySelector('.icono-play').style.display = c.jugando ? 'none' : '';
    }
    $('capas-play').addEventListener('click', () => visor.capas?.alternar());
    $('capas-cerrar').addEventListener('click', () => visor.capas?.terminar());
    $('capas-barra').addEventListener('input', (e) => visor.capas?.irA(+e.target.value));

    function pantallaCompleta(b) {
        const dentro = document.fullscreenElement === contenedor || contenedor.classList.contains('completa');
        if (dentro) {
            if (document.fullscreenElement) document.exitFullscreen();
            contenedor.classList.remove('completa');
            document.body.style.overflow = '';
        } else if (contenedor.requestFullscreen) {
            contenedor.requestFullscreen().catch(() => { contenedor.classList.add('completa'); document.body.style.overflow = 'hidden'; });
        } else {
            contenedor.classList.add('completa');   // iPhone: no hay API de pantalla completa
            document.body.style.overflow = 'hidden';
        }
        b.setAttribute('aria-pressed', dentro ? 'false' : 'true');
    }
    document.addEventListener('fullscreenchange', () => {
        contenedor.querySelector('[data-accion="completa"]').setAttribute('aria-pressed', document.fullscreenElement ? 'true' : 'false');
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && contenedor.classList.contains('completa')) {
            contenedor.classList.remove('completa');
            document.body.style.overflow = '';
            contenedor.querySelector('[data-accion="completa"]').setAttribute('aria-pressed', 'false');
        }
    });

    $('anterior').addEventListener('click', () => seleccionar(vecino(-1)));
    $('siguiente').addEventListener('click', () => seleccionar(vecino(1)));

    // avisos con botones
    $('visor-aviso').addEventListener('click', (e) => {
        if (e.target.closest('[data-recargar]')) visor.cargar(actual);
        if (e.target.closest('[data-cerrar]')) visor.ocultarAviso();
    });

    // pestaña «Materiales»
    $('restaurar').addEventListener('click', () => {
        visor.restaurarMateriales();
        $('acabados').querySelectorAll('.chip').forEach((c, i) => c.setAttribute('aria-pressed', i === 0 ? 'true' : 'false'));
        pintarMateriales();
    });

    // pestaña «Vista»
    $('modos').addEventListener('click', (e) => {
        const b = e.target.closest('[data-modo]');
        if (!b) return;
        $('modos').querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', c === b ? 'true' : 'false'));
        visor.ponerModo(b.dataset.modo);
    });
    $('luces').addEventListener('click', (e) => {
        const b = e.target.closest('[data-luz]');
        if (!b) return;
        $('luces').querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', c === b ? 'true' : 'false'));
        visor.ponerLuz(b.dataset.luz);
    });
    $('exposicion').addEventListener('input', (e) => {
        visor.renderer.toneMappingExposure = +e.target.value;
        $('exposicion-valor').textContent = (+e.target.value).toFixed(2).replace('.', ',');
        visor.pedirDibujo();
    });
    $('sombra').addEventListener('change', (e) => visor.ponerSombra(e.target.checked));
    $('medidas').addEventListener('change', (e) => visor.cajaMedidas(e.target.checked));
}

/* ---------- Sube tu modelo 3D ---------- */

/*
 * El botón de la cabecera (y soltar archivos sobre el visor). El archivo se
 * abre con el mismo código que la pantalla «Subir modelo» del panel
 * (procesar/importar.js): en metros, apoyado en el suelo, con materiales PBR.
 * Se convierte a .glb en memoria y el visor lo carga como a cualquier otra
 * figura: girar, materiales, vista, medidas, descargas y realidad aumentada.
 * Nada viaja al servidor; al recargar la página, desaparece.
 */
const VERSION_JS = new URL(import.meta.url).search;
const moduloProcesar = (archivo) => import(new URL(`procesar/${archivo}${VERSION_JS}`, import.meta.url).href);
const MAX_ARCHIVO = 300 * 1048576;   // más que esto no cabe en la memoria de un teléfono
let subiendo = false;

function estadoSubir(texto, esError = false) {
    const e = $('subir-estado');
    e.textContent = texto;
    e.classList.toggle('error', esError);
}

const cmLocal = (m) => (m * 100).toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const nombreBonito = (archivo) => archivo.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^./, (c) => c.toUpperCase()) || 'Mi modelo';

function contarTriangulos(objeto) {
    let n = 0;
    objeto.traverse((o) => {
        if (!o.isMesh || !o.geometry) return;
        const g = o.geometry;
        n += (g.index ? g.index.count : g.attributes.position.count) / 3;
    });
    return Math.round(n);
}

function agregarTarjeta(m) {
    const a = document.createElement('a');
    a.className = 'tarjeta propia';
    a.href = '#';
    a.dataset.slug = m.slug;
    a.dataset.categoria = m.categoria;
    a.dataset.buscar = sinTildes(`${m.nombre} ${m.tecnica} ${m.categoria}`);
    a.dataset.etiquetas = '';
    a.dataset.orden = -1;
    a.innerHTML = '<span class="foto"><img alt="" width="800" height="800"></span><span class="texto"><span class="cat"></span><h3></h3><span class="meta"><span></span></span></span>';
    const img = a.querySelector('img');
    if (m.urls.poster) img.src = m.urls.poster; else img.remove();
    a.querySelector('.cat').textContent = `${m.categoria} · ${m.tecnica}`;
    a.querySelector('h3').textContent = m.nombre;
    a.querySelector('.meta span').textContent = `${peso(m.bytes.glb)} · solo en tu navegador`;
    $('tarjetas').prepend(a);
    filtrar();
}

async function subirArchivos(lista) {
    const archivos = [...(lista || [])];
    if (!archivos.length || subiendo) return;
    if (!visor) { estadoSubir('Este navegador no puede mostrar modelos 3D (WebGL está desactivado o no está disponible).', true); return; }
    const grande = archivos.find((f) => f.size > MAX_ARCHIVO);
    if (grande) { estadoSubir(`«${grande.name}» pesa ${peso(grande.size)}: es demasiado para abrirlo en el navegador (máximo ${peso(MAX_ARCHIVO)}).`, true); return; }

    subiendo = true;
    const boton = document.querySelector('.subir-boton');
    boton.setAttribute('aria-disabled', 'true');
    const esCAD = archivos.some((f) => /\.(step|stp|iges|igs)$/i.test(f.name));
    estadoSubir(esCAD ? 'Convirtiendo el archivo CAD en malla… La primera vez descarga el lector (unos 8 MB).' : 'Abriendo el archivo…');
    const urls = [];
    try {
        const [{ abrir, colocar, FORMATOS }, { exportarGLB, miniatura }] = await Promise.all([moduloProcesar('importar.js'), moduloProcesar('empaquetar.js')]);
        const abierto = await abrir(archivos);
        const { escena, medidas, avisos } = colocar(abierto.escena, abierto.sugerido);
        estadoSubir('Preparándolo para el visor…');
        await new Promise((r) => setTimeout(r, 30));   // que se pinte el mensaje antes del cálculo

        const glb = new Blob([await exportarGLB(escena, abierto.animaciones)], { type: 'model/gltf-binary' });
        urls.push(URL.createObjectURL(glb));
        const triangulos = contarTriangulos(escena);
        let poster = '';
        try { poster = URL.createObjectURL(await miniatura(escena)); urls.push(poster); } catch (err) { console.warn('Sin foto para la tarjeta', err); }
        liberar(escena);   // el visor lo vuelve a cargar desde el .glb

        // El slug da nombre a las descargas («mi-pieza.stl»); nunca pisa el de otra figura.
        const nombre = nombreBonito(abierto.nombre);
        const raiz = sinTildes(nombre).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'mi-modelo';
        let slug = raiz;
        for (let i = 2; porSlug[slug]; i++) slug = `${raiz}-${i}`;
        const m = {
            slug,
            nombre,
            categoria: 'Tu archivo',
            tecnica: FORMATOS[abierto.formato] || abierto.formato.toUpperCase(),
            maquina: '', material: '',
            resumen: 'Abierto desde tu dispositivo. Gíralo, prueba materiales y descárgalo en otro formato.',
            descripcion: [...abierto.avisos, ...avisos].join(' '),
            urls: { glb: urls[0], glb_ar: urls[0], poster, usdz: null },
            bytes: { glb: glb.size },
            medidas: `${cmLocal(medidas.x)} × ${cmLocal(medidas.z)} × ${cmLocal(medidas.y)} cm`,
            triangulos, texturas: [], fuente: null,
            animado: abierto.animaciones.length > 0, escalaFija: false, acabados: [],
            pagina: null, qr: null, ra: null,
            local: true, archivos, urlsTemporales: urls,
        };
        MODELOS.push(m);
        porSlug[m.slug] = m;
        agregarTarjeta(m);
        seleccionar(m.slug, { desplazar: true });
        const pesado = triangulos > 1_500_000 ? ' Tiene muchos triángulos: en un teléfono puede ir lento.' : '';
        estadoSubir(`Listo: «${m.nombre}» está en el visor (${numero(triangulos)} triángulos, ${m.medidas}).${pesado}`);
    } catch (err) {
        console.warn('No se pudo abrir el archivo subido', err);
        urls.forEach((u) => URL.revokeObjectURL(u));
        estadoSubir(err?.message || 'No se pudo abrir el archivo. ¿Es un modelo 3D?', true);
    } finally {
        subiendo = false;
        boton.removeAttribute('aria-disabled');
        $('subir-entrada').value = '';
    }
}

$('subir-entrada').addEventListener('change', (e) => subirArchivos(e.target.files));

// Soltar archivos sobre el visor.
const llevaArchivos = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
contenedor.addEventListener('dragover', (e) => { if (!llevaArchivos(e)) return; e.preventDefault(); contenedor.classList.add('encima'); });
contenedor.addEventListener('dragleave', (e) => { if (!contenedor.contains(e.relatedTarget)) contenedor.classList.remove('encima'); });
contenedor.addEventListener('drop', (e) => {
    if (!llevaArchivos(e)) return;
    e.preventDefault();
    contenedor.classList.remove('encima');
    subirArchivos(e.dataTransfer.files);
});

$('propio-quitar').addEventListener('click', () => {
    const m = actual;
    if (!m?.local) return;
    document.querySelector(`.tarjeta[data-slug="${m.slug}"]`)?.remove();
    MODELOS.splice(MODELOS.indexOf(m), 1);
    delete porSlug[m.slug];
    seleccionar(MODELOS[0].slug, { desplazar: false });
    setTimeout(() => m.urlsTemporales.forEach((u) => URL.revokeObjectURL(u)), 2000);
    estadoSubir(`Se quitó «${m.nombre}».`);
});

/**
 * Publicar (solo quien administra la galería): los archivos originales pasan
 * a la pantalla «Subir modelo» del panel por IndexedDB —el navegador guarda
 * archivos ahí sin subirlos— y allí se abren solos, sin elegirlos otra vez.
 */
function dejarParaElPanel(archivos) {
    return new Promise((ok, mal) => {
        const pedido = indexedDB.open('fabos-galeria-3d', 1);
        pedido.onupgradeneeded = () => pedido.result.createObjectStore('pendiente');
        pedido.onerror = () => mal(pedido.error);
        pedido.onsuccess = () => {
            const db = pedido.result;
            const tx = db.transaction('pendiente', 'readwrite');
            tx.objectStore('pendiente').put({ archivos, cuando: Date.now() }, 'archivos');
            tx.oncomplete = () => { db.close(); ok(); };
            tx.onerror = () => { db.close(); mal(tx.error); };
        };
    });
}

$('propio-publicar')?.addEventListener('click', async (e) => {
    const m = actual;
    if (!m?.local || !datos.subir?.publicar) return;
    e.currentTarget.disabled = true;
    const destino = new URL(datos.subir.publicar, window.location.href);
    try {
        await dejarParaElPanel(m.archivos);
        destino.searchParams.set('desde', 'galeria');
    } catch (err) {
        // Sin IndexedDB (navegación privada en algunos navegadores): se abre
        // el panel igual y allí se elige el archivo.
        console.warn('No se pudo pasar el archivo al panel', err);
    }
    window.location.href = destino.href;
});

prepararAR();
