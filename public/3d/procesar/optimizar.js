/*
 * Optimización de modelos para verlos en tiempo real en el navegador.
 *
 * Lo que hace, en orden, y por qué:
 *
 *  1. Suelda vértices repetidos (mismo punto, misma normal, misma UV): una
 *     malla sin índices repite cada vértice por cada triángulo que lo toca.
 *  2. Simplifica las mallas densas con meshoptimizer, dentro de un error
 *     geométrico máximo: el detalle que no se ve en pantalla no se descarga.
 *  3. Junta en una sola malla las piezas fijas que comparten material: cada
 *     malla es una llamada de dibujo, y en un teléfono eso es lo que más cuesta.
 *     Las piezas animadas se dejan aparte para que sigan moviéndose.
 *  4. Reduce las texturas al tamaño máximo del modelo y las recodifica en
 *     JPEG (PNG solo si llevan transparencia).
 *  5. Comprime la geometría con EXT_meshopt_compression (ver meshopt-gltf.js).
 *
 * Funciona con cualquier escena de three: también con un .glb cargado de
 * fuera, que es como se optimizan los modelos que traiga el laboratorio.
 */
import * as THREE from 'three';
import { mergeVertices, mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { MeshoptSimplifier } from 'three/addons/libs/meshopt_simplifier.module.js';

/* ------------------------------------------------------------------ */
/* Estadísticas                                                         */
/* ------------------------------------------------------------------ */

export function estadisticas(escena) {
    let triangulos = 0, vertices = 0, mallas = 0, llamadas = 0;
    const materiales = new Set(), texturas = new Map();
    escena.traverse((o) => {
        if (!o.isMesh) return;
        mallas++;
        const g = o.geometry;
        const n = g.index ? g.index.count : g.attributes.position.count;
        triangulos += n / 3;
        vertices += g.attributes.position.count;
        const mats = Array.isArray(o.material) ? o.material : [o.material];
        llamadas += Array.isArray(o.material) ? Math.max(1, g.groups.length) : 1;
        for (const m of mats) {
            materiales.add(m);
            for (const k of MAPAS) {
                const t = m[k];
                if (t && t.image) texturas.set(t.image, { ancho: t.image.width, alto: t.image.height });
            }
        }
    });
    return {
        triangulos: Math.round(triangulos), vertices, mallas, llamadas,
        materiales: materiales.size, texturas: [...texturas.values()],
    };
}

const MAPAS = [
    'map', 'normalMap', 'roughnessMap', 'metalnessMap', 'aoMap', 'emissiveMap', 'alphaMap',
    'clearcoatMap', 'clearcoatNormalMap', 'clearcoatRoughnessMap', 'sheenColorMap', 'sheenRoughnessMap',
    'transmissionMap', 'thicknessMap', 'specularIntensityMap', 'specularColorMap',
];

/* ------------------------------------------------------------------ */
/* Geometría                                                            */
/* ------------------------------------------------------------------ */

function usaUV(material) {
    const mats = Array.isArray(material) ? material : [material];
    return mats.some((m) => MAPAS.some((k) => m[k]));
}

/** Quita lo que no se usa, suelda vértices y devuelve una geometría con índices. */
function limpiar(geo, material) {
    let g = geo.clone();
    for (const nombre of Object.keys(g.attributes)) {
        if (!['position', 'normal', 'uv', 'color', 'tangent'].includes(nombre)) g.deleteAttribute(nombre);
    }
    if (!usaUV(material)) g.deleteAttribute('uv');
    const mats = Array.isArray(material) ? material : [material];
    if (!mats.some((m) => m.vertexColors)) g.deleteAttribute('color');
    if (!g.attributes.normal) g.computeVertexNormals();
    g.morphAttributes = {};
    const grupos = g.groups.map((x) => ({ ...x }));
    g = mergeVertices(g, 1e-6);
    // mergeVertices conserva los grupos (los rangos de índices no cambian)
    if (!g.groups.length && grupos.length) grupos.forEach((x) => g.addGroup(x.start, x.count, x.materialIndex));
    return g;
}

/** Quita los vértices que ya no usa ningún triángulo y renumera. */
function compactar(g) {
    const idx = g.index.array;
    const mapa = new Int32Array(g.attributes.position.count).fill(-1);
    let n = 0;
    for (let i = 0; i < idx.length; i++) if (mapa[idx[i]] === -1) mapa[idx[i]] = n++;
    const nuevo = new THREE.BufferGeometry();
    for (const [nombre, attr] of Object.entries(g.attributes)) {
        const s = attr.itemSize;
        const arr = new attr.array.constructor(n * s);
        for (let v = 0; v < mapa.length; v++) {
            if (mapa[v] < 0) continue;
            for (let k = 0; k < s; k++) arr[mapa[v] * s + k] = attr.array[v * s + k];
        }
        nuevo.setAttribute(nombre, new THREE.BufferAttribute(arr, s, attr.normalized));
    }
    const nIdx = n > 65535 ? new Uint32Array(idx.length) : new Uint16Array(idx.length);
    for (let i = 0; i < idx.length; i++) nIdx[i] = mapa[idx[i]];
    nuevo.setIndex(new THREE.BufferAttribute(nIdx, 1));
    g.groups.forEach((x) => nuevo.addGroup(x.start, x.count, x.materialIndex));
    return nuevo;
}

/**
 * Simplifica con meshoptimizer si la malla pasa de `umbral` triángulos.
 * `error` es relativo al tamaño de la malla (0,0008 = 0,08 % del objeto).
 * Los bordes (costuras de UV, cantos abiertos) quedan fijos.
 */
function simplificar(g, { umbral = 12000, proporcion = 0.2, error = 0.0008, pesoNormales = 0.1 } = {}) {
    const triangulos = g.index.count / 3;
    if (triangulos <= umbral || g.groups.length > 1) return { geometria: g, error: 0 };
    const indices = new Uint32Array(g.index.array);
    const posiciones = new Float32Array(g.attributes.position.array);
    const normales = new Float32Array(g.attributes.normal.array);
    const objetivo = Math.max(umbral, Math.floor(triangulos * proporcion)) * 3;
    // Las normales cuentan como atributo: así el simplificador no deja
    // triángulos que cambien el sombreado, solo los que no se notan.
    const [nuevos, err] = MeshoptSimplifier.simplifyWithAttributes(
        indices, posiciones, 3, normales, 3, [pesoNormales, pesoNormales, pesoNormales], null, objetivo, error, ['LockBorder'],
    );
    const s = g.clone();
    s.setIndex(new THREE.BufferAttribute(nuevos, 1));
    s.clearGroups();
    // Los vértices que quedan son un subconjunto de los originales: sus
    // normales (las de la superficie lisa) siguen siendo correctas. Volver a
    // calcularlas sobre los triángulos simplificados daba puntos oscuros.
    const compacta = compactar(s);
    return { geometria: compacta, error: err };
}

/* ------------------------------------------------------------------ */
/* Texturas                                                             */
/* ------------------------------------------------------------------ */

function tieneTransparencia(imagen) {
    const c = document.createElement('canvas');
    c.width = Math.min(64, imagen.width); c.height = Math.min(64, imagen.height);
    const ctx = c.getContext('2d');
    ctx.drawImage(imagen, 0, 0, c.width, c.height);
    const d = ctx.getImageData(0, 0, c.width, c.height).data;
    for (let i = 3; i < d.length; i += 4) if (d[i] < 250) return true;
    return false;
}

function reducirTextura(t, max) {
    const img = t.image;
    const escala = Math.min(1, max / Math.max(img.width, img.height));
    const w = Math.max(1, Math.round(img.width * escala)), h = Math.max(1, Math.round(img.height * escala));
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const ctx = c.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, 0, 0, w, h);
    const n = new THREE.CanvasTexture(c);
    n.name = t.name;
    n.colorSpace = t.colorSpace;
    n.wrapS = t.wrapS; n.wrapT = t.wrapT;
    n.flipY = t.flipY;
    n.repeat.copy(t.repeat); n.offset.copy(t.offset);
    n.anisotropy = t.anisotropy;
    n.userData.mimeType = tieneTransparencia(c) ? 'image/png' : 'image/jpeg';
    return n;
}

/* ------------------------------------------------------------------ */
/* Optimización completa                                                */
/* ------------------------------------------------------------------ */

/**
 * Devuelve una copia optimizada de la escena; la original no se toca.
 * `animaciones`: los clips, para saber qué nodos se mueven y no juntarlos.
 */
export function optimizar(escena, animaciones = [], { texturaMax = 1024, simplificacion = {} } = {}) {
    const copia = escena.clone(true);
    copia.updateMatrixWorld(true);
    const informe = { simplificadas: [], juntadas: 0 };

    // nodos animados (y todo lo que cuelga de ellos) no se juntan
    const animados = new Set();
    for (const clip of animaciones) for (const pista of clip.tracks) animados.add(pista.name.split('.')[0]);
    const esAnimado = (o) => { for (let p = o; p; p = p.parent) if (animados.has(p.name)) return true; return false; };

    // 1 y 2: limpiar, soldar y simplificar cada malla. Una geometría usada
    // por varias mallas (los tres planetas) se procesa una vez y se sigue
    // compartiendo: en el .glb y en el .usdz se guarda una sola vez.
    const hechas = new Map();
    copia.traverse((o) => {
        if (!o.isMesh) return;
        const clave = o.geometry.uuid + '|' + [].concat(o.material).map((m) => m.uuid).join(',');
        if (!hechas.has(clave)) {
            const g = limpiar(o.geometry, o.material);
            const r = simplificar(g, simplificacion);
            if (r.geometria !== g) {
                informe.simplificadas.push({ malla: o.name, antes: g.index.count / 3, despues: r.geometria.index.count / 3, error: +r.error.toFixed(6) });
            }
            hechas.set(clave, r.geometria);
        }
        o.geometry = hechas.get(clave);
    });

    // 3: juntar piezas fijas que comparten material (y el mismo juego de atributos)
    const grupos = new Map();
    copia.traverse((o) => {
        if (!o.isMesh || Array.isArray(o.material) || esAnimado(o)) return;
        const clave = o.material.uuid + '|' + Object.keys(o.geometry.attributes).sort().join(',');
        if (!grupos.has(clave)) grupos.set(clave, []);
        grupos.get(clave).push(o);
    });
    const inversa = new THREE.Matrix4().copy(copia.matrixWorld).invert();
    for (const mallas of grupos.values()) {
        if (mallas.length < 2) continue;
        const geos = mallas.map((m) => {
            const g = m.geometry.clone();
            g.applyMatrix4(new THREE.Matrix4().multiplyMatrices(inversa, m.matrixWorld));
            if (g.index.array instanceof Uint16Array) g.setIndex(new THREE.BufferAttribute(new Uint32Array(g.index.array), 1));
            return g;
        });
        const unida = mergeGeometries(geos, false);
        if (!unida) continue;
        const final = compactar(unida); // vuelve a índices de 16 bits si caben
        const nueva = new THREE.Mesh(final, mallas[0].material);
        nueva.name = mallas.length > 1 ? `${mallas[0].material.name}` : mallas[0].name;
        mallas.forEach((m) => m.parent.remove(m));
        copia.add(nueva);
        informe.juntadas += mallas.length - 1;
    }
    // quitar grupos que quedaron vacíos (y que no estén animados)
    const vacios = [];
    copia.traverse((o) => { if (o !== copia && !o.isMesh && o.children.length === 0 && !animados.has(o.name)) vacios.push(o); });
    vacios.forEach((o) => o.parent && o.parent.remove(o));

    // 4: texturas (una sola copia reducida por textura original, para que sigan compartidas)
    const cache = new Map();
    const materiales = new Set();
    copia.traverse((o) => { if (o.isMesh) (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => materiales.add(m)); });
    const reemplazos = new Map();
    for (const m of materiales) {
        const n = m.clone();
        for (const k of MAPAS) {
            if (!m[k]) continue;
            if (!cache.has(m[k])) cache.set(m[k], reducirTextura(m[k], texturaMax));
            n[k] = cache.get(m[k]);
        }
        reemplazos.set(m, n);
    }
    copia.traverse((o) => {
        if (!o.isMesh) return;
        o.material = Array.isArray(o.material) ? o.material.map((m) => reemplazos.get(m)) : reemplazos.get(o.material);
    });

    return { escena: copia, informe };
}
