/*
 * Piezas y utilidades de geometría compartidas por los modelos.
 * Todas las medidas en metros, con Y hacia arriba (lo que pide glTF y lo que
 * usan la realidad aumentada de Android y la Vista rápida de iOS).
 */
import * as THREE from 'three';

/* ------------------------------------------------------------------ */
/* Engranajes                                                           */
/* ------------------------------------------------------------------ */

/**
 * Contorno de un engranaje de N dientes con perfil aproximado de evolvente.
 * Sirve para engranajes exteriores (rPunta > rRaiz) e interiores (rPunta < rRaiz).
 * Cada diente k está centrado en el ángulo 2πk/N.
 */
export function contornoDientes(N, rPunta, rRaiz, { anchoPunta = 0.55, anchoRaiz = 1.35, pasos = 5 } = {}) {
    const puntos = [];
    const medio = Math.PI / (2 * N);                   // medio espesor del diente en el círculo primitivo
    const semiPunta = medio * anchoPunta, semiRaiz = Math.min(medio * anchoRaiz, Math.PI / N * 0.98);
    for (let k = 0; k < N; k++) {
        const c = (2 * Math.PI * k) / N;
        // flanco de subida (de la raíz a la punta), curvado como una evolvente
        for (let i = 0; i <= pasos; i++) {
            const t = i / pasos;
            const r = rRaiz + (rPunta - rRaiz) * t;
            const s = semiRaiz + (semiPunta - semiRaiz) * Math.pow(t, 0.8);
            puntos.push(new THREE.Vector2(Math.cos(c - s) * r, Math.sin(c - s) * r));
        }
        // punta
        for (let i = 1; i < pasos; i++) {
            const a = c - semiPunta + (2 * semiPunta * i) / pasos;
            puntos.push(new THREE.Vector2(Math.cos(a) * rPunta, Math.sin(a) * rPunta));
        }
        // flanco de bajada
        for (let i = pasos; i >= 0; i--) {
            const t = i / pasos;
            const r = rRaiz + (rPunta - rRaiz) * t;
            const s = semiRaiz + (semiPunta - semiRaiz) * Math.pow(t, 0.8);
            puntos.push(new THREE.Vector2(Math.cos(c + s) * r, Math.sin(c + s) * r));
        }
        // fondo hasta el siguiente diente
        const siguiente = (2 * Math.PI * (k + 1)) / N;
        for (let i = 1; i < pasos; i++) {
            const a = c + semiRaiz + ((siguiente - semiRaiz) - (c + semiRaiz)) * (i / pasos);
            puntos.push(new THREE.Vector2(Math.cos(a) * rRaiz, Math.sin(a) * rRaiz));
        }
    }
    return puntos;
}

export function circulo(r, segmentos = 48, horario = false) {
    const p = [];
    for (let i = 0; i < segmentos; i++) {
        const a = (i / segmentos) * Math.PI * 2 * (horario ? -1 : 1);
        p.push(new THREE.Vector2(Math.cos(a) * r, Math.sin(a) * r));
    }
    return p;
}

export function hexagono(r) {
    return circulo(r, 6);
}

/** Extruye un contorno (plano XY) hacia arriba (+Y), con el canto biselado como sale de una impresora. */
export function extruirVertical(forma, alto, { bisel = 0.0004, segmentosCurva = 1 } = {}) {
    const g = new THREE.ExtrudeGeometry(forma, {
        depth: alto - bisel * 2,
        bevelEnabled: bisel > 0,
        bevelThickness: bisel,
        bevelSize: bisel,
        bevelSegments: 1,
        curveSegments: segmentosCurva * 12,
    });
    g.translate(0, 0, bisel);
    g.rotateX(-Math.PI / 2);
    return g;
}

/* ------------------------------------------------------------------ */
/* Coordenadas de textura                                               */
/* ------------------------------------------------------------------ */

/**
 * UV para piezas impresas en 3D. En las caras laterales, V avanza con la
 * altura —una vuelta del mosaico de capas cada `capas`×`altoCapa`—; en las
 * caras de arriba y abajo se proyecta en diagonal, que es como se ve el
 * relleno de la última capa.
 */
export function uvImpresion(geometria, { altoCapa = 0.0002, capasPorMosaico = 32 } = {}) {
    const g = geometria.index ? geometria.toNonIndexed() : geometria;
    g.computeVertexNormals();
    const pos = g.attributes.position, nor = g.attributes.normal;
    const uv = new Float32Array(pos.count * 2);
    const periodo = altoCapa * capasPorMosaico;
    for (let tri = 0; tri < pos.count; tri += 3) {
        // decide por triángulo para que no se mezclen las dos proyecciones
        let ny = 0;
        for (let k = 0; k < 3; k++) ny += Math.abs(nor.getY(tri + k));
        const horizontal = ny / 3 > 0.75;
        for (let k = 0; k < 3; k++) {
            const i = tri + k;
            const x = pos.getX(i), y = pos.getY(i), z = pos.getZ(i);
            if (horizontal) {
                uv[i * 2] = (x - z) / (periodo * 1.5);
                uv[i * 2 + 1] = (x + z) / (periodo * 1.5);
            } else {
                const nx = nor.getX(i), nz = nor.getZ(i);
                const t = Math.abs(nx) > Math.abs(nz) ? z : x;   // a lo largo de la pared
                uv[i * 2] = t / (periodo * 4);
                uv[i * 2 + 1] = y / periodo;
            }
        }
    }
    g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    return g;
}

/** UV planar normalizada (0..1) proyectando sobre dos ejes: para tableros, placas y grabados. */
export function uvPlanar(geometria, ejeU = 'x', ejeV = 'z', { invertirV = true } = {}) {
    const g = geometria;
    g.computeBoundingBox();
    const bb = g.boundingBox;
    const pos = g.attributes.position;
    const uv = new Float32Array(pos.count * 2);
    const min = bb.min, size = new THREE.Vector3().subVectors(bb.max, bb.min);
    for (let i = 0; i < pos.count; i++) {
        const p = { x: pos.getX(i), y: pos.getY(i), z: pos.getZ(i) };
        uv[i * 2] = (p[ejeU] - min[ejeU]) / (size[ejeU] || 1);
        const v = (p[ejeV] - min[ejeV]) / (size[ejeV] || 1);
        uv[i * 2 + 1] = invertirV ? 1 - v : v;
    }
    g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    return g;
}

/* ------------------------------------------------------------------ */
/* Corte láser: paneles con uniones dentadas (finger joints)             */
/* ------------------------------------------------------------------ */

/**
 * Un borde dentado de longitud L entre (x0,y0) y (x1,y1), con `n` dedos.
 * `sale` = true: el primer dedo sobresale hacia fuera de la pieza (hacia `normal`).
 */
function bordeDentado(puntos, desde, hasta, n, espesor, normal, sale) {
    const dir = new THREE.Vector2().subVectors(hasta, desde);
    const L = dir.length();
    dir.normalize();
    const paso = L / n;
    for (let i = 0; i < n; i++) {
        const fuera = (i % 2 === 0) === sale;
        const off = fuera ? 0 : -espesor;
        const a = desde.clone().addScaledVector(dir, paso * i).addScaledVector(normal, off);
        const b = desde.clone().addScaledVector(dir, paso * (i + 1)).addScaledVector(normal, off);
        puntos.push(a, b);
    }
}

/**
 * Panel rectangular ancho×alto con dedos en los cuatro bordes.
 * `lados` = [abajo, derecha, arriba, izquierda]: null (liso), true (dedos salen) o false (dedos entran).
 */
export function panelDentado(ancho, alto, espesor, dedos, lados) {
    const p = [];
    const esquinas = [
        new THREE.Vector2(-ancho / 2, -alto / 2), new THREE.Vector2(ancho / 2, -alto / 2),
        new THREE.Vector2(ancho / 2, alto / 2), new THREE.Vector2(-ancho / 2, alto / 2),
    ];
    const normales = [new THREE.Vector2(0, -1), new THREE.Vector2(1, 0), new THREE.Vector2(0, 1), new THREE.Vector2(-1, 0)];
    const bordes = [];
    for (let l = 0; l < 4; l++) {
        const a = esquinas[l], b = esquinas[(l + 1) % 4];
        const e = [];
        if (lados[l] === null) {
            e.push(a.clone(), b.clone());
        } else {
            bordeDentado(e, a, b, dedos[l % 2], espesor, normales[l], lados[l]);
        }
        bordes.push(e);
    }
    // En cada esquina, el punto que combina el retranqueo de los dos bordes:
    // sin él, dos dedos hacia dentro se unirían con un corte en diagonal.
    for (let l = 0; l < 4; l++) {
        const e = bordes[l], sig = bordes[(l + 1) % 4];
        const esquina = esquinas[(l + 1) % 4];
        const fin = e[e.length - 1], ini = sig[0];
        const combinado = new THREE.Vector2().addVectors(fin, ini).sub(esquina);
        p.push(...e, combinado);
    }
    // quitar duplicados consecutivos
    const limpio = p.filter((v, i) => i === 0 || v.distanceTo(p[i - 1]) > 1e-7);
    if (limpio.length > 2 && limpio[0].distanceTo(limpio[limpio.length - 1]) < 1e-7) limpio.pop();
    return new THREE.Shape(limpio);
}

/* ------------------------------------------------------------------ */
/* Animación                                                            */
/* ------------------------------------------------------------------ */

/** Pista de rotación continua alrededor de Y: `vueltas` vueltas en `duracion` segundos. */
export function pistaGiro(nombreNodo, vueltas, duracion, { inicial = 0, claves = 65 } = {}) {
    const tiempos = [], valores = [];
    const q = new THREE.Quaternion(), eje = new THREE.Vector3(0, 1, 0);
    for (let i = 0; i < claves; i++) {
        const t = (i / (claves - 1)) * duracion;
        tiempos.push(t);
        q.setFromAxisAngle(eje, inicial + (i / (claves - 1)) * vueltas * Math.PI * 2);
        valores.push(q.x, q.y, q.z, q.w);
    }
    return new THREE.QuaternionKeyframeTrack(`${nombreNodo}.quaternion`, tiempos, valores);
}
