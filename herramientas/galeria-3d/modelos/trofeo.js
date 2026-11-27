/*
 * Trofeo del reto maker, impreso en resina transparente (SLA) sobre una base
 * negra con placa grabada. La resina usa transmisión y volumen (refracción
 * real en el visor); en la Vista rápida de iOS se ve como un cristal tintado.
 */
import * as THREE from 'three';
import { uvPlanar } from '../piezas.js';
import { lienzo, textura, texto, iconoEngranaje, azar } from '../texturas.js';

export default {
    slug: 'trofeo',
    texturaMax: 512,

    async crear() {
        const mResina = new THREE.MeshPhysicalMaterial({
            name: 'Resina transparente',
            color: '#bfe9ff',
            metalness: 0,
            roughness: 0.06,
            transmission: 1,
            thickness: 0.018,
            ior: 1.52,
            attenuationColor: new THREE.Color('#37b9e6'),
            attenuationDistance: 0.06,
            specularIntensity: 1,
        });
        const mBase = new THREE.MeshPhysicalMaterial({ name: 'Base negra lacada', color: '#151618', roughness: 0.25, metalness: 0, clearcoat: 1, clearcoatRoughness: 0.08 });

        // la placa: latón cepillado con el texto grabado
        const c = lienzo(512, 256);
        const ctx = c.getContext('2d');
        const g = ctx.createLinearGradient(0, 0, 512, 256);
        g.addColorStop(0, '#d8b45a'); g.addColorStop(0.5, '#f0d68a'); g.addColorStop(1, '#b8913c');
        ctx.fillStyle = g; ctx.fillRect(0, 0, 512, 256);
        const r = azar(4);
        ctx.globalAlpha = 0.08;
        for (let i = 0; i < 400; i++) { ctx.fillStyle = r() > 0.5 ? '#fff' : '#000'; ctx.fillRect(0, r() * 256, 512, 1); }
        ctx.globalAlpha = 1;
        ctx.fillStyle = '#3a2a0c';
        iconoEngranaje(ctx, 72, 128, 40, 10);
        texto(ctx, 'RETO MAKER 2026', 290, 96, { tam: 46, peso: 800, color: '#3a2a0c' });
        texto(ctx, 'Primer puesto · EAN FabLab', 290, 160, { tam: 28, peso: 600, color: '#3a2a0c' });
        const mPlaca = new THREE.MeshStandardMaterial({ name: 'Placa de latón grabada', map: textura(c, { nombre: 'placa', repetir: false }), metalness: 0.95, roughness: 0.35 });

        const raiz = new THREE.Group();
        raiz.name = 'Trofeo reto maker';

        // base escalonada
        const escalon = (w, h, y, nombre) => {
            const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, w), mBase);
            m.position.y = y + h / 2;
            m.name = nombre;
            raiz.add(m);
            return m;
        };
        escalon(0.085, 0.018, 0, 'Base inferior');
        escalon(0.068, 0.014, 0.018, 'Base superior');

        const placaGeo = new THREE.BoxGeometry(0.056, 0.012, 0.0012);
        uvPlanar(placaGeo, 'x', 'y', { invertirV: false });
        const placa = new THREE.Mesh(placaGeo, mPlaca);
        placa.name = 'Placa';
        placa.position.set(0, 0.009, 0.085 / 2 + 0.0006);
        raiz.add(placa);

        // la figura: estrella de 5 puntas que se retuerce y se afina al subir,
        // con caras planas (facetas) como las que deja la impresión en resina
        const puntas = 5, anillos = 48, alto = 0.15, y0 = 0.032;
        const pos = [];
        const anillo = (t) => {
            const giro = t * Math.PI * 0.8;
            const escala = 0.032 * (1 - t * 0.55) * (0.85 + 0.15 * Math.sin(t * Math.PI));
            const pts = [];
            for (let k = 0; k < puntas * 2; k++) {
                const a = (k / (puntas * 2)) * Math.PI * 2 + giro;
                const r = (k % 2 === 0 ? 1 : 0.48) * escala;
                pts.push(new THREE.Vector3(Math.cos(a) * r, y0 + t * alto, -Math.sin(a) * r));
            }
            return pts;
        };
        const anillosPts = [];
        for (let i = 0; i <= anillos; i++) anillosPts.push(anillo(i / anillos));
        const tri = (a, b, c2) => pos.push(a.x, a.y, a.z, b.x, b.y, b.z, c2.x, c2.y, c2.z);
        const n = puntas * 2;
        for (let i = 0; i < anillos; i++) {
            for (let k = 0; k < n; k++) {
                const a = anillosPts[i][k], b = anillosPts[i][(k + 1) % n];
                const c2 = anillosPts[i + 1][k], d = anillosPts[i + 1][(k + 1) % n];
                tri(a, b, c2); tri(b, d, c2);
            }
        }
        // tapa de arriba en punta y tapa de abajo plana
        const cima = new THREE.Vector3(0, y0 + alto + 0.012, 0);
        const fondo = new THREE.Vector3(0, y0, 0);
        for (let k = 0; k < n; k++) {
            tri(anillosPts[anillos][k], anillosPts[anillos][(k + 1) % n], cima);
            tri(anillosPts[0][(k + 1) % n], anillosPts[0][k], fondo);
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
        geo.computeVertexNormals();          // sin índices: normales planas, facetas nítidas
        uvPlanar(geo, 'x', 'y');
        const figura = new THREE.Mesh(geo, mResina);
        figura.name = 'Estrella de resina';
        raiz.add(figura);

        return { escena: raiz, animaciones: [] };
    },
};
