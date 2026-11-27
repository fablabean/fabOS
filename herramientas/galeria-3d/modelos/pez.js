/*
 * Pez articulado («flexi»), impreso de una vez y ya ensamblado: los
 * segmentos salen sueltos de la impresora, unidos por bisagras con 0,4 mm de
 * holgura, y el pez se mueve como si nadara. Filamento arcoíris: el color
 * cambia a lo largo del cuerpo, como cuando el carrete cambia de tono.
 *
 * Se imprime acostado: el contorno del pez queda sobre la cama (plano XZ) y
 * el grosor, 9 mm, es la altura (Y). Cada segmento es una malla cerrada.
 */
import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { capasImpresion, textura } from '../texturas.js';

const LARGO = 0.13;        // de la boca a la base de la cola
const GROSOR = 0.009;      // alto de la impresión
const HUECO = 0.0008;      // separación entre segmentos
const SEGMENTOS = 11;

/** Medio ancho del cuerpo (en Z) a lo largo de x ∈ [0, LARGO]: cabeza redonda que se afina a la cola. */
function medioAncho(x) {
    const t = Math.min(1, Math.max(0, x / LARGO));
    // hocico redondeado, panza ancha hacia el primer tercio y pedúnculo fino
    const s = Math.pow((t + 0.035) / 1.035, 0.68);
    return 0.0035 + 0.024 * Math.sin(Math.PI * s) * (1 - 0.25 * t);
}

/** Medio grosor (en Y): el lomo se redondea hacia la cola. */
const medioGrosor = (x) => (GROSOR / 2) * (1 - 0.3 * Math.pow(x / LARGO, 2));

/**
 * Un segmento cerrado entre x0 y x1: secciones superelípticas (casi
 * rectángulos de esquinas redondas) unidas por anillos, con tapas.
 */
function segmento(x0, x1, color0, color1) {
    const filas = 8, alrededor = 40;
    const pos = [], col = [], idx = [];
    const seccion = (x, c) => {
        const a = Math.max(medioAncho(x), 0.0012), b = medioGrosor(x);
        const inicio = pos.length / 3;
        for (let j = 0; j < alrededor; j++) {
            const th = (j / alrededor) * Math.PI * 2;
            const cs = Math.cos(th), sn = Math.sin(th);
            // superelipse de exponente 4
            const z = a * Math.sign(cs) * Math.pow(Math.abs(cs), 0.5);
            const y = b * Math.sign(sn) * Math.pow(Math.abs(sn), 0.5);
            pos.push(x, b + y, z);
            col.push(c.r, c.g, c.b);
        }
        return inicio;
    };
    const anillos = [];
    for (let i = 0; i <= filas; i++) {
        const t = i / filas;
        anillos.push(seccion(x0 + (x1 - x0) * t, color0.clone().lerp(color1, t)));
    }
    for (let i = 0; i < filas; i++) {
        for (let j = 0; j < alrededor; j++) {
            const a = anillos[i] + j, b = anillos[i] + ((j + 1) % alrededor);
            const c = anillos[i + 1] + j, d = anillos[i + 1] + ((j + 1) % alrededor);
            idx.push(a, c, b, b, c, d);   // hacia fuera
        }
    }
    // tapas: abanico desde el centro de cada extremo
    const tapa = (anillo, x, c, haciaAtras) => {
        const centro = pos.length / 3;
        pos.push(x, medioGrosor(x), 0);
        col.push(c.r, c.g, c.b);
        for (let j = 0; j < alrededor; j++) {
            const a = anillo + j, b = anillo + ((j + 1) % alrededor);
            if (haciaAtras) idx.push(centro, a, b); else idx.push(centro, b, a);
        }
    };
    tapa(anillos[0], x0, color0, true);
    tapa(anillos[filas], x1, color1, false);
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.setAttribute('color', new THREE.Float32BufferAttribute(col, 3));
    g.setIndex(idx);
    g.computeVertexNormals();
    return g;
}

/** Una aleta plana (contorno en XZ) extruida al grosor indicado, con color. */
function aleta(puntos, grosor, color, y0 = 0) {
    const forma = new THREE.Shape(puntos.map(([x, z]) => new THREE.Vector2(x, -z)));
    const g = new THREE.ExtrudeGeometry(forma, { depth: grosor, bevelEnabled: true, bevelThickness: 0.0006, bevelSize: 0.0006, bevelSegments: 2, curveSegments: 16 });
    g.rotateX(-Math.PI / 2);                 // de XY a XZ, el grosor hacia +Y
    g.translate(0, y0 + 0.0006, 0);
    g.deleteAttribute('uv');
    const n = g.attributes.position.count;
    g.setAttribute('color', new THREE.Float32BufferAttribute(new Array(n).fill(0).flatMap(() => [color.r, color.g, color.b]), 3));
    return mergeGeometries([g.toNonIndexed()]);
}

/** Coordenadas UV para el mapa de capas: V sube con Y (las capas son horizontales). */
function uvCapas(g) {
    const p = g.attributes.position;
    const uv = new Float32Array(p.count * 2);
    for (let i = 0; i < p.count; i++) {
        uv[i * 2] = (p.getX(i) + p.getZ(i)) / 0.02;
        uv[i * 2 + 1] = p.getY(i) / 0.0064;
    }
    g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    return g;
}

export default {
    slug: 'pez',
    texturaMax: 256,

    async crear() {
        const capas = textura(capasImpresion({ tam: 256, capas: 32, semilla: 9 }), { color: false, nombre: 'capas' });
        const mPla = new THREE.MeshStandardMaterial({
            name: 'PLA arcoíris', vertexColors: true, roughness: 0.38, metalness: 0.05,
            normalMap: capas, normalScale: new THREE.Vector2(0.5, 0.5),
        });
        const mOjo = new THREE.MeshStandardMaterial({ name: 'PLA negro', color: '#16171a', roughness: 0.3 });

        // el arcoíris del carrete, de la boca a la cola
        const paleta = ['#ff5a5f', '#ff9f1c', '#ffd23f', '#3bceac', '#2ec4f1', '#5a67f2', '#b15cf2'].map((c) => new THREE.Color(c));
        const colorEn = (t) => {
            const f = Math.min(0.9999, Math.max(0, t)) * (paleta.length - 1);
            const i = Math.floor(f);
            return paleta[i].clone().lerp(paleta[i + 1], f - i);
        };

        const raiz = new THREE.Group();
        raiz.name = 'Pez articulado';
        // el pez mira hacia −X; se centra en el origen
        const desplazamiento = -LARGO * 0.55;

        // cortes: la cabeza más larga, los segmentos se acortan hacia la cola
        const cortes = [0];
        const pesos = Array.from({ length: SEGMENTOS }, (_, i) => (i === 0 ? 2.6 : 1 - i * 0.035));
        const total = pesos.reduce((a, b) => a + b, 0);
        let acumulado = 0;
        for (const p of pesos) { acumulado += p; cortes.push((acumulado / total) * LARGO); }

        let padre = raiz;
        const pivotes = [];
        for (let i = 0; i < SEGMENTOS; i++) {
            const x0 = cortes[i] + (i === 0 ? 0 : HUECO / 2);
            const x1 = cortes[i + 1] - (i === SEGMENTOS - 1 ? 0 : HUECO / 2);
            const partes = [segmento(x0, x1, colorEn(x0 / LARGO), colorEn(x1 / LARGO))];
            // bisagra: un pasador que entra en el segmento anterior
            if (i > 0) {
                const r = 0.0016, largo = HUECO + 0.004;
                const pasador = new THREE.CylinderGeometry(r, r, GROSOR * 0.55, 16);
                pasador.translate(x0 - largo / 2 + 0.002, medioGrosor(x0), 0);
                pasador.deleteAttribute('uv');
                const c = colorEn(x0 / LARGO);
                pasador.setAttribute('color', new THREE.Float32BufferAttribute(new Array(pasador.attributes.position.count).fill(0).flatMap(() => [c.r, c.g, c.b]), 3));
                partes.push(pasador);
            }
            if (i === SEGMENTOS - 1) {
                // la cola, abierta en V
                const xc = x1 - 0.002;
                partes.push(aleta([[xc, 0], [xc + 0.022, -0.026], [xc + 0.03, -0.024], [xc + 0.016, 0], [xc + 0.03, 0.024], [xc + 0.022, 0.026]], GROSOR * 0.45, colorEn(1), GROSOR * 0.12));
            }
            if (i === 4) {
                // aletas laterales
                const xa = (x0 + x1) / 2, w = medioAncho(xa);
                partes.push(aleta([[xa - 0.006, w - 0.002], [xa + 0.008, w + 0.012], [xa + 0.01, w - 0.001]], GROSOR * 0.3, colorEn(xa / LARGO), GROSOR * 0.2));
                partes.push(aleta([[xa - 0.006, -w + 0.002], [xa + 0.01, -w + 0.001], [xa + 0.008, -w - 0.012]], GROSOR * 0.3, colorEn(xa / LARGO), GROSOR * 0.2));
            }
            const geo = uvCapas(mergeGeometries(partes.map((g) => (g.index ? g.toNonIndexed() : g))));
            geo.translate(-cortes[i], 0, 0);   // el origen del segmento en su bisagra
            geo.computeVertexNormals();

            const pivote = new THREE.Group();
            pivote.name = `Segmento ${i + 1}`;
            pivote.position.x = i === 0 ? cortes[0] + desplazamiento : cortes[i] - cortes[i - 1];
            const malla = new THREE.Mesh(geo, mPla);
            malla.name = i === 0 ? 'Cabeza' : i === SEGMENTOS - 1 ? 'Cola' : `Cuerpo ${i}`;
            pivote.add(malla);
            padre.add(pivote);
            pivotes.push(pivote);
            padre = pivote;

            if (i === 0) {
                // ojos a los dos lados de la cabeza
                for (const lado of [-1, 1]) {
                    const ojo = new THREE.Mesh(new THREE.SphereGeometry(0.0032, 20, 12), mOjo);
                    ojo.name = lado < 0 ? 'Ojo izquierdo' : 'Ojo derecho';
                    ojo.position.set(0.012, GROSOR * 0.62, lado * (medioAncho(0.012) - 0.0016));
                    pivote.add(ojo);
                }
            }
        }

        // --- Animación: una onda que recorre el cuerpo, más amplia en la cola ---
        const T = 2.4, claves = 49;
        const pistas = pivotes.slice(1).map((pv, k) => {
            const i = k + 1;
            const tiempos = [], valores = [];
            const q = new THREE.Quaternion(), eje = new THREE.Vector3(0, 1, 0);
            const amplitud = 0.05 + 0.13 * (i / SEGMENTOS);
            for (let c = 0; c < claves; c++) {
                const t = (c / (claves - 1)) * T;
                tiempos.push(t);
                q.setFromAxisAngle(eje, amplitud * Math.sin((t / T) * Math.PI * 2 - i * 0.55));
                valores.push(q.x, q.y, q.z, q.w);
            }
            return new THREE.QuaternionKeyframeTrack(`${pv.name}.quaternion`, tiempos, valores);
        });
        const clip = new THREE.AnimationClip('Nadar', T, pistas);

        return { escena: raiz, animaciones: [clip] };
    },
};
