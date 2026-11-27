/*
 * Jarrón paramétrico en espiral, impreso en «modo vaso» (una sola pared).
 *
 * La superficie se genera con mucho detalle a propósito —400 anillos de 256
 * puntos, como la exportaría un programa de CAD— para que el optimizador
 * tenga trabajo: la forma se simplifica y las capas de impresión, que no
 * caben en la geometría, las pone el mapa de normales.
 */
import * as THREE from 'three';
import { capasImpresion, textura } from '../texturas.js';

export default {
    slug: 'jarron',
    texturaMax: 512,
    // La forma es lisa: con un error máximo de 0,15 % del tamaño (normales
    // con poco peso) quedan unos 25.000 triángulos sin facetas visibles.
    simplificacion: { umbral: 12000, proporcion: 0.06, error: 0.0015, pesoNormales: 0.05 },

    async crear() {
        const alto = 0.2, pared = 0.0012;
        const anillos = 400, puntos = 256, lobulos = 7, giro = Math.PI * 0.9;

        // radio exterior según altura y ángulo
        const radio = (t, a) => {
            const perfil = 0.045 + 0.028 * Math.sin(Math.PI * Math.pow(t, 0.85)) - 0.012 * t + 0.006 * Math.pow(t, 6);
            const ondas = 1 + 0.085 * Math.cos(lobulos * a + giro * t * Math.PI * 2 * 0.5) * (0.4 + 0.6 * Math.sin(Math.PI * t));
            return perfil * ondas;
        };

        const pos = [], idx = [];
        const fila = puntos + 1;
        const anillo = (rFn, y) => {
            const inicio = pos.length / 3;
            for (let j = 0; j <= puntos; j++) {
                const a = (j / puntos) * Math.PI * 2;
                const r = rFn(a);
                pos.push(Math.cos(a) * r, y, -Math.sin(a) * r);
            }
            return inicio;
        };
        const unir = (a, b, invertir = false) => {
            for (let j = 0; j < puntos; j++) {
                const p0 = a + j, p1 = a + j + 1, p2 = b + j, p3 = b + j + 1;
                if (invertir) idx.push(p0, p1, p2, p1, p3, p2);
                else idx.push(p0, p2, p1, p1, p2, p3);
            }
        };

        // pared exterior
        const exterior = [];
        for (let i = 0; i <= anillos; i++) {
            const t = i / anillos;
            exterior.push(anillo((a) => radio(t, a), t * alto));
        }
        for (let i = 0; i < anillos; i++) unir(exterior[i], exterior[i + 1]);

        // pared interior (el fondo tiene 1,6 mm: ocho capas macizas)
        const fondo = 0.0016;
        const interior = [];
        for (let i = 0; i <= anillos; i++) {
            const t = i / anillos;
            const y = fondo + t * (alto - fondo);
            const te = y / alto;
            interior.push(anillo((a) => radio(te, a) - pared, y));
        }
        for (let i = 0; i < anillos; i++) unir(interior[i], interior[i + 1], true);

        // labio superior: une exterior e interior
        unir(exterior[anillos], interior[anillos], false);

        // tapas: fondo exterior (abajo) e interior (arriba del fondo), con sus
        // propios vértices para que el canto no promedie normales con la pared
        const tapa = (rFn, y, haciaArriba) => {
            const a0 = anillo(rFn, y);
            const centro = pos.length / 3;
            pos.push(0, y, 0);
            for (let j = 0; j < puntos; j++) {
                if (haciaArriba) idx.push(centro, a0 + j, a0 + j + 1);
                else idx.push(centro, a0 + j + 1, a0 + j);
            }
        };
        tapa((a) => radio(0, a), 0, false);
        tapa((a) => radio(fondo / alto, a) - pared, fondo, true);

        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
        geo.setIndex(idx);

        // UV: U da 6 vueltas al mosaico alrededor del jarrón; V sube una
        // vuelta cada 32 capas de 0,2 mm. Las capas las dibuja el mapa de normales.
        const n = pos.length / 3, uv = new Float32Array(n * 2), periodo = 0.0002 * 32;
        for (let i = 0; i < n; i++) {
            const x = pos[i * 3], y = pos[i * 3 + 1], z = pos[i * 3 + 2];
            const j = i % fila;
            const enPared = i < (exterior[anillos] + fila) || (i >= interior[0] && i < interior[anillos] + fila);
            uv[i * 2] = enPared ? (j / puntos) * 6 : (x - z) / periodo;
            uv[i * 2 + 1] = enPared ? y / periodo : (x + z) / periodo;
        }
        geo.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
        geo.computeVertexNormals();

        // La costura (primer y último punto de cada anillo) tiene dos vértices
        // por la UV; sin promediar su normal se vería una línea vertical.
        const nor = geo.attributes.normal;
        for (const inicio of [...exterior, ...interior]) {
            const a = inicio, b = inicio + puntos;
            const v = new THREE.Vector3(nor.getX(a) + nor.getX(b), nor.getY(a) + nor.getY(b), nor.getZ(a) + nor.getZ(b)).normalize();
            nor.setXYZ(a, v.x, v.y, v.z); nor.setXYZ(b, v.x, v.y, v.z);
        }

        const capas = textura(capasImpresion({ tam: 256, capas: 32, semilla: 9 }), { color: false, nombre: 'capas' });
        const material = new THREE.MeshPhysicalMaterial({
            name: 'PLA seda',
            color: '#d4a640',
            metalness: 0.45,
            roughness: 0.32,
            sheen: 0.4,
            sheenColor: new THREE.Color('#fff1c9'),
            sheenRoughness: 0.4,
            normalMap: capas,
            normalScale: new THREE.Vector2(0.9, 0.9),
            side: THREE.FrontSide,
        });

        const malla = new THREE.Mesh(geo, material);
        malla.name = 'Jarrón espiral';

        const raiz = new THREE.Group();
        raiz.name = 'Jarrón paramétrico';
        raiz.add(malla);
        return { escena: raiz, animaciones: [] };
    },
};
