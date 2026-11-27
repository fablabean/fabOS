/*
 * Soporte para celular impreso en 3D, de una sola pieza y sin soportes: se
 * imprime acostado sobre su costado, así que las capas corren a lo largo del
 * respaldo y no se parte al apoyar el teléfono. El celular va puesto para dar
 * la escala (no se imprime).
 */
import * as THREE from 'three';
import { capasImpresion, lienzo, textura, texto } from '../texturas.js';

const ANCHO = 0.07;          // a lo ancho del escritorio (eje Z)
const ESPESOR = 0.005;
const ANGULO = (68 * Math.PI) / 180;   // inclinación del respaldo

/** El perfil lateral del soporte (plano XY, X hacia delante), en sentido antihorario. */
function perfil() {
    const d = new THREE.Vector2(-Math.cos(ANGULO), Math.sin(ANGULO));   // a lo largo del respaldo
    const n = new THREE.Vector2(-Math.sin(ANGULO), -Math.cos(ANGULO));  // hacia atrás del respaldo
    const largo = 0.088;
    const f0 = new THREE.Vector2(0, ESPESOR);                           // pie del respaldo, delante
    const f1 = f0.clone().addScaledVector(d, largo);
    const b1 = f1.clone().addScaledVector(n, ESPESOR);
    const t = (b1.y - ESPESOR) / d.y;
    const b0 = b1.clone().addScaledVector(d, -t);                       // pie del respaldo, detrás

    const s = new THREE.Shape();
    s.moveTo(-0.062, 0);
    s.lineTo(0.019, 0);
    // labio delantero que frena el teléfono, con la punta redondeada
    s.lineTo(0.019, 0.012);
    s.absarc(0.0165, 0.012, 0.0025, 0, Math.PI, false);
    s.lineTo(0.014, ESPESOR);
    // el fondo de la cuna, redondeado contra el respaldo
    s.lineTo(f0.x + 0.004, f0.y);
    s.quadraticCurveTo(f0.x, f0.y, f0.x - 0.004 * Math.cos(ANGULO), f0.y + 0.004 * Math.sin(ANGULO));
    s.lineTo(f1.x, f1.y);
    // canto de arriba del respaldo, redondeado
    s.quadraticCurveTo(f1.x + n.x * ESPESOR * 0.5 + d.x * 0.003, f1.y + n.y * ESPESOR * 0.5 + d.y * 0.003, b1.x, b1.y);
    s.lineTo(b0.x - 0.004 * Math.cos(ANGULO), b0.y + 0.004 * Math.sin(ANGULO));
    s.quadraticCurveTo(b0.x, b0.y, b0.x - 0.006, ESPESOR);
    s.lineTo(-0.062, ESPESOR);
    s.closePath();
    return { forma: s, f0, d, n };
}

/** La pantalla del celular: la galería 3D abierta, para que se vea en qué se usa. */
function pantalla() {
    const c = lienzo(512, 1024);
    const ctx = c.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 0, 1024);
    g.addColorStop(0, '#0d6e63'); g.addColorStop(0.55, '#14897b'); g.addColorStop(1, '#0a4f47');
    ctx.fillStyle = g; ctx.fillRect(0, 0, 512, 1024);
    texto(ctx, '9:41', 256, 70, { tam: 34, peso: 600 });
    texto(ctx, 'FABLAB EAN', 256, 190, { tam: 30, peso: 800, espaciado: 6 });
    texto(ctx, 'Galería 3D', 256, 250, { tam: 54, peso: 800 });
    // una tarjeta con un cubo dibujado en isométrico
    ctx.fillStyle = 'rgba(255,255,255,0.92)';
    ctx.beginPath(); ctx.roundRect(56, 330, 400, 440, 36); ctx.fill();
    const cubo = (x, y, s) => {
        ctx.fillStyle = '#f08a24'; ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + s, y - s * 0.58); ctx.lineTo(x, y - s * 1.16); ctx.lineTo(x - s, y - s * 0.58); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#c96a12'; ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x - s, y - s * 0.58); ctx.lineTo(x - s, y + s * 0.58); ctx.lineTo(x, y + s * 1.16); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#e07c1a'; ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + s, y - s * 0.58); ctx.lineTo(x + s, y + s * 0.58); ctx.lineTo(x, y + s * 1.16); ctx.closePath(); ctx.fill();
    };
    cubo(256, 560, 110);
    texto(ctx, 'Míralo en tu espacio', 256, 860, { tam: 34, peso: 700 });
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.beginPath(); ctx.roundRect(186, 980, 140, 8, 4); ctx.fill();
    return c;
}

export default {
    slug: 'soporte-celular',
    texturaMax: 1024,

    async crear() {
        const capas = textura(capasImpresion({ tam: 256, capas: 32, semilla: 13 }), { color: false, nombre: 'capas' });
        const mPla = new THREE.MeshStandardMaterial({
            name: 'PLA verde', color: '#1f9e8a', roughness: 0.5, metalness: 0,
            normalMap: capas, normalScale: new THREE.Vector2(0.7, 0.7),
        });
        const mCarcasa = new THREE.MeshStandardMaterial({ name: 'Carcasa del celular', color: '#24262b', roughness: 0.35, metalness: 0.4 });
        const mPantalla = new THREE.MeshStandardMaterial({
            name: 'Pantalla', color: '#000000', roughness: 0.08, metalness: 0,
            emissive: '#ffffff', emissiveMap: textura(pantalla(), { repetir: false, nombre: 'pantalla' }), emissiveIntensity: 0.9,
        });

        const raiz = new THREE.Group();
        raiz.name = 'Soporte para celular';

        const { forma, f0, d, n } = perfil();
        const geo = new THREE.ExtrudeGeometry(forma, {
            depth: ANCHO - 0.002, bevelEnabled: true, bevelThickness: 0.001, bevelSize: 0.0008, bevelSegments: 3, curveSegments: 14,
        });
        // el bisel crece 0,8 mm hacia fuera: se sube para que apoye en y = 0
        geo.translate(0, 0.0008, -(ANCHO - 0.002) / 2);
        // UV para las capas: se imprime de costado, las capas avanzan a lo ancho (Z)
        const p = geo.attributes.position;
        const uv = new Float32Array(p.count * 2);
        for (let i = 0; i < p.count; i++) { uv[i * 2] = (p.getX(i) + p.getY(i)) / 0.0128; uv[i * 2 + 1] = p.getZ(i) / 0.0064; }
        geo.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
        const soporte = new THREE.Mesh(geo, mPla);
        soporte.name = 'Soporte';
        raiz.add(soporte);

        // el celular, apoyado en el respaldo y en la cuna
        const alto = 0.15, anchoCel = 0.072, grueso = 0.008;
        const celular = new THREE.Group();
        celular.name = 'Celular';
        const cuerpo = new THREE.Mesh(new THREE.BoxGeometry(grueso, alto, anchoCel, 1, 1, 1), mCarcasa);
        cuerpo.name = 'Carcasa';
        celular.add(cuerpo);
        // El plano trae sus UV (U a lo ancho, V hacia arriba) y mira a +Z; al
        // girarlo para que mire hacia delante (+X), U sigue yendo de izquierda
        // a derecha según se mira la pantalla.
        const geoPantalla = new THREE.PlaneGeometry(anchoCel - 0.004, alto - 0.006);
        geoPantalla.rotateY(Math.PI / 2);
        geoPantalla.translate(grueso / 2 + 0.0002, 0, 0);
        const vidrio = new THREE.Mesh(geoPantalla, mPantalla);
        vidrio.name = 'Pantalla';
        celular.add(vidrio);
        // Se inclina como el respaldo: su cara trasera, paralela y pegada a él.
        celular.rotation.z = Math.PI / 2 - ANGULO;
        const centro = f0.clone().addScaledVector(d, alto / 2 + 0.0015).addScaledVector(n, -(grueso / 2 + 0.0003));
        celular.position.set(centro.x, centro.y + 0.0008, 0);
        raiz.add(celular);

        return { escena: raiz, animaciones: [] };
    },
};
