/*
 * Estilo low poly para las figuras de la galería.
 *
 * Después de que cada modelo se arma (modelos/*.js) y antes de empaquetarlo,
 * pasa por aquí:
 *
 *  1. Pocos polígonos. Cada malla se simplifica (meshoptimizer) hasta que el
 *     modelo entero quede cerca de `triangulos`, sin deformarlo más que
 *     `error` (fracción de su tamaño): las curvas se vuelven facetas, las
 *     siluetas se conservan.
 *  2. Caras planas. Cada triángulo con su propia normal: se ven las facetas
 *     en el visor, en Scene Viewer (Android) y en la Vista rápida (iPhone),
 *     porque va en la geometría y no en un ajuste del visor.
 *  3. Colores sólidos. Un material sencillo por pieza, mate, de un solo color,
 *     sin texturas ni relieves, sin vidrio, brillo satinado, barniz ni luz
 *     propia. Si la pieza tenía una textura de color, se usa su color
 *     promedio. Se conserva el nombre del material: los acabados de
 *     config/galeria3d.php lo usan para cambiar el color.
 *
 * Cada modelo puede ajustar `lowpoly: { triangulos, error }`; `lowpoly: false`
 * lo deja como está.
 */
import * as THREE from 'three';
import { mergeVertices } from 'three/addons/utils/BufferGeometryUtils.js';
import { MeshoptSimplifier } from 'three/addons/libs/meshopt_simplifier.module.js';

const MATE = { roughness: 0.75, metalness: 0 };

/** Color promedio de una textura (su imagen reducida a 8×8). */
function colorPromedio(textura) {
    const img = textura?.image;
    if (!img || !(img.width > 0)) return null;
    try {
        const c = document.createElement('canvas');
        c.width = c.height = 8;
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, 8, 8);
        const d = ctx.getImageData(0, 0, 8, 8).data;
        let r = 0, g = 0, b = 0, n = 0;
        for (let i = 0; i < d.length; i += 4) {
            if (d[i + 3] < 16) continue;
            r += d[i]; g += d[i + 1]; b += d[i + 2]; n++;
        }
        if (!n) return null;
        // los píxeles del lienzo están en sRGB; el color del material, en lineal
        return new THREE.Color().setRGB(r / n / 255, g / n / 255, b / n / 255, THREE.SRGBColorSpace);
    } catch (e) {
        return null;
    }
}

/** Color promedio de los colores por vértice de una malla (el pez arcoíris). */
function colorDeVertices(g) {
    const c = g.attributes.color;
    if (!c) return null;
    const suma = new THREE.Color(0, 0, 0);
    for (let i = 0; i < c.count; i++) { suma.r += c.getX(i); suma.g += c.getY(i); suma.b += c.getZ(i); }
    return suma.multiplyScalar(1 / Math.max(1, c.count));
}

/**
 * Un material de color sólido, mate, que se parece al original. Los colores
 * por vértice no se conservan (la Vista rápida del iPhone no los muestra):
 * cada pieza toma su color promedio, así el pez arcoíris queda en franjas de
 * color sólido, igual en Android y en iPhone.
 */
function materialSolido(m, colorVertices = null) {
    const color = (m.color || new THREE.Color('#cccccc')).clone();
    if (m.vertexColors && colorVertices) color.multiply(colorVertices);
    const promedio = colorPromedio(m.map);
    if (promedio) color.multiply(promedio);
    // vidrio: un color claro opaco, con un toque del tinte que tenía
    if (m.transmission > 0) color.lerp(m.attenuationColor || new THREE.Color('#ffffff'), 0.3).lerp(new THREE.Color('#cfe3ea'), 0.55);
    // lo que brillaba con luz propia (pantalla, LED, litofanía): su color de luz
    const intensidad = m.emissiveIntensity ?? 1;
    if (m.emissive && intensidad > 0 && (m.emissive.r + m.emissive.g + m.emissive.b) > 0.05) {
        const luz = m.emissive.clone();
        const media = colorPromedio(m.emissiveMap);
        if (media) luz.multiply(media);
        color.lerp(luz, 0.6);
    }
    return new THREE.MeshStandardMaterial({
        name: m.name,
        color,
        roughness: MATE.roughness,
        // lo metálico queda apenas metálico: color sólido, sin reflejos de espejo
        metalness: m.metalness > 0.5 ? 0.3 : MATE.metalness,
        flatShading: true,
        side: m.side,
    });
}

/** Aristas de borde (sin su pareja en sentido contrario): 0 = malla cerrada. */
function bordes(indices) {
    const aristas = new Map();
    for (let t = 0; t < indices.length; t += 3) {
        const a = indices[t], b = indices[t + 1], c = indices[t + 2];
        if (a === b || b === c || a === c) continue;
        for (const [u, v] of [[a, b], [b, c], [c, a]]) aristas.set(`${u}>${v}`, (aristas.get(`${u}>${v}`) || 0) + 1);
    }
    let n = 0;
    for (const [k, veces] of aristas) {
        const [u, v] = k.split('>');
        if (veces !== 1 || aristas.get(`${v}>${u}`) !== 1) n++;
    }
    return n;
}

/** Los índices de cada pieza suelta (triángulos conectados entre sí). */
function componentes(indices, vertices) {
    const padre = new Int32Array(vertices).map((_, i) => i);
    const raiz = (x) => { while (padre[x] !== x) { padre[x] = padre[padre[x]]; x = padre[x]; } return x; };
    for (let t = 0; t < indices.length; t += 3) {
        const a = raiz(indices[t]);
        padre[raiz(indices[t + 1])] = a;
        padre[raiz(indices[t + 2])] = a;
    }
    const grupos = new Map();
    for (let t = 0; t < indices.length; t += 3) {
        const r = raiz(indices[t]);
        if (!grupos.has(r)) grupos.set(r, []);
        grupos.get(r).push(indices[t], indices[t + 1], indices[t + 2]);
    }
    return [...grupos.values()].map((l) => new Uint32Array(l));
}

/** Simplifica una geometría y la deja con caras planas. */
function geometriaLowPoly(original, proporcion, error, minimo) {
    let g = original.clone();
    for (const nombre of Object.keys(g.attributes)) {
        if (!['position', 'skinIndex', 'skinWeight'].includes(nombre)) g.deleteAttribute(nombre);
    }
    g.morphAttributes = {};
    // soldar: las costuras de las texturas partían los vértices y no dejaban simplificar
    g = mergeVertices(g, 1e-5);
    const indices = g.index;
    const triangulos = indices.count / 3;
    const objetivo = Math.max(minimo, Math.floor(triangulos * proporcion));
    if (objetivo < triangulos) {
        const pos = g.attributes.position;
        const posiciones = new Float32Array(pos.count * 3);
        for (let i = 0; i < pos.count; i++) {
            posiciones[i * 3] = pos.getX(i); posiciones[i * 3 + 1] = pos.getY(i); posiciones[i * 3 + 2] = pos.getZ(i);
        }
        // Pieza suelta por pieza suelta (las 34 hojas de la suculenta, por
        // ejemplo): simplificarlas juntas dejaba aristas sueltas entre ellas.
        // Simplificar no puede abrir una pieza cerrada —el 3MF y el STL tienen
        // que llegar al laminador sin agujeros—: si pasa, se reintenta con
        // menos reducción; si sigue pasando, esa pieza queda como estaba.
        const partes = componentes(indices.array, pos.count);
        const salida = [];
        for (const parte of partes) {
            const n = parte.length / 3;
            const antes = bordes(parte);
            let elegida = parte;
            for (const factor of [1, 1.5, 2, 3, 4, 6, 8]) {
                const meta = Math.min(n, Math.max(4, Math.round(n * proporcion * factor)));
                if (meta >= n) break;
                const [nuevos] = MeshoptSimplifier.simplify(parte, posiciones, 3, meta * 3, error, []);
                if (nuevos.length >= 12 && bordes(nuevos) <= antes) { elegida = nuevos; break; }
            }
            for (const k of elegida) salida.push(k);
        }
        g.setIndex(new THREE.BufferAttribute(new Uint32Array(salida), 1));
    }
    // cada triángulo con sus propios vértices: normales de cara, facetas visibles
    const plana = g.toNonIndexed();
    plana.computeVertexNormals();
    plana.computeBoundingBox();
    plana.computeBoundingSphere();
    return plana;
}

/**
 * Convierte la escena a low poly. Devuelve cuántos triángulos tenía y tiene.
 */
export async function aLowPoly(escena, { triangulos = 1600, error = 0.01, minimo = 12 } = {}) {
    await MeshoptSimplifier.ready;
    const mallas = [];
    escena.traverse((o) => { if (o.isMesh && o.geometry?.attributes?.position) mallas.push(o); });

    const contar = (g) => (g.index ? g.index.count : g.attributes.position.count) / 3;
    const antes = mallas.reduce((s, o) => s + contar(o.geometry), 0);
    const proporcion = Math.min(1, triangulos / Math.max(1, antes));

    const geometrias = new Map();
    const materiales = new Map();
    const originales = new Set();
    for (const o of mallas) {
        const colorVertices = colorDeVertices(o.geometry);
        if (!geometrias.has(o.geometry)) geometrias.set(o.geometry, geometriaLowPoly(o.geometry, proporcion, error, minimo));
        o.geometry = geometrias.get(o.geometry);
        const lista = [].concat(o.material);
        lista.forEach((m) => originales.add(m));
        const nuevos = lista.map((m) => {
            // con colores por vértice, un material por color (mismo nombre: los acabados siguen sirviendo)
            const clave = m.vertexColors && colorVertices ? `${m.uuid}:${colorVertices.getHexString()}` : m;
            if (!materiales.has(clave)) materiales.set(clave, materialSolido(m, colorVertices));
            return materiales.get(clave);
        });
        o.material = Array.isArray(o.material) ? nuevos : nuevos[0];
        o.castShadow = true;
        o.receiveShadow = true;
    }
    // las originales (y sus texturas) ya no se usan
    for (const g of geometrias.keys()) g.dispose();
    for (const m of originales) {
        for (const v of Object.values(m)) if (v?.isTexture) v.dispose();
        m.dispose();
    }
    const despues = mallas.reduce((s, o) => s + contar(o.geometry), 0);
    return { antes: Math.round(antes), despues: Math.round(despues) };
}
