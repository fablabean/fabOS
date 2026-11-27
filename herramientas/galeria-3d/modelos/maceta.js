/*
 * Maceta hexagonal impresa en 3D con acabado de gres esmaltado, tierra y una
 * suculenta en roseta. Las hojas se construyen una vez y se repiten girando,
 * como en la planta (filotaxis con el ángulo áureo).
 */
import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { uvPlanar } from '../piezas.js';
import { ceramica, tierra, textura } from '../texturas.js';

export default {
    slug: 'maceta',
    texturaMax: 512,

    async crear() {
        const mCeramica = new THREE.MeshPhysicalMaterial({
            name: 'Esmalte tipo gres', map: textura(ceramica({ tam: 512 }), { nombre: 'ceramica' }),
            roughness: 0.45, metalness: 0, clearcoat: 0.7, clearcoatRoughness: 0.15,
        });
        const mTierra = new THREE.MeshStandardMaterial({ name: 'Tierra', map: textura(tierra({ tam: 512 }), { nombre: 'tierra' }), roughness: 0.95 });
        const mHoja = new THREE.MeshPhysicalMaterial({
            name: 'Hoja de suculenta', vertexColors: true, roughness: 0.42, metalness: 0,
            sheen: 0.6, sheenColor: new THREE.Color('#d9f1e2'), sheenRoughness: 0.5,
        });

        const raiz = new THREE.Group();
        raiz.name = 'Maceta con suculenta';

        // maceta: hexágono que se ensancha, con borde y pared
        const perfil = [
            [0, 0], [0.046, 0], [0.05, 0.004], [0.062, 0.1], [0.066, 0.104], [0.066, 0.11],
            [0.06, 0.11], [0.057, 0.106], [0.047, 0.01], [0, 0.01],
        ].map(([x, y]) => new THREE.Vector2(x, y));
        const maceta = new THREE.LatheGeometry(perfil, 6, Math.PI / 6);
        maceta.deleteAttribute('normal');
        const macetaPlana = maceta.toNonIndexed();
        macetaPlana.computeVertexNormals();
        uvPlanar(macetaPlana, 'x', 'y');
        const mMaceta = new THREE.Mesh(macetaPlana, mCeramica);
        mMaceta.name = 'Maceta hexagonal';
        raiz.add(mMaceta);

        const geoTierra = new THREE.CircleGeometry(0.058, 6, Math.PI / 6);
        geoTierra.rotateX(-Math.PI / 2);
        geoTierra.translate(0, 0.096, 0);
        uvPlanar(geoTierra, 'x', 'z');
        const mallaTierra = new THREE.Mesh(geoTierra, mTierra);
        mallaTierra.name = 'Tierra';
        raiz.add(mallaTierra);

        // una hoja: esfera estirada, en punta, con color de base a punta
        const hojaBase = () => {
            const g = new THREE.SphereGeometry(1, 20, 12);
            const p = g.attributes.position;
            const colores = new Float32Array(p.count * 3);
            const verde = new THREE.Color('#5f9f86'), punta = new THREE.Color('#c96d7a'), base = new THREE.Color('#a8cdb4');
            for (let i = 0; i < p.count; i++) {
                let x = p.getX(i), y = p.getY(i), z = p.getZ(i);
                const t = (x + 1) / 2;                        // 0 en la base, 1 en la punta
                const ancho = Math.sin(Math.PI * Math.pow(t, 0.7)) * (1 - t * 0.35);
                y *= ancho * 0.32; z *= ancho * 0.55;
                y += Math.pow(t, 2) * 0.25;                   // la punta se curva hacia arriba
                if (y > 0) y *= 0.7;                          // cara superior más plana
                p.setXYZ(i, x * 0.5 + 0.5, y, z);
                const c = t < 0.5 ? base.clone().lerp(verde, t * 2) : verde.clone().lerp(punta, Math.pow((t - 0.5) * 2, 2.5));
                colores.set([c.r, c.g, c.b], i * 3);
            }
            g.setAttribute('color', new THREE.BufferAttribute(colores, 3));
            g.deleteAttribute('uv');
            g.computeVertexNormals();
            return g;
        };
        const hoja = hojaBase();
        const hojas = [];
        const aureo = Math.PI * (3 - Math.sqrt(5));
        const total = 34;
        for (let i = 0; i < total; i++) {
            const t = i / (total - 1);                         // 0 = exterior, 1 = centro
            const largo = 0.05 * (1 - t * 0.72) + 0.008;
            const inclinacion = 0.15 + t * 1.15;                // las de dentro, más erguidas
            const m = new THREE.Matrix4()
                .makeRotationY(i * aureo)
                .multiply(new THREE.Matrix4().makeRotationZ(inclinacion))
                .multiply(new THREE.Matrix4().makeScale(largo, largo * 0.9, largo));
            const g = hoja.clone().applyMatrix4(m);
            g.translate(0, 0.1 + t * 0.006, 0);
            hojas.push(g);
        }
        const roseta = new THREE.Mesh(mergeGeometries(hojas), mHoja);
        roseta.name = 'Suculenta';
        raiz.add(roseta);

        return { escena: raiz, animaciones: [] };
    },
};
