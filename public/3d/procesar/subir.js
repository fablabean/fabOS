/*
 * Panel → Galería 3D → Subir modelo.
 *
 * 1. Abre lo que se suelta (importar.js) y lo enseña en una vista previa.
 * 2. Deja corregir unidades, posición y —si no trae— color, viendo el
 *    resultado al momento.
 * 3. Al guardar, lo optimiza y lo empaqueta (empaquetar.js, el mismo código
 *    que hizo los modelos de ejemplo) y sube solo los cuatro archivos finales.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { abrir, colocar, pintar, opcionesDeCalidad, CALIDADES } from './importar.js';
import { empaquetar } from './empaquetar.js';

const $ = (id) => document.getElementById(id);
const datos = JSON.parse($('g3dp-datos').textContent);
const reemplazo = !!datos.modelo;
const numero = (n) => Math.round(n).toLocaleString('es-CO');
const peso = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(b / 1024)).toLocaleString('es-CO')} KB`);
const cm = (m) => (m * 100).toFixed(m < 0.1 ? 2 : 1).replace('.', ',');

let abierto = null;         // lo que devolvió abrir()
let colocado = null;        // { escena, medidas }
const ajustes = { unidades: 'mm', zArriba: false, giroX: 0, giroY: 0, color: '#0d6e63', acabado: 'pla-mate', calidad: 'equilibrada' };

/* ------------------------------------------------------------------ */
/* Vista previa                                                         */
/* ------------------------------------------------------------------ */

const lienzo = $('g3dp-lienzo');
const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 2));
renderer.toneMapping = THREE.NeutralToneMapping;
lienzo.prepend(renderer.domElement);
const escena = new THREE.Scene();
escena.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
const camara = new THREE.PerspectiveCamera(35, 1, 0.001, 1000);
const controles = new OrbitControls(camara, renderer.domElement);
controles.enableDamping = true;
let rejilla = null, mezclador = null;
const reloj = new THREE.Timer();

function ajustarTamano() {
    const w = lienzo.clientWidth, h = lienzo.clientHeight;
    renderer.setSize(w, h, false);
    camara.aspect = w / h;
    camara.updateProjectionMatrix();
}
new ResizeObserver(ajustarTamano).observe(lienzo);
ajustarTamano();
renderer.setAnimationLoop((t) => {
    reloj.update(t);
    controles.update();
    mezclador?.update(reloj.getDelta());
    renderer.render(escena, camara);
});

/** Encuadra y pone una rejilla de 1 cm o 10 cm según el tamaño: la escala se ve. */
function mostrar(objeto, encuadrar = true) {
    escena.children.filter((o) => o.userData.previa).forEach((o) => escena.remove(o));
    objeto.userData.previa = true;
    escena.add(objeto);
    const caja = new THREE.Box3().setFromObject(objeto);
    const tam = caja.getSize(new THREE.Vector3());
    const r = Math.max(tam.length() / 2, 0.001);
    const mayor = Math.max(tam.x, tam.z, 0.01);
    const celda = mayor > 1.5 ? 0.5 : mayor > 0.15 ? 0.1 : 0.01;
    const lado = Math.ceil((mayor * 1.6) / celda) * celda;
    if (rejilla) escena.remove(rejilla);
    rejilla = new THREE.GridHelper(lado, Math.round(lado / celda), 0x888888, 0xbbbbbb);
    rejilla.material.transparent = true;
    rejilla.material.opacity = 0.45;
    rejilla.userData.previa = false;
    escena.add(rejilla);
    $('g3dp-rejilla').hidden = false;
    $('g3dp-rejilla').textContent = `Cada cuadro mide ${celda >= 0.1 ? celda * 100 + ' cm' : '1 cm'}`;
    if (encuadrar) {
        const c = caja.getCenter(new THREE.Vector3());
        controles.target.copy(c);
        camara.near = r / 100; camara.far = r * 200; camara.updateProjectionMatrix();
        camara.position.copy(c).add(new THREE.Vector3(0.9, 0.62, 1.25).normalize().multiplyScalar(r / Math.sin(THREE.MathUtils.degToRad(17.5)) * 1.05));
    }
    $('g3dp-vacio').hidden = true;
}

/* ------------------------------------------------------------------ */
/* Abrir                                                                */
/* ------------------------------------------------------------------ */

const error = (texto) => { const e = $('g3dp-error'); e.textContent = texto || ''; e.hidden = !texto; };
const avisos = (lista) => {
    $('g3dp-avisos').innerHTML = '';
    for (const a of lista) {
        const d = document.createElement('div');
        d.className = 'g3dp-aviso';
        d.textContent = a;
        $('g3dp-avisos').appendChild(d);
    }
};

async function elegir(archivos) {
    archivos = [...archivos];
    if (!archivos.length) return;
    error('');
    avisos([]);
    $('g3dp-resultado').hidden = true;
    const lista = $('g3dp-archivos');
    lista.innerHTML = '';
    archivos.forEach((f) => {
        const li = document.createElement('li');
        li.textContent = `${f.name} · ${peso(f.size)}`;
        lista.appendChild(li);
    });
    lista.hidden = false;

    const esCAD = archivos.some((f) => /\.(step|stp|iges|igs)$/i.test(f.name));
    $('g3dp-vacio').hidden = false;
    $('g3dp-vacio').textContent = esCAD ? 'Convirtiendo el archivo CAD en malla… (la primera vez descarga el lector, unos 8 MB)' : 'Leyendo el archivo…';
    try {
        abierto = await abrir(archivos);
    } catch (e) {
        console.error(e);
        abierto = null;
        error(e.message || String(e));
        $('g3dp-vacio').textContent = 'No se pudo abrir.';
        ['g3dp-ajustes', 'g3dp-ficha', 'g3dp-guardar-seccion'].forEach((id) => $(id) && ($(id).hidden = true));
        return;
    }
    lista.querySelectorAll('li').forEach((li, i) => { if (archivos[i].name === abierto.nombre) li.classList.add('principal'); });

    Object.assign(ajustes, abierto.sugerido, { giroX: 0, giroY: 0 });
    $('g3dp-unidades').value = ajustes.unidades;
    $('g3dp-unidades-ayuda').textContent = ['step', 'stp', 'iges', 'igs'].includes(abierto.formato)
        ? 'El STEP y el IGES guardan sus unidades: normalmente no hay que tocar esto.'
        : 'El archivo no dice sus unidades: se eligieron las que dan un tamaño razonable. Si no es el real, cámbialas.';
    $('g3dp-pintura').hidden = !abierto.sinMateriales;
    if (!reemplazo && !$('g3dp-nombre').value) {
        $('g3dp-nombre').value = abierto.nombre.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^./, (c) => c.toUpperCase());
    }
    mezclador = null;
    actualizar(true, abierto.avisos);
    ['g3dp-ajustes', 'g3dp-ficha', 'g3dp-guardar-seccion'].forEach((id) => $(id) && ($(id).hidden = false));
    if (abierto.animaciones.length) {
        mezclador = new THREE.AnimationMixer(abierto.escena);
        abierto.animaciones.forEach((c) => mezclador.clipAction(c).play());
    }
}

function actualizar(encuadrar = false, avisosExtra = []) {
    if (!abierto) return;
    if (abierto.sinMateriales) pintar(abierto.escena, ajustes.color, ajustes.acabado);
    colocado = colocar(abierto.escena, ajustes);
    const t = colocado.medidas;
    $('g3dp-medidas').textContent = `${cm(t.x)} × ${cm(t.z)} × ${cm(t.y)} cm`;
    avisos([...avisosExtra, ...colocado.avisos]);
    const op = opcionesDeCalidad(colocado.escena, ajustes.calidad);
    $('g3dp-calidad-ayuda').textContent = op.simplificacion.umbral === Infinity
        ? `El modelo tiene ${numero(op.triangulos)} triángulos: con esta calidad se queda como está y solo se comprime.`
        : `El modelo tiene ${numero(op.triangulos)} triángulos: se simplificará a unos ${numero(CALIDADES[ajustes.calidad].triangulos)} sin cambiar su forma visible.`;
    mostrar(colocado.escena, encuadrar);
}

const suelta = $('g3dp-suelta');
$('g3dp-entrada').addEventListener('change', (e) => elegir(e.target.files));
suelta.addEventListener('dragover', (e) => { e.preventDefault(); suelta.classList.add('encima'); });
suelta.addEventListener('dragleave', () => suelta.classList.remove('encima'));
suelta.addEventListener('drop', (e) => { e.preventDefault(); suelta.classList.remove('encima'); elegir(e.dataTransfer.files); });

$('g3dp-unidades').addEventListener('change', (e) => { ajustes.unidades = e.target.value; actualizar(true); });
$('g3dp-levantar').addEventListener('click', () => { ajustes.giroX -= Math.PI / 2; actualizar(true); });
$('g3dp-girar').addEventListener('click', () => { ajustes.giroY += Math.PI / 2; actualizar(false); });
$('g3dp-posicion-original').addEventListener('click', () => { ajustes.giroX = 0; ajustes.giroY = 0; ajustes.zArriba = abierto?.sugerido.zArriba ?? false; actualizar(true); });
$('g3dp-color').addEventListener('input', (e) => { ajustes.color = e.target.value; actualizar(false); });
$('g3dp-acabado').addEventListener('change', (e) => { ajustes.acabado = e.target.value; actualizar(false); });
document.querySelectorAll('input[name="g3dp-calidad"]').forEach((r) => r.addEventListener('change', () => { ajustes.calidad = r.value; actualizar(false); }));

/* ------------------------------------------------------------------ */
/* Guardar                                                              */
/* ------------------------------------------------------------------ */

function trabajando(texto, fraccion) {
    $('g3dp-trabajando').hidden = false;
    $('g3dp-estado').textContent = texto;
    if (fraccion !== undefined) $('g3dp-barra').style.width = `${Math.round(fraccion * 100)}%`;
}

function enviar(form) {
    return new Promise((ok, mal) => {
        const x = new XMLHttpRequest();
        x.open('POST', datos.guardar);
        x.setRequestHeader('Accept', 'application/json');
        x.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.content || '');
        x.upload.onprogress = (e) => e.lengthComputable && trabajando(`Subiendo… ${Math.round((e.loaded / e.total) * 100)} %`, 0.75 + 0.25 * (e.loaded / e.total));
        x.onload = () => {
            let r = null;
            try { r = JSON.parse(x.responseText); } catch (e) { /* respuesta no JSON */ }
            if (x.status >= 200 && x.status < 300 && r?.ok) return ok(r);
            if (x.status === 422 && r?.errors) return mal(new Error(Object.values(r.errors).flat().join(' ')));
            if (x.status === 413) return mal(new Error('El servidor rechazó el envío por tamaño. Prueba con la calidad «Ligera».'));
            if (x.status === 419) return mal(new Error('La sesión caducó. Recarga la página y vuelve a intentarlo.'));
            if (x.status === 403) return mal(new Error('No tienes permiso para hacer esto en la Galería 3D.'));
            mal(new Error(r?.message || `El servidor respondió con un error (${x.status}).`));
        };
        x.onerror = () => mal(new Error('Se cortó la conexión mientras se subía.'));
        x.send(form);
    });
}

$('g3dp-guardar').addEventListener('click', async () => {
    if (!colocado) return;
    error('');
    if (!reemplazo && !$('g3dp-nombre').value.trim()) {
        error('Ponle un nombre al modelo (en «La ficha»).');
        $('g3dp-nombre').focus();
        return;
    }
    const boton = $('g3dp-guardar');
    boton.disabled = true;
    $('g3dp-resultado').hidden = true;
    try {
        const op = opcionesDeCalidad(colocado.escena, ajustes.calidad);
        const pasos = ['Midiendo', 'Optimizando', 'Exportando para la web', 'Exportando para iPhone', 'Comprobando', 'Sacando la foto'];
        const r = await empaquetar(colocado.escena, abierto.animaciones, {
            ...op,
            medirFuente: op.triangulos < 400000,
            alAvanzar: (texto) => trabajando(texto, Math.min(0.7, (pasos.findIndex((p) => texto.startsWith(p)) + 1) / pasos.length * 0.7)),
        });
        // la vista previa se quedó sin el modelo (la miniatura lo usa): se devuelve
        mostrar(colocado.escena, false);

        const { glb, glbAR, usdz, poster } = r.archivos;
        const total = glb.byteLength + glbAR.byteLength + usdz.byteLength + poster.size;
        if (total > datos.maximoMB * 1048576) {
            throw new Error(`Optimizado sigue pesando ${peso(total)} en total y el máximo es ${datos.maximoMB} MB. Prueba con la calidad «Ligera» o reduce las texturas.`);
        }
        const nombres = new Set();
        colocado.escena.traverse((o) => { if (o.isMesh) [].concat(o.material).forEach((m) => m?.name && nombres.add(m.name)); });
        r.estadisticas.nombresMateriales = [...nombres];

        const form = new FormData();
        if (reemplazo) form.append('modelo_id', datos.modelo.id);
        form.append('glb', new Blob([glb], { type: 'model/gltf-binary' }), 'modelo.glb');
        form.append('glb_ar', new Blob([glbAR], { type: 'model/gltf-binary' }), 'modelo-ar.glb');
        form.append('usdz', new Blob([usdz], { type: 'model/vnd.usdz+zip' }), 'modelo.usdz');
        form.append('poster', poster, 'modelo.webp');
        form.append('estadisticas', JSON.stringify(r.estadisticas));
        form.append('archivo_original', abierto.nombre);
        form.append('formato_original', abierto.formato);
        if (!reemplazo) {
            for (const campo of ['nombre', 'categoria', 'tecnica', 'maquina', 'material', 'resumen', 'descripcion']) {
                form.append(campo, $(`g3dp-${campo}`).value.trim());
            }
            form.append('escala_fija', $('g3dp-escala').checked ? '1' : '0');
            form.append('publicado', $('g3dp-publicar').checked ? '1' : '0');
        }

        trabajando('Subiendo…', 0.75);
        const respuesta = await enviar(form);
        trabajando('Listo.', 1);

        const e = r.estadisticas, f = e.fuente, o = e.optimizado;
        const res = $('g3dp-resultado');
        res.innerHTML = `
            <dl class="g3dp-cifras">
                <div><dt>Triángulos</dt><dd>${numero(o.triangulos)}<small>${f.triangulos > o.triangulos ? 'de ' + numero(f.triangulos) : 'sin cambios'}</small></dd></div>
                <div><dt>Peso en la web</dt><dd>${peso(o.bytes)}<small>${f.bytes ? 'de ' + peso(f.bytes) : ''}</small></dd></div>
                <div><dt>Llamadas de dibujo</dt><dd>${o.llamadas}<small>${f.llamadas > o.llamadas ? 'de ' + f.llamadas : ''}</small></dd></div>
            </dl>
            <div class="g3dp-listo">
                <img alt="" src="${URL.createObjectURL(poster)}">
                <div>
                    <p style="margin:0 0 .6rem;font-weight:600">${reemplazo ? 'Archivo reemplazado.' : (respuesta.publicado ? 'Guardado y publicado en la galería.' : 'Guardado. Todavía no está publicado.')}</p>
                    <div class="g3dp-fila">
                        <a class="fi-btn fi-size-md fi-color-primary fi-btn-color-primary" style="text-decoration:none" href="${respuesta.editar}">${reemplazo ? 'Volver a la ficha' : 'Editar ficha y acabados'}</a>
                        ${respuesta.publicado ? `<a class="fi-link" href="${respuesta.pagina}" target="_blank" rel="noopener">Ver en la galería ↗</a>` : ''}
                        ${reemplazo ? '' : `<a class="fi-link" href="${location.pathname}">Subir otro</a>`}
                    </div>
                </div>
            </div>`;
        res.hidden = false;
        boton.hidden = true;
    } catch (e) {
        console.error(e);
        error(e.message || String(e));
        $('g3dp-trabajando').hidden = true;
        mostrar(colocado.escena, false);
    } finally {
        boton.disabled = false;
    }
});

/* ------------------------------------------------------------------ */
/* Desde la galería                                                     */
/* ------------------------------------------------------------------ */

/**
 * «Publicar en la galería» (un modelo abierto con «Sube tu modelo 3D») deja
 * los archivos en IndexedDB y llega aquí con ?desde=galeria: se abren solos,
 * como si se hubieran soltado. Se borran al leerlos; si tienen más de diez
 * minutos, se descartan (alguien volvió a esta página mucho después).
 */
function tomarDeLaGaleria() {
    return new Promise((ok) => {
        let pedido;
        try { pedido = indexedDB.open('fabos-galeria-3d', 1); } catch (e) { ok(null); return; }
        pedido.onupgradeneeded = () => pedido.result.createObjectStore('pendiente');
        pedido.onerror = () => ok(null);
        pedido.onsuccess = () => {
            const db = pedido.result;
            const tx = db.transaction('pendiente', 'readwrite');
            const almacen = tx.objectStore('pendiente');
            const leer = almacen.get('archivos');
            leer.onsuccess = () => { almacen.delete('archivos'); };
            tx.oncomplete = () => {
                db.close();
                const d = leer.result;
                ok(d && Date.now() - d.cuando < 10 * 60_000 ? d.archivos : null);
            };
            tx.onerror = () => { db.close(); ok(null); };
        };
    });
}

if (!reemplazo && new URLSearchParams(location.search).get('desde') === 'galeria') {
    history.replaceState(null, '', location.pathname);
    tomarDeLaGaleria().then((archivos) => {
        if (archivos?.length) elegir(archivos);
    });
}

window.galeriaSubirLista = true;
