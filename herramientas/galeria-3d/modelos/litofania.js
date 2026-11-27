/*
 * Litofanía: una lámina curva de PLA blanco cuyo grosor cambia punto a
 * punto. Apagada se ve como un relieve; con la luz de la base encendida, lo
 * delgado deja pasar más luz y aparece la imagen: los cerros orientales de
 * Bogotá con Monserrate, el sol y el nombre del laboratorio.
 *
 * La imagen se dibuja en un canvas; de ella sale el grosor (más oscuro, más
 * grueso) y el mapa de emisión que simula la luz que la atraviesa.
 */
import * as THREE from 'three';
import { uvImpresion } from '../piezas.js';
import { azar, fractal, lienzo, ruido, textura, texto } from '../texturas.js';

const RADIO = 0.07;
const ARCO = (115 * Math.PI) / 180;
const ALTO = 0.085;
const MIN = 0.0008, MAX = 0.0032;       // grosor de 0,8 a 3,2 mm
const COLS = 300, FILAS = 220;

/** La escena en escala de grises (blanco = delgado = luz). */
function imagen() {
    const W = 600, H = 440;
    const c = lienzo(W, H);
    const ctx = c.getContext('2d');
    // cielo: claro arriba, con el sol a la derecha
    const cielo = ctx.createLinearGradient(0, 0, 0, H);
    cielo.addColorStop(0, '#d8d8d8'); cielo.addColorStop(0.6, '#f2f2f2');
    ctx.fillStyle = cielo; ctx.fillRect(0, 0, W, H);
    const sol = ctx.createRadialGradient(430, 120, 10, 430, 120, 120);
    sol.addColorStop(0, '#ffffff'); sol.addColorStop(0.3, '#fbfbfb'); sol.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = sol; ctx.fillRect(0, 0, W, H);
    // nubes suaves
    const r = azar(8);
    ctx.fillStyle = 'rgba(190,190,190,0.55)';
    for (let i = 0; i < 5; i++) {
        const x = 40 + r() * 300, y = 50 + r() * 70;
        for (let k = 0; k < 5; k++) { ctx.beginPath(); ctx.ellipse(x + k * 18, y + Math.sin(k) * 6, 26, 12, 0, 0, Math.PI * 2); ctx.fill(); }
    }
    // tres capas de cerros, de lejos (claros) a cerca (oscuros)
    const n = ruido(3, 64);
    const cerros = [
        { base: 250, alto: 90, tono: 175, escala: 0.008, cumbre: 0.32 },
        { base: 300, alto: 110, tono: 120, escala: 0.011, cumbre: 0.3 },
        { base: 360, alto: 70, tono: 70, escala: 0.016, cumbre: 0.7 },
    ];
    for (const m of cerros) {
        ctx.fillStyle = `rgb(${m.tono},${m.tono},${m.tono})`;
        ctx.beginPath();
        ctx.moveTo(0, H);
        for (let x = 0; x <= W; x += 3) {
            const pico = Math.exp(-Math.pow((x / W - m.cumbre) / 0.16, 2));
            const y = m.base - m.alto * (0.35 + 0.65 * pico) * (0.7 + 0.6 * fractal(n, x * m.escala, m.tono, 3));
            ctx.lineTo(x, y);
        }
        ctx.lineTo(W, H); ctx.closePath(); ctx.fill();
    }
    // el santuario en la cumbre del cerro del medio
    const cx = 0.3 * W, cy = 300 - 110 * 0.98 - 6;
    ctx.fillStyle = 'rgb(95,95,95)';
    ctx.fillRect(cx - 14, cy - 10, 28, 14);
    ctx.fillRect(cx - 5, cy - 30, 10, 22);
    ctx.beginPath(); ctx.moveTo(cx - 7, cy - 30); ctx.lineTo(cx, cy - 40); ctx.lineTo(cx + 7, cy - 30); ctx.fill();
    // nombre en la parte de abajo, en oscuro (grueso)
    ctx.fillStyle = 'rgb(40,40,40)';
    // (los 25 px de abajo quedan dentro de la base: el letrero va por encima)
    ctx.fillRect(0, 352, W, 88);
    texto(ctx, 'FABLAB EAN · BOGOTÁ', W / 2, 384, { tam: 30, peso: 800, color: '#f2f2f2', espaciado: 4 });
    // marco oscuro (grueso) alrededor, para que la lámina sea resistente
    ctx.strokeStyle = 'rgb(30,30,30)'; ctx.lineWidth = 18; ctx.strokeRect(0, 0, W, H);
    return c;
}

/** Luz que atraviesa: cálida, y cae con el grosor como en el plástico de verdad. */
function mapaDeLuz(gris) {
    const c = lienzo(gris.width, gris.height);
    const ctx = c.getContext('2d');
    const src = gris.getContext('2d').getImageData(0, 0, gris.width, gris.height).data;
    const img = ctx.createImageData(gris.width, gris.height);
    for (let i = 0; i < src.length; i += 4) {
        const brillo = src[i] / 255;
        const grosor = MIN + (1 - brillo) * (MAX - MIN);
        const paso = Math.exp(-(grosor - MIN) / 0.00045);       // atenuación del PLA blanco
        img.data[i] = 255 * paso;
        img.data[i + 1] = 220 * paso;
        img.data[i + 2] = 170 * paso;
        img.data[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    return c;
}

export default {
    slug: 'litofania',
    texturaMax: 1024,
    simplificacion: { umbral: 40000, proporcion: 0.18, error: 0.0006 },

    async crear() {
        const gris = imagen();
        const pix = gris.getContext('2d').getImageData(0, 0, gris.width, gris.height).data;
        const brilloEn = (u, v) => {
            const x = Math.min(gris.width - 1, Math.max(0, Math.round(u * (gris.width - 1))));
            const y = Math.min(gris.height - 1, Math.max(0, Math.round((1 - v) * (gris.height - 1))));
            return pix[(y * gris.width + x) * 4] / 255;
        };

        const mLamina = new THREE.MeshStandardMaterial({
            // Un blanco apagado: así, encendida, manda la luz que atraviesa la
            // lámina (como en una habitación en penumbra) y se lee la imagen.
            name: 'PLA blanco', color: '#8c867b', roughness: 0.72, metalness: 0,
            emissive: '#ffffff', emissiveMap: textura(mapaDeLuz(gris), { repetir: false, nombre: 'luz' }), emissiveIntensity: 1.35,
        });
        const mBase = new THREE.MeshStandardMaterial({ name: 'PLA gris grafito', color: '#3a3c40', roughness: 0.5 });
        const mLed = new THREE.MeshStandardMaterial({ name: 'Tira LED', color: '#fff4dc', emissive: '#ffd9a0', emissiveIntensity: 1.2 });

        const raiz = new THREE.Group();
        raiz.name = 'Litofania';
        const y0 = 0.012;    // la lámina entra en la base

        // --- La lámina: cara de fuera con relieve, cara de dentro lisa, cantos ---
        const pos = [], uv = [], idx = [];
        const punto = (u, v, r) => {
            const a = -ARCO / 2 + u * ARCO;
            pos.push(Math.sin(a) * r, y0 + v * ALTO, Math.cos(a) * r - RADIO);
            uv.push(u, v);
            return pos.length / 3 - 1;
        };
        const fuera = [], dentro = [];
        for (let i = 0; i <= FILAS; i++) {
            for (let j = 0; j <= COLS; j++) {
                const u = j / COLS, v = i / FILAS;
                const grosor = MIN + (1 - brilloEn(u, v)) * (MAX - MIN);
                fuera.push(punto(u, v, RADIO + grosor));
            }
        }
        for (let i = 0; i <= FILAS; i++) for (let j = 0; j <= COLS; j++) dentro.push(punto(j / COLS, i / FILAS, RADIO));
        const fila = COLS + 1;
        const id = (malla, i, j) => malla[i * fila + j];
        for (let i = 0; i < FILAS; i++) {
            for (let j = 0; j < COLS; j++) {
                const a = id(fuera, i, j), b = id(fuera, i, j + 1), c = id(fuera, i + 1, j), d = id(fuera, i + 1, j + 1);
                idx.push(a, b, c, b, d, c);
                const e = id(dentro, i, j), f = id(dentro, i, j + 1), g = id(dentro, i + 1, j), h = id(dentro, i + 1, j + 1);
                idx.push(e, g, f, f, g, h);
            }
        }
        // cantos: abajo, arriba, izquierda y derecha, uniendo fuera con dentro
        const canto = (lista, invertir) => {
            for (let k = 0; k + 1 < lista.length; k++) {
                const [a, b] = lista[k], [c, d] = lista[k + 1];
                if (invertir) idx.push(a, b, c, b, d, c); else idx.push(a, c, b, b, c, d);
            }
        };
        canto(Array.from({ length: fila }, (_, j) => [id(fuera, 0, j), id(dentro, 0, j)]), true);
        canto(Array.from({ length: fila }, (_, j) => [id(fuera, FILAS, j), id(dentro, FILAS, j)]), false);
        canto(Array.from({ length: FILAS + 1 }, (_, i) => [id(fuera, i, 0), id(dentro, i, 0)]), false);
        canto(Array.from({ length: FILAS + 1 }, (_, i) => [id(fuera, i, COLS), id(dentro, i, COLS)]), true);

        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
        geo.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
        geo.setIndex(idx);
        geo.computeVertexNormals();
        const lamina = new THREE.Mesh(geo, mLamina);
        lamina.name = 'Lamina';
        raiz.add(lamina);

        // --- La base: un arco grueso con la ranura (y el LED) ---
        const forma = new THREE.Shape();
        const rFuera = RADIO + 0.012, rDentro = RADIO - 0.012, margen = 0.08;
        forma.absarc(0, 0, rFuera, Math.PI / 2 - ARCO / 2 - margen, Math.PI / 2 + ARCO / 2 + margen, false);
        forma.absarc(0, 0, rDentro, Math.PI / 2 + ARCO / 2 + margen, Math.PI / 2 - ARCO / 2 - margen, true);
        // 16 mm de alto con el bisel: la lámina entra 4 mm en la ranura
        const base = new THREE.ExtrudeGeometry(forma, { depth: 0.013, bevelEnabled: true, bevelThickness: 0.0015, bevelSize: 0.0015, bevelSegments: 3, curveSegments: 48 });
        base.rotateX(-Math.PI / 2);
        // el arco de la forma está en +Y (plano XY) → tras girar queda en −Z; se lleva al de la lámina
        base.rotateY(Math.PI);
        base.translate(0, 0.0015, -RADIO);
        const mallaBase = new THREE.Mesh(uvImpresion(base), mBase);
        mallaBase.name = 'Base';
        raiz.add(mallaBase);

        // la tira LED asoma por dentro, sobre la base, y alumbra la lámina
        const led = new THREE.Mesh(new THREE.TorusGeometry(RADIO - 0.005, 0.0016, 8, 64, ARCO).rotateX(Math.PI / 2).rotateY(ARCO / 2 - Math.PI / 2), mLed);
        led.name = 'Tira LED';
        led.position.set(0, 0.0175, -RADIO);
        raiz.add(led);

        return { escena: raiz, animaciones: [] };
    },
};
