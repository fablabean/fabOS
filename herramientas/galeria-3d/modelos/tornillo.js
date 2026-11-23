/*
 * Tornillo y tuerca M20 impresos en 3D, con la rosca de verdad (paso de
 * 2,5 mm, perfil trapezoidal como el métrico). La tuerca sube y baja por el
 * tornillo girando lo justo para que los filetes coincidan.
 *
 * Cada pieza es una sola malla cerrada (sin caras sueltas ni solapadas), así
 * que el STL que se descarga de la galería se puede laminar e imprimir tal cual.
 */
import * as THREE from 'three';
import { hexagono } from '../piezas.js';
import { capasImpresion, textura } from '../texturas.js';

const PASO = 0.0025;          // 2,5 mm por vuelta
const R_MAYOR = 0.01;         // M20
const PROFUNDIDAD = 0.00135;  // altura del filete
const HOLGURA = 0.00025;      // entre tornillo y tuerca, para que gire impreso
const SEGMENTOS = 96;         // puntos por vuelta

/** Perfil del filete: 0 en el fondo, 1 en la cresta (trapecio con cresta y fondo planos). */
function perfil(f) {
    f = ((f % 1) + 1) % 1;
    if (f < 0.125) return 1;
    if (f < 0.5) return 1 - (f - 0.125) / 0.375;
    if (f < 0.625) return 0;
    return (f - 0.625) / 0.375;
}

/** Radio del tornillo en el ángulo θ y la altura y (rosca a derechas). */
const radioRosca = (theta, y) => R_MAYOR - PROFUNDIDAD + PROFUNDIDAD * perfil(y / PASO - theta / (Math.PI * 2));

/**
 * Superficie de revolución con radio r(θ, y), de y0 a y1. Devuelve la malla
 * y los índices del primer y del último anillo para cerrarla con tapas.
 */
function superficie(r, y0, y1, filas, { haciaDentro = false } = {}) {
    const pos = [], idx = [];
    for (let i = 0; i <= filas; i++) {
        const y = y0 + ((y1 - y0) * i) / filas;
        for (let j = 0; j < SEGMENTOS; j++) {
            const th = (j / SEGMENTOS) * Math.PI * 2;
            const rr = r(th, y);
            pos.push(Math.cos(th) * rr, y, -Math.sin(th) * rr);
        }
    }
    for (let i = 0; i < filas; i++) {
        for (let j = 0; j < SEGMENTOS; j++) {
            const a = i * SEGMENTOS + j, b = i * SEGMENTOS + ((j + 1) % SEGMENTOS);
            const c = a + SEGMENTOS, d = b + SEGMENTOS;
            // (a → b avanza en θ; a → c sube). Hacia fuera: a, b, c.
            if (haciaDentro) idx.push(a, c, b, b, c, d);
            else idx.push(a, b, c, b, d, c);
        }
    }
    const anillo = (i) => Array.from({ length: SEGMENTOS }, (_, j) => i * SEGMENTOS + j);
    return { pos, idx, abajo: anillo(0), arriba: anillo(filas) };
}

/** Une varias listas de posiciones/índices en una BufferGeometry indexada. */
function geometria(partes) {
    const pos = [], idx = [];
    for (const p of partes) {
        const base = pos.length / 3;
        pos.push(...p.pos);
        for (const i of p.idx) idx.push(i + base);
    }
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.setIndex(idx);
    return g;
}

/**
 * Cara plana horizontal entre un polígono exterior (hexágono) y el anillo de
 * la rosca (los mismos puntos que la superficie, para que la malla quede
 * cerrada). Con `hueco` = null es una tapa llena.
 */
function caraPlana(exterior, hueco, y, haciaArriba) {
    const contorno = exterior.map((p) => new THREE.Vector2(p.x, p.y));
    if (THREE.ShapeUtils.isClockWise(contorno)) contorno.reverse();
    const huecos = hueco ? [hueco.map((p) => new THREE.Vector2(p.x, p.y))] : [];
    if (huecos[0] && !THREE.ShapeUtils.isClockWise(huecos[0])) huecos[0].reverse();
    const tris = THREE.ShapeUtils.triangulateShape(contorno, huecos);
    const todos = [...contorno, ...(huecos[0] || [])];
    const pos = [], idx = [];
    // En la forma, Y es -Z del mundo (para que el sentido de giro coincida).
    for (const p of todos) pos.push(p.x, y, -p.y);
    for (const [a, b, c] of tris) {
        if (haciaArriba) idx.push(a, b, c);
        else idx.push(a, c, b);
    }
    return { pos, idx };
}

/** Las paredes de un prisma de base `poligono`, de y0 a y1, hacia fuera. */
function paredes(poligono, y0, y1) {
    const pos = [], idx = [];
    const n = poligono.length;
    for (let k = 0; k < n; k++) {
        const a = poligono[k], b = poligono[(k + 1) % n];
        const base = pos.length / 3;
        pos.push(a.x, y0, -a.y, b.x, y0, -b.y, a.x, y1, -a.y, b.x, y1, -b.y);
        idx.push(base, base + 1, base + 2, base + 1, base + 3, base + 2);
    }
    return { pos, idx };
}

/** Los puntos (en el plano, Y = −Z del mundo) de un anillo de la superficie. */
const anilloPlano = (sup, indices) => indices.map((i) => new THREE.Vector2(sup.pos[i * 3], -sup.pos[i * 3 + 2]));

/** Hexágono «entre caras» `entreCaras`, girado para que una cara mire al frente. */
function hexagonoEntreCaras(entreCaras) {
    const r = entreCaras / 2 / Math.cos(Math.PI / 6);
    return hexagono(r).map((p) => p.clone().rotateAround(new THREE.Vector2(), Math.PI / 6));
}

/** UV para el mapa de capas: V sube con la altura, U da la vuelta. */
function uvCapas(g, { periodo = 0.0064 } = {}) {
    const p = g.attributes.position;
    const uv = new Float32Array(p.count * 2);
    for (let i = 0; i < p.count; i++) {
        const x = p.getX(i), y = p.getY(i), z = p.getZ(i);
        uv[i * 2] = (Math.atan2(-z, x) / (Math.PI * 2) + 0.5) * 8;
        uv[i * 2 + 1] = y / periodo;
    }
    g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    return g;
}

export default {
    slug: 'tornillo',
    texturaMax: 256,
    // La rosca tiene muchos triángulos y ninguno sobra del todo: se simplifica poco.
    simplificacion: { umbral: 30000, proporcion: 0.45, error: 0.0004 },

    async crear() {
        const capas = textura(capasImpresion({ tam: 256, capas: 32 }), { color: false, nombre: 'capas' });
        const mTornillo = new THREE.MeshStandardMaterial({
            name: 'PETG gris', color: '#8d949c', roughness: 0.42, metalness: 0.05,
            normalMap: capas, normalScale: new THREE.Vector2(0.6, 0.6),
        });
        const mTuerca = new THREE.MeshStandardMaterial({
            name: 'PLA naranja', color: '#f08a24', roughness: 0.55, metalness: 0,
            normalMap: capas, normalScale: new THREE.Vector2(0.6, 0.6),
        });

        const raiz = new THREE.Group();
        raiz.name = 'Tornillo y tuerca M20';

        // --- Tornillo: cabeza hexagonal abajo (así se imprime) y vástago roscado ---
        const altoCabeza = 0.0125, largo = 0.06;
        const hexCabeza = hexagonoEntreCaras(0.03);
        const finVastago = altoCabeza + largo;
        // punta achaflanada: el radio no pasa de un cono de 45°
        const rTornillo = (th, y) => Math.min(radioRosca(th, y), R_MAYOR - PROFUNDIDAD - 0.0004 + (finVastago - y));
        const vastago = superficie(rTornillo, altoCabeza, finVastago, Math.round((largo / PASO) * 14));
        const cabezaAbajo = caraPlana(hexCabeza, null, 0, false);
        const cabezaLados = paredes(hexCabeza, 0, altoCabeza);
        const cabezaArriba = caraPlana(hexCabeza, anilloPlano(vastago, vastago.abajo), altoCabeza, true);
        // tapa de la punta: abanico al centro con los puntos del último anillo
        const punta = (() => {
            const pos = [...vastago.arriba.flatMap((i) => [vastago.pos[i * 3], finVastago, vastago.pos[i * 3 + 2]]), 0, finVastago, 0];
            const idx = [];
            for (let j = 0; j < SEGMENTOS; j++) idx.push(SEGMENTOS, j, (j + 1) % SEGMENTOS);
            return { pos, idx };
        })();
        const geoTornillo = uvCapas(geometria([vastago, cabezaAbajo, cabezaLados, cabezaArriba, punta]));
        geoTornillo.computeVertexNormals();
        const tornillo = new THREE.Mesh(geoTornillo, mTornillo);
        tornillo.name = 'Tornillo';
        raiz.add(tornillo);

        // --- Tuerca: hexágono con la rosca por dentro ---
        const altoTuerca = 0.016, yTuerca = 0.042;
        const hexTuerca = hexagonoEntreCaras(0.03);
        // Su rosca es la del tornillo, en coordenadas de la tuerca, más la holgura.
        const rTuerca = (th, y) => radioRosca(th, y + yTuerca) + HOLGURA;
        const rosca = superficie(rTuerca, 0, altoTuerca, Math.round((altoTuerca / PASO) * 14), { haciaDentro: true });
        const tuercaAbajo = caraPlana(hexTuerca, anilloPlano(rosca, rosca.abajo), 0, false);
        const tuercaArriba = caraPlana(hexTuerca, anilloPlano(rosca, rosca.arriba), altoTuerca, true);
        const tuercaLados = paredes(hexTuerca, 0, altoTuerca);
        const geoTuerca = uvCapas(geometria([rosca, tuercaAbajo, tuercaArriba, tuercaLados]));
        geoTuerca.computeVertexNormals();
        const tuerca = new THREE.Mesh(geoTuerca, mTuerca);
        tuerca.name = 'Tuerca';
        tuerca.position.y = yTuerca;
        raiz.add(tuerca);

        // --- Animación: la tuerca baja 6 vueltas y vuelve a subir ---
        // Bajar Δy exige girar −2π·Δy/paso para que los filetes sigan encajando.
        const T = 8, vueltas = 6, claves = 97;
        const tiempos = [], alturas = [], giros = [];
        const q = new THREE.Quaternion(), eje = new THREE.Vector3(0, 1, 0);
        for (let i = 0; i < claves; i++) {
            const t = i / (claves - 1);
            const s = 0.5 - 0.5 * Math.cos(t * Math.PI * 2);           // 0 → 1 → 0, suave
            const dy = -s * vueltas * PASO;
            tiempos.push(t * T);
            alturas.push(0, yTuerca + dy, 0);
            q.setFromAxisAngle(eje, (2 * Math.PI * dy) / PASO);
            giros.push(q.x, q.y, q.z, q.w);
        }
        const clip = new THREE.AnimationClip('Enroscar la tuerca', T, [
            new THREE.VectorKeyframeTrack('Tuerca.position', tiempos, alturas),
            new THREE.QuaternionKeyframeTrack('Tuerca.quaternion', tiempos, giros),
        ]);

        return { escena: raiz, animaciones: [clip] };
    },
};

