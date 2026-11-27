/*
 * Descargas de la Galería 3D en formatos de taller: STEP, STL, 3MF y FBX.
 *
 * Se generan en el navegador de quien descarga, a partir del mismo .glb sin
 * comprimir que usa la realidad aumentada en Android. Así sirven para todos
 * los modelos —los de ejemplo y los que se suban desde el panel— sin guardar
 * tres archivos más por modelo en el servidor. El .glb se descarga tal cual.
 *
 * Lo que conviene saber de cada formato:
 *
 *  - STL: binario, en milímetros y con Z hacia arriba, que es lo que esperan
 *    Cura, PrusaSlicer, Bambu Studio y compañía. Todas las piezas en uno.
 *  - 3MF: en milímetros y Z arriba, como el STL, pero con cada pieza como
 *    parte de un mismo objeto, con su nombre y su color. Es el formato propio
 *    de PrusaSlicer, Bambu Studio y Cura.
 *  - STEP (AP214): cada pieza cerrada sale como un sólido y las abiertas como
 *    superficies, con su nombre y su color. Los triángulos que están en un
 *    mismo plano se juntan en una sola cara, para que una caja tenga seis
 *    caras y no doce. Una pieza curva con muchísimos triángulos (una rosca,
 *    una litofanía) se simplifica hasta MAX_CARAS_STEP para que el CAD la
 *    abra sin quedarse sin memoria. OJO: es geometría facetada; no recupera cilindros ni
 *    curvas paramétricas, ni el historial de operaciones del CAD original.
 *  - FBX 7.4 binario (el ASCII no lo abre Blender): en centímetros, Y hacia
 *    arriba, con normales, coordenadas UV, colores y la textura de color
 *    incrustada. Una pieza por material.
 *
 * Todas las piezas salen en la posición de reposo del modelo, sin animación.
 */
import * as THREE from 'three';
import { MeshoptSimplifier } from 'three/addons/libs/meshopt_simplifier.module.js';

/* ================================================================== */
/* Las piezas del modelo, ya en coordenadas del mundo                   */
/* ================================================================== */

/**
 * Recorre el modelo y devuelve una pieza por cada malla y material, con los
 * vértices ya transformados (metros, Y arriba, como en glTF).
 */
function recogerPiezas(raiz) {
    raiz.updateMatrixWorld(true);
    const piezas = [];
    const matriz = new THREE.Matrix4();
    const normalMatriz = new THREE.Matrix3();
    const v = new THREE.Vector3();

    raiz.traverse((o) => {
        if (!o.isMesh || !o.visible || !o.geometry?.attributes?.position) return;
        const g = o.geometry;
        const pos = g.attributes.position;
        const nor = g.attributes.normal;
        const uv = g.attributes.uv;
        const instancias = o.isInstancedMesh ? o.count : 1;

        for (let k = 0; k < instancias; k++) {
            matriz.copy(o.matrixWorld);
            if (o.isInstancedMesh) {
                const mi = new THREE.Matrix4();
                o.getMatrixAt(k, mi);
                matriz.multiply(mi);
            }
            normalMatriz.getNormalMatrix(matriz);
            const espejo = matriz.determinant() < 0;

            const n = pos.count;
            const P = new Float64Array(n * 3);
            const N = nor ? new Float32Array(n * 3) : null;
            const U = uv ? new Float32Array(n * 2) : null;
            for (let i = 0; i < n; i++) {
                v.fromBufferAttribute(pos, i).applyMatrix4(matriz);
                P[i * 3] = v.x; P[i * 3 + 1] = v.y; P[i * 3 + 2] = v.z;
                if (N) {
                    v.fromBufferAttribute(nor, i).applyMatrix3(normalMatriz).normalize();
                    N[i * 3] = v.x; N[i * 3 + 1] = v.y; N[i * 3 + 2] = v.z;
                }
                if (U) { U[i * 2] = uv.getX(i); U[i * 2 + 1] = uv.getY(i); }
            }

            const indice = g.index;
            const total = indice ? indice.count : n;
            const materiales = Array.isArray(o.material) ? o.material : [o.material];
            const grupos = Array.isArray(o.material) && g.groups.length
                ? g.groups
                : [{ start: 0, count: total, materialIndex: 0 }];

            grupos.forEach((grupo) => {
                const desde = Math.max(0, grupo.start);
                const hasta = Math.min(total, grupo.start + grupo.count);
                const tris = [];
                for (let i = desde; i + 2 < hasta; i += 3) {
                    const a = indice ? indice.getX(i) : i;
                    let b = indice ? indice.getX(i + 1) : i + 1;
                    let c = indice ? indice.getX(i + 2) : i + 2;
                    if (espejo) [b, c] = [c, b];
                    tris.push(a, b, c);
                }
                if (!tris.length) return;
                const material = materiales[grupo.materialIndex ?? 0] || materiales[0];
                let nombre = o.name || 'Pieza';
                if (grupos.length > 1 && material?.name) nombre += ` · ${material.name}`;
                if (instancias > 1) nombre += ` ${k + 1}`;
                // Una malla glTF con varios materiales llega como un grupo de mallas
                // (el visor lo marca con userData.unaPieza): en STEP es un solo cuerpo.
                const dueno = o.parent?.userData?.unaPieza ? o.parent : o;
                const cuerpo = `${dueno.uuid}#${k}`;
                const nombreCuerpo = (dueno.name || o.name || 'Pieza') + (instancias > 1 ? ` ${k + 1}` : '');
                piezas.push({ nombre, material, P, N, U, tris: Uint32Array.from(tris), cuerpo, nombreCuerpo });
            });
        }
    });

    // Nombres únicos: los programas de CAD los usan para identificar cuerpos.
    const vistos = new Map();
    for (const p of piezas) {
        const veces = (vistos.get(p.nombre) || 0) + 1;
        vistos.set(p.nombre, veces);
        if (veces > 1) p.nombre = `${p.nombre} (${veces})`;
    }
    return piezas;
}

/**
 * Color del material en sRGB 0–1 (lo que muestran los programas de CAD). Si
 * el color viene de una textura (la madera, por ejemplo), se usa el color
 * promedio de la textura: un STEP no lleva texturas y saldría blanco.
 */
const promedios = new WeakMap();
function colorSRGB(material) {
    const c = material?.color ? material.color.clone() : new THREE.Color(0.8, 0.8, 0.8);
    c.convertLinearToSRGB();
    const textura = promedioDeTextura(material?.map);
    return textura ? [c.r * textura[0], c.g * textura[1], c.b * textura[2]] : [c.r, c.g, c.b];
}

function promedioDeTextura(textura) {
    const img = textura?.image;
    if (!img || !img.width || typeof document === 'undefined') return null;
    if (promedios.has(img)) return promedios.get(img);
    let resultado = null;
    try {
        const lado = 16;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = lado;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, lado, lado);
        const px = ctx.getImageData(0, 0, lado, lado).data;
        const suma = [0, 0, 0];
        for (let i = 0; i < px.length; i += 4) { suma[0] += px[i]; suma[1] += px[i + 1]; suma[2] += px[i + 2]; }
        const n = (px.length / 4) * 255;
        resultado = [suma[0] / n, suma[1] / n, suma[2] / n];
    } catch (e) {
        resultado = null;
    }
    promedios.set(img, resultado);
    return resultado;
}

/** De glTF (metros, Y arriba) a CAD (milímetros, Z arriba). */
const aCAD = (P, i) => [P[i * 3] * 1000, -P[i * 3 + 2] * 1000, P[i * 3 + 1] * 1000];

/* ================================================================== */
/* STL                                                                  */
/* ================================================================== */

export function exportarSTL(raiz, { nombre = 'modelo' } = {}) {
    const piezas = recogerPiezas(raiz);
    const totalTris = piezas.reduce((s, p) => s + p.tris.length / 3, 0);
    const buffer = new ArrayBuffer(84 + totalTris * 50);
    const dv = new DataView(buffer);
    const cabecera = `fabOS Galeria 3D - ${ascii(nombre)} - mm, Z arriba`.slice(0, 80);
    for (let i = 0; i < cabecera.length; i++) dv.setUint8(i, cabecera.charCodeAt(i));
    dv.setUint32(80, totalTris, true);

    let o = 84;
    for (const p of piezas) {
        for (let t = 0; t < p.tris.length; t += 3) {
            const a = aCAD(p.P, p.tris[t]), b = aCAD(p.P, p.tris[t + 1]), c = aCAD(p.P, p.tris[t + 2]);
            const n = normalTriangulo(a, b, c);
            for (const x of [...n, ...a, ...b, ...c]) { dv.setFloat32(o, x, true); o += 4; }
            dv.setUint16(o, 0, true); o += 2;
        }
    }
    return buffer;
}

/* ================================================================== */
/* 3MF                                                                  */
/* ================================================================== */

const xml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const mm = (x) => (Math.round(x * 10000) / 10000).toString();

function hexColor(material) {
    return '#' + colorSRGB(material).map((v) => Math.round(Math.min(1, Math.max(0, v)) * 255).toString(16).padStart(2, '0')).join('').toUpperCase();
}

/**
 * 3MF (núcleo 1.3): el formato de PrusaSlicer, Bambu Studio, Cura y
 * Windows. En milímetros y Z arriba, cada pieza como una parte de un solo
 * objeto, con su nombre y su color (basematerials), así que en el laminador
 * llega armado y con los colores de la galería. Los vértices se sueldan por
 * posición: cada pieza queda como una malla cerrada, sin grietas.
 */
export async function exportar3MF(raiz, { nombre = 'modelo', autor = 'FabLab EAN' } = {}) {
    const { zipSync, strToU8 } = await import('three/addons/libs/fflate.module.js');
    const piezas = recogerPiezas(raiz);

    // un color base por material distinto
    const colores = [];
    const indiceColor = new Map();
    for (const p of piezas) {
        const clave = p.material?.uuid || 'sin-material';
        if (!indiceColor.has(clave)) {
            indiceColor.set(clave, colores.length);
            colores.push(`<base name="${xml(p.material?.name || 'Material')}" displaycolor="${hexColor(p.material)}"/>`);
        }
    }

    const objetos = [];
    const componentes = [];
    let id = 2;   // 1 = basematerials
    for (const p of piezas) {
        // soldar por posición (0,1 µm): la geometría low poly repite vértices por cara
        const mapa = new Map();
        const vertices = [];
        const remap = new Uint32Array(p.P.length / 3);
        for (let i = 0; i < remap.length; i++) {
            const [x, y, z] = aCAD(p.P, i);
            const clave = `${Math.round(x * 1e4)},${Math.round(y * 1e4)},${Math.round(z * 1e4)}`;
            let k = mapa.get(clave);
            if (k === undefined) {
                k = vertices.length;
                mapa.set(clave, k);
                vertices.push(`<vertex x="${mm(x)}" y="${mm(y)}" z="${mm(z)}"/>`);
            }
            remap[i] = k;
        }
        const triangulos = [];
        for (let t = 0; t < p.tris.length; t += 3) {
            const a = remap[p.tris[t]], b = remap[p.tris[t + 1]], c = remap[p.tris[t + 2]];
            if (a === b || b === c || a === c) continue;   // se aplastó al soldar
            triangulos.push(`<triangle v1="${a}" v2="${b}" v3="${c}"/>`);
        }
        if (!triangulos.length) continue;
        const color = indiceColor.get(p.material?.uuid || 'sin-material');
        objetos.push(`<object id="${id}" type="model" name="${xml(p.nombre)}" pid="1" pindex="${color}"><mesh><vertices>${vertices.join('')}</vertices><triangles>${triangulos.join('')}</triangles></mesh></object>`);
        componentes.push(`<component objectid="${id}"/>`);
        id++;
    }
    if (!objetos.length) throw new Error('El modelo no tiene triángulos para el 3MF.');

    const fecha = new Date().toISOString().slice(0, 10);
    const modelo = '<?xml version="1.0" encoding="UTF-8"?>\n'
        + '<model unit="millimeter" xml:lang="es-CO" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
        + `<metadata name="Title">${xml(nombre)}</metadata>`
        + `<metadata name="Designer">${xml(autor)}</metadata>`
        + '<metadata name="Application">fabOS · Galería 3D</metadata>'
        + `<metadata name="CreationDate">${fecha}</metadata>`
        + '<resources>'
        + `<basematerials id="1">${colores.join('')}</basematerials>`
        + objetos.join('')
        + `<object id="${id}" type="model" name="${xml(nombre)}"><components>${componentes.join('')}</components></object>`
        + '</resources>'
        + `<build><item objectid="${id}"/></build>`
        + '</model>';

    return zipSync({
        '[Content_Types].xml': strToU8('<?xml version="1.0" encoding="UTF-8"?>\n<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>'),
        '_rels/.rels': strToU8('<?xml version="1.0" encoding="UTF-8"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>'),
        '3D/3dmodel.model': strToU8(modelo),
    }, { level: 6 }).buffer;
}

function normalTriangulo(a, b, c) {
    const ux = b[0] - a[0], uy = b[1] - a[1], uz = b[2] - a[2];
    const vx = c[0] - a[0], vy = c[1] - a[1], vz = c[2] - a[2];
    const x = uy * vz - uz * vy, y = uz * vx - ux * vz, z = ux * vy - uy * vx;
    const l = Math.hypot(x, y, z);
    return l > 0 ? [x / l, y / l, z / l] : [0, 0, 0];
}

const ascii = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\x20-\x7e]/g, '');

/* ================================================================== */
/* STEP                                                                 */
/* ================================================================== */

// Distancia máxima (mm) de un vértice al plano para juntar triángulos en una
// cara: una micra. Mucho menos de lo que distingue cualquier máquina del lab.
const TOLERANCIA_PLANO = 1e-3;
// Más caras que esto por pieza y el STEP pesa decenas de MB y los programas de
// CAD tardan minutos en abrirlo (o se quedan sin memoria).
const MAX_CARAS_STEP = 12000;
const COS_PLANO = 1 - 1e-7;

/** Cadena STEP: ASCII, comillas dobladas y lo demás con \X2\…\X0\. */
function cadenaSTEP(s) {
    let r = '';
    for (const ch of String(s ?? '')) {
        const c = ch.codePointAt(0);
        if (c === 39) r += "''";
        else if (c === 92) r += '\\\\';
        else if (c >= 32 && c < 127) r += ch;
        else if (c <= 0xffff) r += `\\X2\\${c.toString(16).toUpperCase().padStart(4, '0')}\\X0\\`;
    }
    return `'${r}'`;
}

/** Real STEP: siempre con punto decimal y sin notación «1e-7». */
function real(v, decimales = 6) {
    let s = (Math.abs(v) < 0.5 * 10 ** -decimales ? 0 : v).toFixed(decimales).replace(/0+$/, '');
    if (s === '-0.') s = '0.';
    return s;
}

/**
 * Une vértices repetidos (las mallas para la pantalla duplican vértices en
 * los bordes y costuras de UV) y quita triángulos degenerados. Recibe las
 * piezas de un mismo cuerpo y anota de cuál viene cada triángulo.
 */
function soldar(lista) {
    const mapa = new Map();
    const puntos = [];
    const tris = [];
    const mats = [];
    lista.forEach((pieza, indice) => {
        const n = pieza.P.length / 3;
        const nuevo = new Int32Array(n);
        for (let i = 0; i < n; i++) {
            const p = aCAD(pieza.P, i);
            const clave = `${Math.round(p[0] * 1e4)},${Math.round(p[1] * 1e4)},${Math.round(p[2] * 1e4)}`;
            let k = mapa.get(clave);
            if (k === undefined) { k = puntos.length / 3; mapa.set(clave, k); puntos.push(p[0], p[1], p[2]); }
            nuevo[i] = k;
        }
        for (let t = 0; t < pieza.tris.length; t += 3) {
            const a = nuevo[pieza.tris[t]], b = nuevo[pieza.tris[t + 1]], c = nuevo[pieza.tris[t + 2]];
            if (a === b || b === c || a === c) continue;
            if (aplastado(puntos, a, b, c)) continue;
            tris.push(a, b, c);
            mats.push(indice);
        }
    });
    return { puntos: Float64Array.from(puntos), tris: Int32Array.from(tris), mats: Int32Array.from(mats) };
}

/**
 * Un triángulo aplastado (los tres puntos casi en línea: su altura no llega a
 * la tolerancia) no sirve para hacer una cara en CAD. Se quita, y el hueco sin
 * área que deja lo cierra repararUnionesT partiendo al vecino.
 */
function aplastado(P, a, b, c) {
    const doble = Math.hypot(...normalCruda(P, a, b, c));
    const lado = (i, j) => Math.hypot(P[i * 3] - P[j * 3], P[i * 3 + 1] - P[j * 3 + 1], P[i * 3 + 2] - P[j * 3 + 2]);
    const mayor = Math.max(lado(a, b), lado(b, c), lado(c, a));
    return !(mayor > 0) || doble / mayor < TOLERANCIA_PLANO;
}

/**
 * Si una pieza de un solo material pasa de MAX_CARAS_STEP triángulos, se
 * simplifica (el mismo simplificador que optimiza la galería), con un error
 * máximo del 0,1 % de su tamaño y sin mover sus bordes.
 */
function simplificarParaCAD(malla) {
    const n = malla.tris.length / 3;
    if (n <= MAX_CARAS_STEP || malla.mats.some((m) => m !== malla.mats[0])) return malla;
    const [indices] = MeshoptSimplifier.simplify(
        Uint32Array.from(malla.tris), Float32Array.from(malla.puntos), 3, MAX_CARAS_STEP * 3, 1e-3, ['LockBorder'],
    );
    const tris = [];
    for (let t = 0; t < indices.length; t += 3) {
        if (!aplastado(malla.puntos, indices[t], indices[t + 1], indices[t + 2])) tris.push(indices[t], indices[t + 1], indices[t + 2]);
    }
    return { puntos: malla.puntos, tris: Int32Array.from(tris), mats: new Int32Array(tris.length / 3).fill(malla.mats[0]) };
}

function normalCruda(P, a, b, c) {
    const ux = P[b * 3] - P[a * 3], uy = P[b * 3 + 1] - P[a * 3 + 1], uz = P[b * 3 + 2] - P[a * 3 + 2];
    const vx = P[c * 3] - P[a * 3], vy = P[c * 3 + 1] - P[a * 3 + 1], vz = P[c * 3 + 2] - P[a * 3 + 2];
    return [uy * vz - uz * vy, uz * vx - ux * vz, ux * vy - uy * vx];
}

/**
 * Arregla las uniones en «T»: un borde largo de un triángulo que pasa por el
 * vértice de otro (típico de las uniones de dedos de un corte láser). Se
 * parte el triángulo largo para que los dos lados compartan aristas.
 */
function repararUnionesT(puntos, entrada, materiales) {
    let tris = Array.from(entrada);
    let mats = Array.from(materiales);
    const nv = puntos.length / 3;
    const clave = (a, b) => (a < b ? a * nv + b : b * nv + a);
    const TOL2 = TOLERANCIA_PLANO * TOLERANCIA_PLANO;

    for (let ronda = 0; ronda < 4; ronda++) {
        const usos = new Map();
        for (let i = 0; i < tris.length; i += 3) {
            for (let s = 0; s < 3; s++) {
                const k = clave(tris[i + s], tris[i + (s + 1) % 3]);
                usos.set(k, (usos.get(k) || 0) + 1);
            }
        }
        const libres = [];
        const vertices = new Set();
        for (let i = 0; i < tris.length; i += 3) {
            for (let s = 0; s < 3; s++) {
                const a = tris[i + s], b = tris[i + (s + 1) % 3];
                if (usos.get(clave(a, b)) % 2 === 1) { libres.push(i, s); vertices.add(a); vertices.add(b); }
            }
        }
        // Una malla abierta enorme (una superficie suelta) no se revisa: sería
        // lento y no la convertiría en sólido de todos modos.
        if (!libres.length || (libres.length / 2) * vertices.size > 4e7) break;
        const candidatos = Array.from(vertices);

        const partido = new Set();
        const nuevos = [];
        const nuevosMats = [];
        for (let k = 0; k < libres.length; k += 2) {
            const i = libres[k], s = libres[k + 1];
            if (partido.has(i)) continue;
            const a = tris[i + s], b = tris[i + (s + 1) % 3], c = tris[i + (s + 2) % 3];
            const ax = puntos[a * 3], ay = puntos[a * 3 + 1], az = puntos[a * 3 + 2];
            const dx = puntos[b * 3] - ax, dy = puntos[b * 3 + 1] - ay, dz = puntos[b * 3 + 2] - az;
            const l2 = dx * dx + dy * dy + dz * dz;
            const enMedio = [];
            for (const p of candidatos) {
                if (p === a || p === b) continue;
                const px = puntos[p * 3] - ax, py = puntos[p * 3 + 1] - ay, pz = puntos[p * 3 + 2] - az;
                const t = (px * dx + py * dy + pz * dz) / l2;
                if (t <= 1e-6 || t >= 1 - 1e-6) continue;
                const ex = px - t * dx, ey = py - t * dy, ez = pz - t * dz;
                if (ex * ex + ey * ey + ez * ez <= TOL2) enMedio.push([t, p]);
            }
            if (!enMedio.length) continue;
            enMedio.sort((x, y) => x[0] - y[0]);
            const cadena = [a, ...enMedio.map((x) => x[1]), b];
            for (let j = 0; j + 1 < cadena.length; j++) { nuevos.push(cadena[j], cadena[j + 1], c); nuevosMats.push(mats[i / 3]); }
            partido.add(i);
        }
        if (!partido.size) break;
        const quedan = [], quedanMats = [];
        for (let i = 0; i < tris.length; i += 3) if (!partido.has(i)) { quedan.push(tris[i], tris[i + 1], tris[i + 2]); quedanMats.push(mats[i / 3]); }
        tris = quedan.concat(nuevos);
        mats = quedanMats.concat(nuevosMats);
    }
    return { tris: Int32Array.from(tris), mats: Int32Array.from(mats) };
}

/**
 * Parte la malla en piezas y, para cada una, arma sus caras planas con sus
 * bucles de aristas.
 *
 * Cada «lado» de triángulo (triángulo·3 + 0, 1 o 2, del vértice s al s+1) se
 * empareja con el lado del triángulo vecino que recorre la misma arista al
 * revés. Si una arista la comparten cuatro triángulos (dos piezas que se
 * tocan por un canto), se empareja cada triángulo con el primero que
 * encuentra girando hacia dentro de su pieza, y así las dos piezas quedan
 * separadas. Una pieza es un sólido si todos sus lados tienen pareja.
 */
function topologia({ puntos, tris: entrada, mats: entradaMats }) {
    const { tris: T, mats: M } = repararUnionesT(puntos, entrada, entradaMats);
    const m = T.length / 3;
    const nv = puntos.length / 3;
    const clave = (a, b) => (a < b ? a * nv + b : b * nv + a);
    const desde = (l) => T[l];
    const hasta = (l) => T[(l - (l % 3)) + ((l % 3) + 1) % 3];
    const opuesto = (l) => T[(l - (l % 3)) + ((l % 3) + 2) % 3];
    const pt = (i) => [puntos[i * 3], puntos[i * 3 + 1], puntos[i * 3 + 2]];
    const resta = (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
    const punto3 = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
    const cruz = (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
    const unit = (a) => { const l = Math.hypot(...a) || 1; return [a[0] / l, a[1] / l, a[2] / l]; };

    const normal = new Float64Array(m * 3);
    for (let t = 0; t < m; t++) {
        const n = unit(normalCruda(puntos, T[t * 3], T[t * 3 + 1], T[t * 3 + 2]));
        normal[t * 3] = n[0]; normal[t * 3 + 1] = n[1]; normal[t * 3 + 2] = n[2];
    }
    const normalDe = (t) => [normal[t * 3], normal[t * 3 + 1], normal[t * 3 + 2]];

    // Parejas de lados.
    const usos = new Map();
    for (let l = 0; l < m * 3; l++) {
        const k = clave(desde(l), hasta(l));
        if (!usos.has(k)) usos.set(k, []);
        usos.get(k).push(l);
    }
    const pareja = new Int32Array(m * 3).fill(-1);
    for (const lista of usos.values()) {
        if (lista.length === 2) {
            const [x, y] = lista;
            if (desde(x) === hasta(y) && hasta(x) === desde(y)) { pareja[x] = y; pareja[y] = x; }
            continue;
        }
        if (lista.length < 4 || lista.length % 2) continue;
        // Varias piezas en la misma arista: orden radial alrededor de ella.
        const a0 = Math.min(desde(lista[0]), hasta(lista[0])), b0 = Math.max(desde(lista[0]), hasta(lista[0]));
        const eje = unit(resta(pt(b0), pt(a0)));
        const ala = (l) => { const w = resta(pt(opuesto(l)), pt(a0)); const p = punto3(w, eje); return unit([w[0] - p * eje[0], w[1] - p * eje[1], w[2] - p * eje[2]]); };
        const ref = ala(lista[0]);
        const perp = cruz(eje, ref);
        const info = lista.map((l) => {
            const w = ala(l);
            let ang = Math.atan2(punto3(w, perp), punto3(w, ref));
            if (ang < 0) ang += 2 * Math.PI;
            // Hacia dónde queda el interior de la pieza: al lado contrario de la normal.
            const haciaDentro = -punto3(normalDe((l - (l % 3)) / 3), cruz(eje, w)) > 0 ? 1 : -1;
            return { l, ang, haciaDentro };
        }).sort((x, y) => x.ang - y.ang);
        const elegido = new Map();
        info.forEach((x, i) => {
            const j = (i + x.haciaDentro + info.length) % info.length;
            elegido.set(x.l, info[j].l);
        });
        for (const [x, y] of elegido) {
            if (elegido.get(y) === x && desde(x) === hasta(y) && hasta(x) === desde(y)) { pareja[x] = y; pareja[y] = x; }
        }
    }

    // Piezas: triángulos unidos por lados emparejados.
    const pieza = new Int32Array(m).fill(-1);
    const piezas = [];
    for (let t0 = 0; t0 < m; t0++) {
        if (pieza[t0] !== -1) continue;
        const lista = [t0];
        pieza[t0] = piezas.length;
        for (let i = 0; i < lista.length; i++) {
            for (let s = 0; s < 3; s++) {
                const p = pareja[lista[i] * 3 + s];
                if (p < 0) continue;
                const o = (p - (p % 3)) / 3;
                if (pieza[o] === -1) { pieza[o] = piezas.length; lista.push(o); }
            }
        }
        piezas.push(lista);
    }

    const componentes = [];
    for (const lista of piezas) {
        const cerrada = lista.every((t) => pareja[t * 3] >= 0 && pareja[t * 3 + 1] >= 0 && pareja[t * 3 + 2] >= 0);
        if (cerrada) {
            // Si la pieza está al revés (volumen negativo), se le da la vuelta.
            let vol = 0;
            for (const t of lista) vol += punto3(pt(T[t * 3]), cruz(pt(T[t * 3 + 1]), pt(T[t * 3 + 2])));
            if (vol < 0) {
                // Intercambiar los vértices 1 y 2 invierte los lados: 0↔2 y 1 queda.
                const mover = [2, 1, 0];
                const nuevaPareja = new Map();
                for (const t of lista) for (let s = 0; s < 3; s++) {
                    const p = pareja[t * 3 + s];
                    nuevaPareja.set(t * 3 + mover[s], (p - (p % 3)) + mover[p % 3]);
                }
                for (const t of lista) {
                    const x = T[t * 3 + 1]; T[t * 3 + 1] = T[t * 3 + 2]; T[t * 3 + 2] = x;
                    normal[t * 3] *= -1; normal[t * 3 + 1] *= -1; normal[t * 3 + 2] *= -1;
                }
                for (const [l, p] of nuevaPareja) pareja[l] = p;
            }
        }

        // Regiones planas: triángulos vecinos en el mismo plano que la semilla.
        const region = new Map();
        const regiones = [];
        for (const semilla of lista) {
            if (region.has(semilla)) continue;
            const id = regiones.length;
            const n = normalDe(semilla);
            const d = punto3(n, pt(T[semilla * 3]));
            const miembros = [semilla];
            region.set(semilla, id);
            for (let i = 0; i < miembros.length; i++) {
                for (let s = 0; s < 3; s++) {
                    const p = pareja[miembros[i] * 3 + s];
                    if (p < 0) continue;
                    const o = (p - (p % 3)) / 3;
                    // Una cara no mezcla materiales: cada una conserva su color.
                    if (region.has(o) || M[o] !== M[semilla] || punto3(n, normalDe(o)) < COS_PLANO) continue;
                    let enPlano = true;
                    for (let q = 0; q < 3 && enPlano; q++) enPlano = Math.abs(punto3(n, pt(T[o * 3 + q])) - d) <= TOLERANCIA_PLANO;
                    if (!enPlano) continue;
                    region.set(o, id);
                    miembros.push(o);
                }
            }
            regiones.push({ normal: n, miembros, material: M[semilla] });
        }

        // Bucles del borde de cada región (listas de lados).
        const caras = [];
        regiones.forEach(({ normal: n, miembros, material }, r) => {
            const salientes = new Map();
            for (const t of miembros) {
                for (let s = 0; s < 3; s++) {
                    const l = t * 3 + s;
                    const p = pareja[l];
                    if (p >= 0 && region.get((p - (p % 3)) / 3) === r) continue;
                    const a = desde(l);
                    if (!salientes.has(a)) salientes.set(a, []);
                    salientes.get(a).push(l);
                }
            }
            const usado = new Set();
            const bucles = [];
            for (const lista2 of salientes.values()) {
                for (const inicio of lista2) {
                    if (usado.has(inicio)) continue;
                    const bucle = [inicio];
                    usado.add(inicio);
                    let actual = inicio;
                    let cerrado = false;
                    for (let guarda = 0; guarda < 1e6; guarda++) {
                        const v = hasta(actual);
                        const opciones = (salientes.get(v) || []).filter((l) => l === inicio || !usado.has(l));
                        if (!opciones.length) break;
                        let siguiente = opciones[0];
                        if (opciones.length > 1) {
                            // En un vértice donde se tocan dos bordes, se sigue el que
                            // gira menos en sentido horario: así no se mezclan bucles.
                            const atras = resta(pt(desde(actual)), pt(v));
                            let mejor = Infinity;
                            for (const l of opciones) {
                                const w = resta(pt(hasta(l)), pt(v));
                                let ccw = Math.atan2(punto3(n, cruz(atras, w)), punto3(atras, w));
                                if (ccw <= 0) ccw += 2 * Math.PI;
                                const horario = 2 * Math.PI - ccw;
                                if (horario < mejor) { mejor = horario; siguiente = l; }
                            }
                        }
                        if (siguiente === inicio) { cerrado = true; break; }
                        usado.add(siguiente);
                        bucle.push(siguiente);
                        actual = siguiente;
                    }
                    if (cerrado && bucle.length >= 3) bucles.push(bucle);
                }
            }
            if (!bucles.length) return;

            // El bucle exterior es el de mayor área positiva respecto a la normal.
            const area = (b) => {
                let acc = [0, 0, 0];
                for (const l of b) { const c = cruz(pt(desde(l)), pt(hasta(l))); acc = [acc[0] + c[0], acc[1] + c[1], acc[2] + c[2]]; }
                return punto3(acc, n) / 2;
            };
            let exterior = 0, mayor = -Infinity;
            bucles.forEach((b, i) => { const a = area(b); if (a > mayor) { mayor = a; exterior = i; } });
            caras.push({ normal: n, bucles, exterior, material });
        });

        // La arista de un lado: la misma para el lado y su pareja.
        const claveArista = (l) => (pareja[l] >= 0 ? `p${Math.min(l, pareja[l])}` : `s${l}`);
        componentes.push({ cerrada, caras, claveArista, desde, hasta });
    }
    return componentes;
}

export async function exportarSTEP(raiz, { nombre = 'modelo', archivo = 'modelo.step', autor = 'FabLab EAN' } = {}) {
    await MeshoptSimplifier.ready;
    const piezas = recogerPiezas(raiz);
    const L = [];
    let id = 0;
    const e = (s) => { id++; L.push(`#${id}=${s};`); return `#${id}`; };

    // Contexto: producto, unidades (mm) y tolerancia.
    const appCtx = e("APPLICATION_CONTEXT('automotive design')");
    e(`APPLICATION_PROTOCOL_DEFINITION('international standard','automotive_design',2000,${appCtx})`);
    const prodCtx = e(`PRODUCT_CONTEXT('',${appCtx},'mechanical')`);
    const producto = e(`PRODUCT(${cadenaSTEP(nombre)},${cadenaSTEP(nombre)},'',(${prodCtx}))`);
    e(`PRODUCT_RELATED_PRODUCT_CATEGORY('part',$,(${producto}))`);
    const pdf = e(`PRODUCT_DEFINITION_FORMATION('','',${producto})`);
    const pdCtx = e(`PRODUCT_DEFINITION_CONTEXT('part definition',${appCtx},'design')`);
    const pd = e(`PRODUCT_DEFINITION('design','',${pdf},${pdCtx})`);
    const pds = e(`PRODUCT_DEFINITION_SHAPE('','',${pd})`);
    const mm = e('(LENGTH_UNIT()NAMED_UNIT(*)SI_UNIT(.MILLI.,.METRE.))');
    const rad = e('(NAMED_UNIT(*)PLANE_ANGLE_UNIT()SI_UNIT($,.RADIAN.))');
    const sr = e('(NAMED_UNIT(*)SI_UNIT($,.STERADIAN.)SOLID_ANGLE_UNIT())');
    const incert = e(`UNCERTAINTY_MEASURE_WITH_UNIT(LENGTH_MEASURE(1.E-03),${mm},'distance_accuracy_value','confusion accuracy')`);
    const ctx = e(`(GEOMETRIC_REPRESENTATION_CONTEXT(3)GLOBAL_UNCERTAINTY_ASSIGNED_CONTEXT((${incert}))GLOBAL_UNIT_ASSIGNED_CONTEXT((${mm},${rad},${sr}))REPRESENTATION_CONTEXT('Context3D','3D Context with UNIT and UNCERTAINTY'))`);
    const origen = e(`AXIS2_PLACEMENT_3D('',${e("CARTESIAN_POINT('',(0.,0.,0.))")},${e("DIRECTION('',(0.,0.,1.))")},${e("DIRECTION('',(1.,0.,0.))")})`);

    const solidos = [], superficies = [], estilos = [];
    const colores = new Map();
    // Estilo de presentación para un material (uno por color distinto).
    const estiloDe = (material) => {
        const rgb = colorSRGB(material);
        const clave = rgb.map((x) => x.toFixed(4)).join(',');
        if (!colores.has(clave)) {
            const col = e(`COLOUR_RGB('',${real(rgb[0], 4)},${real(rgb[1], 4)},${real(rgb[2], 4)})`);
            const relleno = e(`FILL_AREA_STYLE('',(${e(`FILL_AREA_STYLE_COLOUR('',${col})`)}))`);
            const lado = e(`SURFACE_SIDE_STYLE('',(${e(`SURFACE_STYLE_FILL_AREA(${relleno})`)}))`);
            colores.set(clave, e(`PRESENTATION_STYLE_ASSIGNMENT((${e(`SURFACE_STYLE_USAGE(.BOTH.,${lado})`)}))`));
        }
        return colores.get(clave);
    };

    // Las piezas de un mismo cuerpo (una malla con varios materiales) van juntas.
    const cuerposDelModelo = new Map();
    for (const p of piezas) {
        if (!cuerposDelModelo.has(p.cuerpo)) cuerposDelModelo.set(p.cuerpo, []);
        cuerposDelModelo.get(p.cuerpo).push(p);
    }

    for (const lista of cuerposDelModelo.values()) {
        const pieza = { nombre: lista.length > 1 ? lista[0].nombreCuerpo : lista[0].nombre };
        const malla = simplificarParaCAD(soldar(lista));
        if (!malla.tris.length) continue;
        const P = malla.puntos;
        const punto = new Map();
        const puntoDe = (i) => {
            if (!punto.has(i)) punto.set(i, e(`CARTESIAN_POINT('',(${real(P[i * 3], 4)},${real(P[i * 3 + 1], 4)},${real(P[i * 3 + 2], 4)}))`));
            return punto.get(i);
        };

        const componentes = topologia(malla);
        const cuerpos = [];
        componentes.forEach((comp, ci) => {
            const materialDeCara = [];
            // Vértices y aristas son de cada cuerpo: dos piezas que se tocan no
            // comparten topología, solo los puntos.
            const vertice = new Map(), arista = new Map();
            const verticeDe = (i) => {
                if (!vertice.has(i)) vertice.set(i, e(`VERTEX_POINT('',${puntoDe(i)})`));
                return vertice.get(i);
            };
            const aristaDe = (l) => {
                const k = comp.claveArista(l);
                if (!arista.has(k)) {
                    const i = comp.desde(l), j = comp.hasta(l);
                    const dx = P[j * 3] - P[i * 3], dy = P[j * 3 + 1] - P[i * 3 + 1], dz = P[j * 3 + 2] - P[i * 3 + 2];
                    const largo = Math.hypot(dx, dy, dz);
                    const dir = e(`DIRECTION('',(${real(dx / largo, 10)},${real(dy / largo, 10)},${real(dz / largo, 10)}))`);
                    const linea = e(`LINE('',${puntoDe(i)},${e(`VECTOR('',${dir},${real(largo, 4)})`)})`);
                    arista.set(k, { ent: e(`EDGE_CURVE('',${verticeDe(i)},${verticeDe(j)},${linea},.T.)`), desde: i, dir: [dx / largo, dy / largo, dz / largo] });
                }
                return arista.get(k);
            };

            const caras = comp.caras.map((cara) => {
                const limites = cara.bucles.map((bucle, bi) => {
                    const orientadas = bucle.map((l) => {
                        const ar = aristaDe(l);
                        return e(`ORIENTED_EDGE('',*,*,${ar.ent},${ar.desde === comp.desde(l) ? '.T.' : '.F.'})`);
                    });
                    const lazo = e(`EDGE_LOOP('',(${orientadas.join(',')}))`);
                    return e(`${bi === cara.exterior ? 'FACE_OUTER_BOUND' : 'FACE_BOUND'}('',${lazo},.T.)`);
                });
                // Plano: normal de la cara y, como referencia, la primera arista
                // proyectada sobre el plano (tiene que ser perpendicular a la normal).
                const n = cara.normal;
                const l0 = cara.bucles[cara.exterior][0];
                const d = aristaDe(l0).dir;
                const pr = d[0] * n[0] + d[1] * n[1] + d[2] * n[2];
                let rx = d[0] - pr * n[0], ry = d[1] - pr * n[1], rz = d[2] - pr * n[2];
                const rl = Math.hypot(rx, ry, rz) || 1;
                rx /= rl; ry /= rl; rz /= rl;
                const eje = e(`AXIS2_PLACEMENT_3D('',${puntoDe(comp.desde(l0))},${e(`DIRECTION('',(${real(n[0], 10)},${real(n[1], 10)},${real(n[2], 10)}))`)},${e(`DIRECTION('',(${real(rx, 10)},${real(ry, 10)},${real(rz, 10)}))`)})`);
                materialDeCara.push(cara.material);
                return e(`ADVANCED_FACE('',(${limites.join(',')}),${e(`PLANE('',${eje})`)},.T.)`);
            });
            if (!caras.length) return;
            const etiqueta = componentes.length > 1 ? `${pieza.nombre} ${ci + 1}` : pieza.nombre;
            if (comp.cerrada) {
                const s = e(`MANIFOLD_SOLID_BREP(${cadenaSTEP(etiqueta)},${e(`CLOSED_SHELL('',(${caras.join(',')}))`)})`);
                solidos.push(s); cuerpos.push({ ent: s, caras, materialDeCara });
            } else {
                const s = e(`SHELL_BASED_SURFACE_MODEL(${cadenaSTEP(etiqueta)},(${e(`OPEN_SHELL('',(${caras.join(',')}))`)}))`);
                superficies.push(s); cuerpos.push({ ent: s, caras, materialDeCara });
            }
        });

        // Color: el cuerpo lleva el del material que más caras tiene, y las
        // caras de otro material (los cantos quemados de un corte láser, por
        // ejemplo) llevan el suyo.
        for (const c of cuerpos) {
            const cuenta = new Map();
            for (const m of c.materialDeCara) cuenta.set(m, (cuenta.get(m) || 0) + 1);
            const principal = [...cuenta.entries()].sort((a, b) => b[1] - a[1])[0][0];
            estilos.push(e(`STYLED_ITEM('color',(${estiloDe(lista[principal].material)}),${c.ent})`));
            c.caras.forEach((cara, i) => {
                const m = c.materialDeCara[i];
                if (m !== principal && colorSRGB(lista[m].material).join() !== colorSRGB(lista[principal].material).join()) {
                    estilos.push(e(`STYLED_ITEM('color',(${estiloDe(lista[m].material)}),${cara})`));
                }
            });
        }
    }

    if (!solidos.length && !superficies.length) throw new Error('El modelo no tiene geometría que exportar.');

    const forma = e(`SHAPE_REPRESENTATION(${cadenaSTEP(nombre)},(${origen}),${ctx})`);
    e(`SHAPE_DEFINITION_REPRESENTATION(${pds},${forma})`);
    if (solidos.length) {
        const r = e(`ADVANCED_BREP_SHAPE_REPRESENTATION(${cadenaSTEP(nombre)},(${[origen, ...solidos].join(',')}),${ctx})`);
        e(`SHAPE_REPRESENTATION_RELATIONSHIP('','',${r},${forma})`);
    }
    if (superficies.length) {
        const r = e(`MANIFOLD_SURFACE_SHAPE_REPRESENTATION(${cadenaSTEP(nombre)},(${[origen, ...superficies].join(',')}),${ctx})`);
        e(`SHAPE_REPRESENTATION_RELATIONSHIP('','',${r},${forma})`);
    }
    if (estilos.length) e(`MECHANICAL_DESIGN_GEOMETRIC_PRESENTATION_REPRESENTATION('',(${estilos.join(',')}),${ctx})`);

    const fecha = new Date().toISOString().slice(0, 19);
    return [
        'ISO-10303-21;',
        'HEADER;',
        `FILE_DESCRIPTION((${cadenaSTEP(`${nombre} - Galeria 3D fabOS (geometria facetada, mm)`)}),'2;1');`,
        `FILE_NAME(${cadenaSTEP(archivo)},'${fecha}',(${cadenaSTEP(autor)}),(${cadenaSTEP(autor)}),'fabOS Galeria 3D','fabOS','');`,
        "FILE_SCHEMA(('AUTOMOTIVE_DESIGN { 1 0 10303 214 1 1 1 1 }'));",
        'ENDSEC;',
        'DATA;',
        ...L,
        'ENDSEC;',
        'END-ISO-10303-21;',
        '',
    ].join('\n');
}

/* ================================================================== */
/* FBX (binario, versión 7.4)                                           */
/* ================================================================== */

/** Escritor de bytes que crece solo. */
class Bytes {
    constructor() { this.buf = new Uint8Array(1 << 20); this.dv = new DataView(this.buf.buffer); this.n = 0; }
    espacio(k) {
        if (this.n + k <= this.buf.length) return;
        let largo = this.buf.length * 2;
        while (largo < this.n + k) largo *= 2;
        const b = new Uint8Array(largo); b.set(this.buf.subarray(0, this.n));
        this.buf = b; this.dv = new DataView(b.buffer);
    }
    u8(v) { this.espacio(1); this.dv.setUint8(this.n, v); this.n += 1; }
    u32(v) { this.espacio(4); this.dv.setUint32(this.n, v, true); this.n += 4; }
    i32(v) { this.espacio(4); this.dv.setInt32(this.n, v, true); this.n += 4; }
    i64(v) { this.espacio(8); this.dv.setBigInt64(this.n, BigInt(v), true); this.n += 8; }
    f64(v) { this.espacio(8); this.dv.setFloat64(this.n, v, true); this.n += 8; }
    bytes(b) { this.espacio(b.length); this.buf.set(b, this.n); this.n += b.length; }
    final() { return this.buf.slice(0, this.n).buffer; }
}

const utf8 = new TextEncoder();

// Propiedades de un nodo FBX, cada una con su tipo.
const I = (v) => ({ t: 'I', v });
const D = (v) => ({ t: 'D', v });
const Lg = (v) => ({ t: 'L', v });
const S = (v) => ({ t: 'S', v });
const C = (v) => ({ t: 'C', v });
const R = (v) => ({ t: 'R', v });
const Ad = (v) => ({ t: 'd', v });
const Ai = (v) => ({ t: 'i', v });

/** Un nodo: nombre, propiedades e hijos. */
const nodo = (nombre, props = [], hijos = []) => ({ nombre, props, hijos });
/** Una propiedad dentro de Properties70. */
const P = (...valores) => nodo('P', valores);
/** «Nombre\x00\x01Clase», como el FBX binario guarda los nombres de objeto. */
const nombreClase = (nombre, clase) => S(`${nombre}\x00\x01${clase}`);

function escribirPropiedad(w, p) {
    w.u8(p.t.charCodeAt(0));
    switch (p.t) {
        case 'I': w.i32(p.v); break;
        case 'D': w.f64(p.v); break;
        case 'L': w.i64(p.v); break;
        case 'C': w.u8(p.v ? 1 : 0); break;
        case 'S': { const b = utf8.encode(p.v); w.u32(b.length); w.bytes(b); break; }
        case 'R': w.u32(p.v.length); w.bytes(p.v); break;
        case 'd': w.u32(p.v.length); w.u32(0); w.u32(p.v.length * 8); for (const x of p.v) w.f64(x); break;
        case 'i': w.u32(p.v.length); w.u32(0); w.u32(p.v.length * 4); for (const x of p.v) w.i32(x); break;
    }
}

function escribirNodo(w, n) {
    const inicio = w.n;
    w.u32(0); w.u32(n.props.length); w.u32(0);
    const nombre = utf8.encode(n.nombre);
    w.u8(nombre.length); w.bytes(nombre);
    const inicioProps = w.n;
    for (const p of n.props) escribirPropiedad(w, p);
    const largoProps = w.n - inicioProps;
    // Como el SDK de Autodesk: registro nulo al final si tiene hijos o si
    // está vacío del todo.
    if (n.hijos.length || !n.props.length) {
        for (const h of n.hijos) escribirNodo(w, h);
        w.bytes(new Uint8Array(13));
    }
    w.dv.setUint32(inicio, w.n, true);
    w.dv.setUint32(inicio + 8, largoProps, true);
}

// Valores fijos que usan Blender y el SDK de Autodesk para que el archivo
// sea reconocido como válido (la fecha va emparejada con el identificador).
const FBX_FILE_ID = new Uint8Array([0x28, 0xb3, 0x2a, 0xeb, 0xb6, 0x24, 0xcc, 0xc2, 0xbf, 0xc8, 0xb0, 0x2a, 0xa9, 0x2b, 0xfc, 0xf1]);
const FBX_FOOT_ID = new Uint8Array([0xfa, 0xbc, 0xab, 0x09, 0xd0, 0xc8, 0xd4, 0x66, 0xb1, 0x76, 0xfb, 0x83, 0x1c, 0xf7, 0x26, 0x7e]);
const FBX_FIN = new Uint8Array([0xf8, 0x5a, 0x8c, 0x6a, 0xde, 0xf5, 0xd9, 0x7e, 0xec, 0xe9, 0x0c, 0xe3, 0x75, 0x8f, 0x29, 0x0b]);
const FBX_FECHA = '1970-01-01 10:00:00:000';

/** La textura de color como JPEG (bytes), o null si no hay. */
async function jpegDeTextura(textura) {
    const img = textura?.image;
    if (!img || !img.width) return null;
    const lado = (x) => Math.min(x, 2048);
    const canvas = document.createElement('canvas');
    canvas.width = lado(img.width); canvas.height = lado(img.height);
    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.9));
    return blob ? new Uint8Array(await blob.arrayBuffer()) : null;
}

export async function exportarFBX(raiz, { nombre = 'modelo' } = {}) {
    const piezas = recogerPiezas(raiz);
    let siguienteId = 1000000;
    const nuevoId = () => siguienteId++;

    const objetos = [];
    const conexiones = [];
    const materiales = new Map();
    let texturas = 0;
    let n = 0;

    for (const pieza of piezas) {
        // Puntos de control únicos (en cm) y, por esquina, normal y UV.
        const nv = pieza.P.length / 3;
        const mapa = new Map();
        const control = new Int32Array(nv);
        const vertices = [];
        for (let i = 0; i < nv; i++) {
            const x = pieza.P[i * 3] * 100, y = pieza.P[i * 3 + 1] * 100, z = pieza.P[i * 3 + 2] * 100;
            const k = `${Math.round(x * 1e4)},${Math.round(y * 1e4)},${Math.round(z * 1e4)}`;
            let c = mapa.get(k);
            if (c === undefined) { c = vertices.length / 3; mapa.set(k, c); vertices.push(x, y, z); }
            control[i] = c;
        }
        const indices = new Int32Array(pieza.tris.length);
        const normales = pieza.N ? new Float64Array(pieza.tris.length * 3) : null;
        const uvIndice = pieza.U ? new Int32Array(pieza.tris.length) : null;
        for (let t = 0; t < pieza.tris.length; t++) {
            const v = pieza.tris[t];
            // El último índice de cada polígono va negado (~i), así lo marca FBX.
            indices[t] = t % 3 === 2 ? ~control[v] : control[v];
            if (normales) { normales[t * 3] = pieza.N[v * 3]; normales[t * 3 + 1] = pieza.N[v * 3 + 1]; normales[t * 3 + 2] = pieza.N[v * 3 + 2]; }
            if (uvIndice) uvIndice[t] = v;
        }
        let uvs = null;
        if (pieza.U) {
            // glTF tiene el origen de la textura arriba; FBX, abajo.
            uvs = new Float64Array(nv * 2);
            for (let i = 0; i < nv; i++) { uvs[i * 2] = pieza.U[i * 2]; uvs[i * 2 + 1] = 1 - pieza.U[i * 2 + 1]; }
        }

        n++;
        const idGeo = nuevoId(), idModelo = nuevoId();
        const capas = [];
        const elementos = [];
        if (normales) {
            capas.push(nodo('LayerElementNormal', [I(0)], [
                nodo('Version', [I(101)]), nodo('Name', [S('')]),
                nodo('MappingInformationType', [S('ByPolygonVertex')]),
                nodo('ReferenceInformationType', [S('Direct')]),
                nodo('Normals', [Ad(normales)]),
            ]));
            elementos.push(nodo('LayerElement', [], [nodo('Type', [S('LayerElementNormal')]), nodo('TypedIndex', [I(0)])]));
        }
        if (uvs) {
            capas.push(nodo('LayerElementUV', [I(0)], [
                nodo('Version', [I(101)]), nodo('Name', [S('UVMap')]),
                nodo('MappingInformationType', [S('ByPolygonVertex')]),
                nodo('ReferenceInformationType', [S('IndexToDirect')]),
                nodo('UV', [Ad(uvs)]), nodo('UVIndex', [Ai(uvIndice)]),
            ]));
            elementos.push(nodo('LayerElement', [], [nodo('Type', [S('LayerElementUV')]), nodo('TypedIndex', [I(0)])]));
        }
        capas.push(nodo('LayerElementMaterial', [I(0)], [
            nodo('Version', [I(101)]), nodo('Name', [S('')]),
            nodo('MappingInformationType', [S('AllSame')]),
            nodo('ReferenceInformationType', [S('IndexToDirect')]),
            nodo('Materials', [Ai(new Int32Array([0]))]),
        ]));
        elementos.push(nodo('LayerElement', [], [nodo('Type', [S('LayerElementMaterial')]), nodo('TypedIndex', [I(0)])]));

        objetos.push(nodo('Geometry', [Lg(idGeo), nombreClase(pieza.nombre, 'Geometry'), S('Mesh')], [
            nodo('Properties70'),
            nodo('GeometryVersion', [I(124)]),
            nodo('Vertices', [Ad(Float64Array.from(vertices))]),
            nodo('PolygonVertexIndex', [Ai(indices)]),
            ...capas,
            nodo('Layer', [I(0)], [nodo('Version', [I(100)]), ...elementos]),
        ]));
        objetos.push(nodo('Model', [Lg(idModelo), nombreClase(pieza.nombre, 'Model'), S('Mesh')], [
            nodo('Version', [I(232)]),
            nodo('Properties70', [], [
                P(S('Lcl Translation'), S('Lcl Translation'), S(''), S('A'), D(0), D(0), D(0)),
                P(S('Lcl Rotation'), S('Lcl Rotation'), S(''), S('A'), D(0), D(0), D(0)),
                P(S('Lcl Scaling'), S('Lcl Scaling'), S(''), S('A'), D(1), D(1), D(1)),
                P(S('DefaultAttributeIndex'), S('int'), S('Integer'), S(''), I(0)),
            ]),
            nodo('Shading', [C(true)]),
            nodo('Culling', [S('CullingOff')]),
        ]));
        conexiones.push(['OO', idModelo, 0], ['OO', idGeo, idModelo]);

        // Material (compartido entre piezas que usan el mismo).
        const mat = pieza.material;
        if (!materiales.has(mat)) {
            const idMat = nuevoId();
            const c = mat?.color ?? new THREE.Color(0.8, 0.8, 0.8);
            const em = mat?.emissive ?? new THREE.Color(0, 0, 0);
            const fuerza = mat?.emissiveIntensity ?? 1;
            const opacidad = mat?.transparent || mat?.transmission ? Math.min(mat.opacity ?? 1, mat.transmission ? 0.5 : 1) : 1;
            const rugosidad = mat?.roughness ?? 0.6;
            const brillo = (10 * (1 - rugosidad)) ** 2;
            const nombreMat = mat?.name || `Material ${materiales.size + 1}`;
            objetos.push(nodo('Material', [Lg(idMat), nombreClase(nombreMat, 'Material'), S('')], [
                nodo('Version', [I(102)]),
                nodo('ShadingModel', [S('Phong')]),
                nodo('MultiLayer', [I(0)]),
                nodo('Properties70', [], [
                    P(S('DiffuseColor'), S('Color'), S(''), S('A'), D(c.r), D(c.g), D(c.b)),
                    P(S('DiffuseFactor'), S('Number'), S(''), S('A'), D(1)),
                    P(S('EmissiveColor'), S('Color'), S(''), S('A'), D(em.r), D(em.g), D(em.b)),
                    P(S('EmissiveFactor'), S('Number'), S(''), S('A'), D(em.r + em.g + em.b > 0 ? fuerza : 0)),
                    P(S('SpecularColor'), S('Color'), S(''), S('A'), D(0.5), D(0.5), D(0.5)),
                    P(S('SpecularFactor'), S('Number'), S(''), S('A'), D(mat?.metalness ? 1 : 0.5)),
                    P(S('ShininessExponent'), S('Number'), S(''), S('A'), D(brillo)),
                    P(S('TransparencyFactor'), S('Number'), S(''), S('A'), D(1 - opacidad)),
                    P(S('Opacity'), S('Number'), S(''), S('A'), D(opacidad)),
                ]),
            ]));
            materiales.set(mat, idMat);

            const jpeg = await jpegDeTextura(mat?.map).catch(() => null);
            if (jpeg) {
                texturas++;
                const idTex = nuevoId(), idVideo = nuevoId();
                const archivo = `${ascii(nombreMat).replace(/[^\w-]+/g, '_') || 'textura'}_${texturas}.jpg`;
                objetos.push(nodo('Video', [Lg(idVideo), nombreClase(archivo, 'Video'), S('Clip')], [
                    nodo('Type', [S('Clip')]),
                    nodo('Properties70', [], [P(S('Path'), S('KString'), S('XRefUrl'), S(''), S(archivo))]),
                    nodo('UseMipMap', [I(0)]),
                    nodo('Filename', [S(archivo)]),
                    nodo('RelativeFilename', [S(archivo)]),
                    nodo('Content', [R(jpeg)]),
                ]));
                objetos.push(nodo('Texture', [Lg(idTex), nombreClase(archivo, 'Texture'), S('')], [
                    nodo('Type', [S('TextureVideoClip')]),
                    nodo('Version', [I(202)]),
                    nodo('TextureName', [nombreClase(archivo, 'Texture')]),
                    nodo('Properties70', [], [
                        P(S('UVSet'), S('KString'), S(''), S(''), S('UVMap')),
                        P(S('UseMaterial'), S('bool'), S(''), S(''), I(1)),
                    ]),
                    nodo('Media', [nombreClase(archivo, 'Video')]),
                    nodo('FileName', [S(archivo)]),
                    nodo('RelativeFilename', [S(archivo)]),
                    nodo('ModelUVTranslation', [D(0), D(0)]),
                    nodo('ModelUVScaling', [D(1), D(1)]),
                    nodo('Texture_Alpha_Source', [S('None')]),
                    nodo('Cropping', [I(0), I(0), I(0), I(0)]),
                ]));
                conexiones.push(['OO', idVideo, idTex], ['OP', idTex, idMat, 'DiffuseColor']);
            }
        }
        conexiones.push(['OO', materiales.get(mat), idModelo]);
    }

    if (!n) throw new Error('El modelo no tiene geometría que exportar.');

    const ahora = new Date();
    const creador = 'fabOS Galeria 3D (FabLab EAN)';
    const definiciones = [['GlobalSettings', 1], ['Model', n], ['Geometry', n], ['Material', materiales.size], ['Texture', texturas], ['Video', texturas]]
        .filter(([, k]) => k > 0);

    const raizFBX = [
        nodo('FBXHeaderExtension', [], [
            nodo('FBXHeaderVersion', [I(1003)]),
            nodo('FBXVersion', [I(7400)]),
            nodo('EncryptionType', [I(0)]),
            nodo('CreationTimeStamp', [], [
                nodo('Version', [I(1000)]),
                nodo('Year', [I(ahora.getFullYear())]), nodo('Month', [I(ahora.getMonth() + 1)]), nodo('Day', [I(ahora.getDate())]),
                nodo('Hour', [I(ahora.getHours())]), nodo('Minute', [I(ahora.getMinutes())]), nodo('Second', [I(ahora.getSeconds())]),
                nodo('Millisecond', [I(0)]),
            ]),
            nodo('Creator', [S(creador)]),
            nodo('SceneInfo', [nombreClase('GlobalInfo', 'SceneInfo'), S('UserData')], [
                nodo('Type', [S('UserData')]),
                nodo('Version', [I(100)]),
                nodo('MetaData', [], [
                    nodo('Version', [I(100)]), nodo('Title', [S(nombre)]), nodo('Subject', [S('')]), nodo('Author', [S('FabLab EAN')]),
                    nodo('Keywords', [S('')]), nodo('Revision', [S('')]), nodo('Comment', [S('')]),
                ]),
            ]),
        ]),
        nodo('FileId', [R(FBX_FILE_ID)]),
        nodo('CreationTime', [S(FBX_FECHA)]),
        nodo('Creator', [S(creador)]),
        nodo('GlobalSettings', [], [
            nodo('Version', [I(1000)]),
            nodo('Properties70', [], [
                P(S('UpAxis'), S('int'), S('Integer'), S(''), I(1)),
                P(S('UpAxisSign'), S('int'), S('Integer'), S(''), I(1)),
                P(S('FrontAxis'), S('int'), S('Integer'), S(''), I(2)),
                P(S('FrontAxisSign'), S('int'), S('Integer'), S(''), I(1)),
                P(S('CoordAxis'), S('int'), S('Integer'), S(''), I(0)),
                P(S('CoordAxisSign'), S('int'), S('Integer'), S(''), I(1)),
                P(S('OriginalUpAxis'), S('int'), S('Integer'), S(''), I(1)),
                P(S('OriginalUpAxisSign'), S('int'), S('Integer'), S(''), I(1)),
                P(S('UnitScaleFactor'), S('double'), S('Number'), S(''), D(1)),
                P(S('OriginalUnitScaleFactor'), S('double'), S('Number'), S(''), D(1)),
                P(S('AmbientColor'), S('ColorRGB'), S('Color'), S(''), D(0), D(0), D(0)),
                P(S('DefaultCamera'), S('KString'), S(''), S(''), S('Producer Perspective')),
                P(S('TimeMode'), S('enum'), S(''), S(''), I(11)),
                P(S('TimeSpanStart'), S('KTime'), S('Time'), S(''), Lg(0)),
                P(S('TimeSpanStop'), S('KTime'), S('Time'), S(''), Lg(46186158000)),
                P(S('CustomFrameRate'), S('double'), S('Number'), S(''), D(24)),
            ]),
        ]),
        nodo('Documents', [], [
            nodo('Count', [I(1)]),
            nodo('Document', [Lg(nuevoId()), S('Scene'), S('Scene')], [
                nodo('Properties70', [], [
                    P(S('SourceObject'), S('object'), S(''), S('')),
                    P(S('ActiveAnimStackName'), S('KString'), S(''), S(''), S('')),
                ]),
                nodo('RootNode', [Lg(0)]),
            ]),
        ]),
        nodo('References'),
        nodo('Definitions', [], [
            nodo('Version', [I(100)]),
            nodo('Count', [I(definiciones.reduce((s, [, k]) => s + k, 0))]),
            ...definiciones.map(([tipo, k]) => nodo('ObjectType', [S(tipo)], [nodo('Count', [I(k)])])),
        ]),
        nodo('Objects', [], objetos),
        nodo('Connections', [], conexiones.map(([tipo, hijo, padre, prop]) =>
            nodo('C', [S(tipo), Lg(hijo), Lg(padre), ...(prop ? [S(prop)] : [])]))),
    ];

    const w = new Bytes();
    w.bytes(utf8.encode('Kaydara FBX Binary  ')); w.u8(0); w.u8(0x1a); w.u8(0);
    w.u32(7400);
    for (const nd of raizFBX) escribirNodo(w, nd);
    w.bytes(new Uint8Array(13));
    // Pie del archivo: identificador, relleno hasta múltiplo de 16, versión y firma.
    w.bytes(FBX_FOOT_ID);
    w.bytes(new Uint8Array(4));
    let relleno = ((w.n + 15) & ~15) - w.n;
    if (relleno === 0) relleno = 16;
    w.bytes(new Uint8Array(relleno));
    w.u32(7400);
    w.bytes(new Uint8Array(120));
    w.bytes(FBX_FIN);
    return w.final();
}
