/*
 * Piezas de ajedrez impresas en 3D sobre un tablero de 4 × 2 casillas.
 * Rey, torre, alfil y peón son sólidos de revolución: un perfil que gira
 * alrededor del eje, como en un torno. Las almenas de la torre y la cruz del
 * rey se añaden aparte. Se imprimen de pie, sin soportes.
 */
import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { uvImpresion } from '../piezas.js';
import { capasImpresion, textura } from '../texturas.js';

const MM = 0.001;
const CASILLA = 30 * MM;
const ALTO_TABLERO = 6 * MM;

/** Perfil (radio, altura) en mm → sólido de revolución en metros. */
function torno(perfil, segmentos = 64) {
    const g = new THREE.LatheGeometry(perfil.map(([r, y]) => new THREE.Vector2(r * MM, y * MM)), segmentos);
    return g;
}

/** Arco de puntos (radio, altura) de una elipse con centro (0, yc), de `desde` a `hasta` grados. */
function arco(rx, ry, yc, desde, hasta, pasos = 14) {
    const p = [];
    for (let i = 0; i <= pasos; i++) {
        const a = ((desde + ((hasta - desde) * i) / pasos) * Math.PI) / 180;
        p.push([Math.max(0, rx * Math.cos(a)), yc + ry * Math.sin(a)]);
    }
    return p;
}

/** La base común: zócalo con moldura. */
const zocalo = (r) => [[0, 0], [r, 0], [r, 3], [r - 1.5, 4.5], [r - 1.5, 6.5], [r - 4, 9]];

const piezas = {
    peon: () => torno([
        ...zocalo(13), [6.5, 15], [5, 24], [8, 26], [8, 27.5], [4.5, 29.5],
        ...arco(7.5, 7.5, 36, -62, 90),
    ]),
    torre: () => {
        const cuerpo = torno([
            ...zocalo(15), [10, 30], [12.5, 33.5], [12.5, 35.5], [11, 36.5], [11, 41], [13, 42], [13, 44], [0, 44],
        ]);
        // cinco almenas sobre el borde
        const almenas = [];
        for (let k = 0; k < 5; k++) {
            const a0 = (k / 5) * Math.PI * 2, a1 = a0 + (Math.PI * 2 / 5) * 0.58;
            const s = new THREE.Shape();
            s.absarc(0, 0, 13 * MM, a0, a1, false);
            s.absarc(0, 0, 8.5 * MM, a1, a0, true);
            const g = new THREE.ExtrudeGeometry(s, { depth: 6 * MM, bevelEnabled: false, curveSegments: 12 });
            g.rotateX(-Math.PI / 2);
            g.translate(0, 44 * MM, 0);
            almenas.push(g.toNonIndexed());
        }
        return mergeGeometries([cuerpo.toNonIndexed(), ...almenas]);
    },
    alfil: () => torno([
        ...zocalo(14), [7, 31], [10.5, 34], [10.5, 36], [6.5, 37.5],
        ...arco(8, 11, 47, -62, 72), [2.2, 57.6],
        ...arco(3, 3, 60.5, -75, 90, 8),
    ]),
    rey: () => {
        const cuerpo = torno([
            ...zocalo(16.5), [8.5, 40], [12.5, 43], [12.5, 45], [9, 46.5], [11, 52], [13, 58], [13, 60], [0, 60],
        ]);
        const vertical = new THREE.BoxGeometry(4.5 * MM, 15 * MM, 4.5 * MM).translate(0, 67.5 * MM, 0);
        const horizontal = new THREE.BoxGeometry(13 * MM, 4.5 * MM, 4.5 * MM).translate(0, 69 * MM, 0);
        return mergeGeometries([cuerpo.toNonIndexed(), vertical.toNonIndexed(), horizontal.toNonIndexed()]);
    },
};

export default {
    slug: 'ajedrez',
    texturaMax: 256,

    async crear() {
        const capas = textura(capasImpresion({ tam: 256, capas: 32, semilla: 17 }), { color: false, nombre: 'capas' });
        const material = (nombre, color, rugosidad) => new THREE.MeshStandardMaterial({
            name: nombre, color, roughness: rugosidad, metalness: 0, normalMap: capas, normalScale: new THREE.Vector2(0.7, 0.7),
        });
        const mClaro = material('PLA marfil', '#efe6d2', 0.45);
        const mOscuro = material('PLA negro', '#2b2c30', 0.4);
        const mCasillaClara = material('Casilla clara', '#d9c4a0', 0.6);
        const mCasillaOscura = material('Casilla oscura', '#6b4a32', 0.6);

        const raiz = new THREE.Group();
        raiz.name = 'Piezas de ajedrez';

        // tablero: 4 × 2 casillas, cada una una pieza (se imprimen en dos colores)
        const claras = [], oscuras = [];
        for (let i = 0; i < 4; i++) {
            for (let j = 0; j < 2; j++) {
                const g = new THREE.BoxGeometry(CASILLA, ALTO_TABLERO, CASILLA)
                    .translate((i - 1.5) * CASILLA, ALTO_TABLERO / 2, (j - 0.5) * CASILLA);
                ((i + j) % 2 === 0 ? oscuras : claras).push(g);
            }
        }
        const tableroClaro = new THREE.Mesh(uvImpresion(mergeGeometries(claras)), mCasillaClara);
        tableroClaro.name = 'Casillas claras';
        const tableroOscuro = new THREE.Mesh(uvImpresion(mergeGeometries(oscuras)), mCasillaOscura);
        tableroOscuro.name = 'Casillas oscuras';
        raiz.add(tableroClaro, tableroOscuro);

        const poner = (tipo, nombre, material, i, j, giro = 0) => {
            const g = uvImpresion(piezas[tipo]());
            const m = new THREE.Mesh(g, material);
            m.name = nombre;
            m.position.set((i - 1.5) * CASILLA, ALTO_TABLERO, (j - 0.5) * CASILLA);
            m.rotation.y = giro;
            raiz.add(m);
        };
        poner('torre', 'Torre blanca', mClaro, 0, 1, 0.2);
        poner('rey', 'Rey negro', mOscuro, 1, 0, Math.PI / 4);
        poner('peon', 'Peón blanco', mClaro, 2, 1);
        poner('alfil', 'Alfil negro', mOscuro, 3, 0);

        return { escena: raiz, animaciones: [] };
    },
};
