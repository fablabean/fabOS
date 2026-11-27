/*
 * Empaquetar un modelo para la Galería 3D.
 *
 * Toma una escena de three (venga de donde venga: un modelo hecho con código
 * en el taller o un archivo subido desde el panel) y saca los cuatro archivos
 * que necesita la galería, con las cifras de la ficha técnica:
 *
 *   glb     el visor web: optimizado y con la geometría comprimida (meshopt)
 *   glbAR   Scene Viewer de Android y la descarga: sin extensiones obligatorias
 *   usdz    la Vista rápida de iOS
 *   poster  la foto de la tarjeta, WebP con fondo transparente
 *
 * Al final vuelve a cargar los dos .glb como lo hará el visor y comprueba que
 * tengan los mismos triángulos: un archivo que no se puede abrir no sale de aquí.
 *
 * Es el mismo código para herramientas/galeria-3d/taller.js y para el panel.
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { GLTFExporter } from 'three/addons/exporters/GLTFExporter.js';
import { USDZExporter } from 'three/addons/exporters/USDZExporter.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import { optimizar, estadisticas } from './optimizar.js';
import { comprimirMeshopt } from './meshopt-gltf.js';

export { estadisticas };

export async function exportarGLB(escena, animaciones = [], { png = false } = {}) {
    const restaurar = [];
    if (png) {
        escena.traverse((o) => {
            if (!o.isMesh) return;
            for (const m of [].concat(o.material)) for (const k in m) {
                const t = m[k];
                if (t && t.isTexture) { restaurar.push([t, t.userData.mimeType]); t.userData.mimeType = 'image/png'; }
            }
        });
    }
    const glb = await new GLTFExporter().parseAsync(escena, { binary: true, animations: animaciones, onlyVisible: true, maxTextureSize: 4096 });
    restaurar.forEach(([t, m]) => (t.userData.mimeType = m));
    return glb;
}

/** Para la Vista rápida de iOS: lo transparente con refracción se ve como cristal tintado. */
function escenaParaUSDZ(escena) {
    const copia = escena.clone(true);
    copia.traverse((o) => {
        if (!o.isMesh) return;
        const conv = (m) => (m.transmission
            ? new THREE.MeshStandardMaterial({ name: m.name, color: m.color, roughness: m.roughness, metalness: 0, transparent: true, opacity: 0.55 })
            : m);
        o.material = Array.isArray(o.material) ? o.material.map(conv) : conv(o.material);
    });
    return copia;
}

export function cargarGLB(buffer) {
    const loader = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
    return new Promise((ok, mal) => loader.parse(buffer.slice(0), '', ok, mal));
}

/* ------------------------------------------------------------------ */
/* La foto de la tarjeta                                                */
/* ------------------------------------------------------------------ */

let renderer, entorno;
function preparar() {
    if (renderer) return;
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(1);
    renderer.setSize(800, 800, false);
    renderer.toneMapping = THREE.NeutralToneMapping;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFShadowMap;
    entorno = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
}

/** La misma dirección de cámara que el visor, para que al abrirlo no salte. */
export async function miniatura(modelo) {
    preparar();
    const escena = new THREE.Scene();
    escena.environment = entorno;
    escena.add(modelo);
    modelo.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });

    const caja = new THREE.Box3().setFromObject(modelo);
    const tam = caja.getSize(new THREE.Vector3()), centro = caja.getCenter(new THREE.Vector3());
    const radio = tam.length() / 2;

    const sol = new THREE.DirectionalLight(0xffffff, 1.2);
    sol.position.set(centro.x + radio * 1.5, caja.max.y + radio * 3, centro.z + radio * 1.2);
    sol.target.position.copy(centro);
    sol.castShadow = true;
    sol.shadow.mapSize.set(2048, 2048);
    const d = radio * 2;
    Object.assign(sol.shadow.camera, { left: -d, right: d, top: d, bottom: -d, near: radio * 0.01, far: radio * 10 });
    sol.shadow.radius = 6;
    sol.shadow.bias = -0.0005;
    escena.add(sol, sol.target);

    const suelo = new THREE.Mesh(new THREE.PlaneGeometry(radio * 8, radio * 8), new THREE.ShadowMaterial({ opacity: 0.22 }));
    suelo.rotation.x = -Math.PI / 2;
    suelo.position.y = caja.min.y;
    suelo.receiveShadow = true;
    escena.add(suelo);

    const camara = new THREE.PerspectiveCamera(30, 1, radio / 100, radio * 100);
    const dir = new THREE.Vector3(0.9, 0.62, 1.25).normalize();
    camara.position.copy(centro).addScaledVector(dir, (radio / Math.sin(THREE.MathUtils.degToRad(15))) * 0.98);
    camara.lookAt(centro.x, centro.y - tam.y * 0.04, centro.z);

    renderer.setClearColor(0x000000, 0);
    renderer.render(escena, camara);
    const blob = await new Promise((ok) => renderer.domElement.toBlob(ok, 'image/webp', 0.86));
    escena.remove(modelo);
    suelo.geometry.dispose();
    return blob;
}

/* ------------------------------------------------------------------ */
/* Todo junto                                                           */
/* ------------------------------------------------------------------ */

/**
 * @param {THREE.Object3D} escena   en metros, Y arriba, apoyada en y = 0
 * @param {THREE.AnimationClip[]} animaciones
 * @param {{texturaMax?: number, simplificacion?: object, medirFuente?: boolean, alAvanzar?: (texto: string) => void}} opciones
 */
export async function empaquetar(escena, animaciones = [], { texturaMax = 1024, simplificacion, medirFuente = true, alAvanzar = () => {} } = {}) {
    const t0 = performance.now();
    escena.updateMatrixWorld(true);
    const antes = estadisticas(escena);
    let bytesFuente = null;
    if (medirFuente) {
        alAvanzar('Midiendo el original…');
        bytesFuente = (await exportarGLB(escena, animaciones, { png: true })).byteLength;
    }

    alAvanzar('Optimizando la geometría y las texturas…');
    const { escena: optima, informe } = optimizar(escena, animaciones, { texturaMax, simplificacion });
    optima.updateMatrixWorld(true);
    const despues = estadisticas(optima);

    alAvanzar('Exportando para la web y para Android…');
    const glbAR = await exportarGLB(optima, animaciones);
    const glb = await comprimirMeshopt(glbAR);

    alAvanzar('Exportando para iPhone y iPad…');
    const usdz = await new USDZExporter().parseAsync(escenaParaUSDZ(optima), {
        quickLookCompatible: true, maxTextureSize: texturaMax,
        ar: { anchoring: { type: 'plane' }, planeAnchoring: { alignment: 'horizontal' } },
    });

    alAvanzar('Comprobando que se abre bien…');
    const tCarga = performance.now();
    const web = await cargarGLB(glb);
    const msCarga = Math.round(performance.now() - tCarga);
    const ar = await cargarGLB(glbAR);
    const contar = (s) => estadisticas(s).triangulos;
    if (contar(web.scene) !== despues.triangulos || contar(ar.scene) !== despues.triangulos) {
        throw new Error('La geometría exportada no coincide con la optimizada.');
    }
    const tam = new THREE.Box3().setFromObject(web.scene).getSize(new THREE.Vector3());

    alAvanzar('Sacando la foto de la tarjeta…');
    const poster = await miniatura(web.scene);

    return {
        archivos: { glb, glbAR, usdz, poster },
        estadisticas: {
            fuente: { ...antes, bytes: bytesFuente },
            optimizado: {
                ...despues,
                bytes: glb.byteLength,
                bytesAR: glbAR.byteLength,
                bytesUSDZ: usdz.byteLength,
                animaciones: web.animations.map((a) => ({ nombre: a.name, duracion: +a.duration.toFixed(2) })),
            },
            medidas: { ancho: +tam.x.toFixed(4), alto: +tam.y.toFixed(4), fondo: +tam.z.toFixed(4) },
            informe,
            msCargaPrueba: msCarga,
            msConstruccion: Math.round(performance.now() - t0),
        },
    };
}
