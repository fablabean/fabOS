/*
 * Una impresión a medio camino: un cubo de 40 mm parado en el 40 % de su
 * altura, para ver por dentro cómo se construye una pieza FDM —la base
 * maciza, las dos paredes del perímetro y el relleno en rejilla al 20 %—,
 * con el cabezal de la impresora recorriendo la capa que está haciendo.
 */
import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { uvImpresion } from '../piezas.js';
import { capasImpresion, cepillado, textura } from '../texturas.js';

const LADO = 0.04;           // 40 mm
const ALTO = 0.016;          // lo impreso hasta ahora
const BASE = 0.0012;         // 6 capas macizas abajo
const PARED = 0.0009;        // dos perímetros de 0,45 mm
const CAPA = 0.0002;

/** Una caja de ancho × alto × fondo con la base en y = 0. */
const caja = (ancho, alto, fondo) => new THREE.BoxGeometry(ancho, alto, fondo).translate(0, alto / 2, 0);

/**
 * Relleno en rejilla: líneas a ±45° cada `paso`, recortadas al interior
 * cuadrado de medio lado `h`. Cada línea es una caja delgada.
 */
function rejilla(h, paso, ancho, y0, alto) {
    const partes = [];
    for (const signo of [1, -1]) {
        for (let c = -2 * h + paso / 2; c < 2 * h; c += paso) {
            // línea z = signo·x − c, recortada a |x|, |z| ≤ h
            let x0 = -h, x1 = h;
            if (signo > 0) { x0 = Math.max(-h, c - h); x1 = Math.min(h, c + h); } else { x0 = Math.max(-h, -c - h); x1 = Math.min(h, -c + h); }
            if (x1 - x0 < 0.001) continue;
            const largo = (x1 - x0) * Math.SQRT2;
            const g = caja(largo, alto, ancho);
            g.rotateY(-signo * Math.PI / 4);
            const xm = (x0 + x1) / 2;
            g.translate(xm, y0, signo * xm - c);
            partes.push(g);
        }
    }
    return mergeGeometries(partes);
}

export default {
    slug: 'impresion',
    texturaMax: 256,

    async crear() {
        const capas = textura(capasImpresion({ tam: 256, capas: 32, semilla: 5 }), { color: false, nombre: 'capas' });
        const mPla = new THREE.MeshStandardMaterial({
            name: 'PLA azul', color: '#2f7fd6', roughness: 0.48, metalness: 0,
            normalMap: capas, normalScale: new THREE.Vector2(0.9, 0.9),
        });
        const mRelleno = new THREE.MeshStandardMaterial({
            name: 'Relleno', color: '#2a72c2', roughness: 0.52, metalness: 0,
            normalMap: capas, normalScale: new THREE.Vector2(0.9, 0.9),
        });
        const rugAluminio = textura(cepillado({ tam: 256 }), { color: false, nombre: 'cepillado' });
        const mBloque = new THREE.MeshStandardMaterial({ name: 'Aluminio del bloque calefactor', color: '#c9ccd1', metalness: 1, roughness: 1, roughnessMap: rugAluminio, metalnessMap: rugAluminio });
        const mDisipador = new THREE.MeshStandardMaterial({ name: 'Disipador anodizado', color: '#2b2d31', metalness: 0.7, roughness: 0.4 });
        const mLaton = new THREE.MeshStandardMaterial({ name: 'Boquilla de latón', color: '#d4a64a', metalness: 1, roughness: 0.28 });
        const mFilamento = new THREE.MeshStandardMaterial({ name: 'Filamento', color: '#2f7fd6', roughness: 0.3 });

        const raiz = new THREE.Group();
        raiz.name = 'Impresion a medio camino';

        // --- La pieza ---
        const base = uvImpresion(caja(LADO, BASE, LADO), { altoCapa: CAPA });
        const contorno = new THREE.Shape([[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([x, z]) => new THREE.Vector2((x * LADO) / 2, (z * LADO) / 2)));
        const hi = LADO / 2 - PARED;
        contorno.holes.push(new THREE.Path([[-1, -1], [-1, 1], [1, 1], [1, -1]].map(([x, z]) => new THREE.Vector2(x * hi, z * hi))));
        const anillo = new THREE.ExtrudeGeometry(contorno, { depth: ALTO - BASE, bevelEnabled: false });
        anillo.rotateX(-Math.PI / 2);
        anillo.translate(0, BASE, 0);
        const pieza = new THREE.Mesh(mergeGeometries([base, uvImpresion(anillo, { altoCapa: CAPA })]), mPla);
        pieza.name = 'Base y paredes';
        raiz.add(pieza);

        const relleno = new THREE.Mesh(uvImpresion(rejilla(hi + 0.0002, 0.0045, 0.00045, BASE, ALTO - BASE - CAPA), { altoCapa: CAPA }), mRelleno);
        relleno.name = 'Relleno en rejilla';
        raiz.add(relleno);

        // --- El cabezal (hotend) ---
        const cabezal = new THREE.Group();
        cabezal.name = 'Cabezal';
        const yBoquilla = ALTO + CAPA;
        // boquilla: cono de latón con la punta de 0,4 mm y un hexágono arriba
        const boquilla = new THREE.LatheGeometry([
            [0, 0], [0.0002, 0], [0.0005, 0.0003], [0.0028, 0.0034], [0.0028, 0.0042],
            [0.004, 0.0042], [0.004, 0.0072], [0.0028, 0.0072], [0.0028, 0.0082], [0, 0.0082],
        ].map(([r, y]) => new THREE.Vector2(r, y)), 24);
        const mBoquilla = new THREE.Mesh(boquilla, mLaton);
        mBoquilla.name = 'Boquilla';
        cabezal.add(mBoquilla);
        // bloque calefactor con su cartucho y su termistor
        const bloque = new THREE.Mesh(caja(0.02, 0.01, 0.016).translate(-0.0045, 0.0082, 0), mBloque);
        bloque.name = 'Bloque calefactor';
        cabezal.add(bloque);
        const cartucho = new THREE.Mesh(new THREE.CylinderGeometry(0.003, 0.003, 0.018, 24).rotateZ(Math.PI / 2).translate(-0.0095, 0.0112, 0), mDisipador);
        cartucho.name = 'Cartucho calefactor';
        cabezal.add(cartucho);
        // garganta y disipador de aletas
        const garganta = new THREE.Mesh(new THREE.CylinderGeometry(0.0021, 0.0021, 0.008, 20).translate(0, 0.0222, 0), mBloque);
        garganta.name = 'Garganta';
        cabezal.add(garganta);
        const aletas = [];
        for (let i = 0; i < 9; i++) aletas.push(new THREE.CylinderGeometry(0.0115, 0.0115, 0.0012, 40).translate(0, 0.027 + i * 0.0026, 0));
        aletas.push(new THREE.CylinderGeometry(0.0045, 0.0045, 0.024, 24).translate(0, 0.0372, 0));
        const disipador = new THREE.Mesh(mergeGeometries(aletas), mDisipador);
        disipador.name = 'Disipador';
        cabezal.add(disipador);
        const filamento = new THREE.Mesh(new THREE.CylinderGeometry(0.000875, 0.000875, 0.03, 16).translate(0, 0.064, 0), mFilamento);
        filamento.name = 'Filamento de 1,75 mm';
        cabezal.add(filamento);
        cabezal.position.set(-LADO / 2 + PARED / 4, yBoquilla, -LADO / 2 + PARED / 4);
        raiz.add(cabezal);

        // --- Animación: el cabezal recorre el perímetro de la capa ---
        const T = 8;
        const m = LADO / 2 - PARED / 4;
        const esquinas = [[-m, -m], [m, -m], [m, m], [-m, m], [-m, -m]];
        const tiempos = [], valores = [];
        esquinas.forEach(([x, z], i) => { tiempos.push((i / 4) * T); valores.push(x, yBoquilla, z); });
        const clip = new THREE.AnimationClip('Imprimir la capa', T, [new THREE.VectorKeyframeTrack('Cabezal.position', tiempos, valores)]);

        return { escena: raiz, animaciones: [clip] };
    },
};
