/*
 * Cubo de calibración de 30 mm impreso en PLA: la pieza más simple que hay.
 * Doce triángulos —dos por cara—, el mínimo con el que se puede dibujar un
 * cubo. Las líneas de las capas van en la textura, no en la malla.
 */
import * as THREE from 'three';
import { uvImpresion } from '../piezas.js';
import { capasImpresion, textura } from '../texturas.js';

export default {
    slug: 'cubo',
    texturaMax: 256,

    async crear() {
        const lado = 0.03;   // 30 mm
        const capas = textura(capasImpresion({ tam: 256, capas: 32 }), { color: false, nombre: 'capas' });
        const material = new THREE.MeshStandardMaterial({
            name: 'PLA verde azulado', color: '#11897b', roughness: 0.55, metalness: 0,
            normalMap: capas, normalScale: new THREE.Vector2(0.6, 0.6),
        });

        // 6 caras × 2 triángulos = 12, apoyado en el suelo (base en y = 0)
        const geo = new THREE.BoxGeometry(lado, lado, lado);
        geo.translate(0, lado / 2, 0);
        const cubo = new THREE.Mesh(uvImpresion(geo), material);
        cubo.name = 'Cubo de 30 mm';

        const raiz = new THREE.Group();
        raiz.name = 'Cubo de calibración';
        raiz.add(cubo);
        return { escena: raiz, animaciones: [] };
    },
};
