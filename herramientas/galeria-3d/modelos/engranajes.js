/*
 * Caja de engranajes planetaria, impresa en PLA.
 *
 * Sol de 12 dientes, tres planetas de 12 y corona de 36 (módulo 2,5 mm):
 * 36 = 12 + 2·12, y (12+36)/3 es entero, así que los tres planetas engranan
 * a la vez. La animación respeta la relación real: por cada 4 vueltas del
 * sol, el portasatélites da 1 y cada planeta gira −2 sobre sí mismo.
 */
import * as THREE from 'three';
import { contornoDientes, circulo, hexagono, extruirVertical, uvImpresion, pistaGiro } from '../piezas.js';
import { capasImpresion, textura } from '../texturas.js';

export default {
    slug: 'engranajes',
    texturaMax: 512,

    async crear() {
        const m = 0.0025;                     // módulo
        const S = 12, P = 12, R = 36;
        const rS = (m * S) / 2, rP = (m * P) / 2, rR = (m * R) / 2;
        const alto = 0.008;
        const juego = 0.00015;                // holgura de impresión entre dientes

        const capas = textura(capasImpresion({ tam: 256, capas: 32 }), { color: false, nombre: 'capas' });
        const pla = (nombre, color, rug = 0.55) => new THREE.MeshStandardMaterial({
            name: nombre, color, roughness: rug, metalness: 0,
            normalMap: capas, normalScale: new THREE.Vector2(0.6, 0.6),
        });
        const mSol = pla('PLA naranja', '#f08a24');
        const mPlaneta = pla('PLA verde azulado', '#11897b');
        const mCorona = pla('PLA gris grafito', '#3b3f45', 0.6);
        const mPortador = pla('PLA blanco', '#eeeeea', 0.5);
        const mEje = new THREE.MeshStandardMaterial({ name: 'Acero del eje', color: '#c9ccd1', metalness: 1, roughness: 0.28 });

        const raiz = new THREE.Group();
        raiz.name = 'Caja planetaria';

        const malla = (geo, mat, nombre) => {
            const g = mat.normalMap ? uvImpresion(geo) : geo;
            const me = new THREE.Mesh(g, mat);
            me.name = nombre;
            return me;
        };

        // --- Base y corona (fija) ---
        const base = new THREE.Shape(circulo(rR + 0.0125, 96));
        base.holes.push(new THREE.Path(circulo(0.004, 24)));
        const mBase = malla(extruirVertical(base, 0.004), mCorona, 'Base');
        raiz.add(mBase);

        const corona = new THREE.Shape(circulo(rR + 0.0085, 96));
        corona.holes.push(new THREE.Path(contornoDientes(R, rR - m + juego, rR + 1.25 * m, { anchoPunta: 0.6, anchoRaiz: 1.25, pasos: 3 })));
        const mallaCorona = malla(extruirVertical(corona, alto), mCorona, 'Corona');
        mallaCorona.position.y = 0.004;
        raiz.add(mallaCorona);

        const altura = 0.004 + 0.0005;        // los engranajes flotan medio milímetro sobre la base

        // --- Sol, con su eje hexagonal y la manivela ---
        const sol = new THREE.Group();
        sol.name = 'Sol';
        sol.position.y = altura;
        const formaSol = new THREE.Shape(contornoDientes(S, rS + m - juego, rS - 1.25 * m, { pasos: 4 }));
        formaSol.holes.push(new THREE.Path(hexagono(0.0032)));
        sol.add(malla(extruirVertical(formaSol, alto), mSol, 'Engranaje sol'));
        const eje = new THREE.Mesh(new THREE.CylinderGeometry(0.0031, 0.0031, 0.034, 6), mEje);
        eje.name = 'Eje de entrada';
        eje.position.y = 0.012;
        eje.rotation.y = Math.PI / 6;         // las caras del hexágono, alineadas con el agujero
        sol.add(eje);
        const brazo = new THREE.Shape();
        brazo.absarc(0, 0, 0.0065, Math.PI / 2, Math.PI * 1.5, false);
        brazo.lineTo(0.026, -0.0045);
        brazo.absarc(0.026, 0, 0.0045, -Math.PI / 2, Math.PI / 2, false);
        brazo.lineTo(0, 0.0065);
        brazo.holes.push(new THREE.Path(hexagono(0.0032)));
        const manivela = malla(extruirVertical(brazo, 0.004), mSol, 'Manivela');
        manivela.position.y = 0.026;
        sol.add(manivela);
        const pomo = malla(new THREE.CylinderGeometry(0.0035, 0.004, 0.014, 24), mPortador, 'Pomo');
        pomo.position.set(0.026, 0.037, 0);
        sol.add(pomo);
        raiz.add(sol);

        // --- Portasatélites con los tres planetas ---
        const portador = new THREE.Group();
        portador.name = 'Portasatelites';
        portador.position.y = altura;
        const rOrbita = rS + rP;
        const fase = Math.PI / P;             // medio diente: así engranan con sol y corona a la vez
        const formaPlaneta = new THREE.Shape(contornoDientes(P, rP + m - juego, rP - 1.25 * m, { pasos: 4 }));
        formaPlaneta.holes.push(new THREE.Path(circulo(0.0022, 24)));
        const geoPlaneta = uvImpresion(extruirVertical(formaPlaneta, alto));
        const geoPasador = new THREE.CylinderGeometry(0.0021, 0.0021, alto + 0.004, 20);
        for (let i = 0; i < 3; i++) {
            const th = (i * 2 * Math.PI) / 3;
            const pivote = new THREE.Group();
            pivote.name = `Planeta ${i + 1}`;
            pivote.position.set(Math.cos(th) * rOrbita, 0, -Math.sin(th) * rOrbita);
            pivote.rotation.y = fase;
            const engranaje = new THREE.Mesh(geoPlaneta, mPlaneta);   // los tres comparten geometría
            engranaje.name = `Engranaje planeta ${i + 1}`;
            pivote.add(engranaje);
            portador.add(pivote);

            const pasador = new THREE.Mesh(geoPasador, mEje);
            pasador.name = `Pasador ${i + 1}`;
            pasador.position.set(pivote.position.x, alto / 2 + 0.002, pivote.position.z);
            portador.add(pasador);
        }
        // la araña de tres brazos que une los pasadores, por encima
        const arana = new THREE.Shape(circulo(0.0075, 48));
        const brazos = [];
        for (let i = 0; i < 3; i++) {
            const th = (i * 2 * Math.PI) / 3;
            const b = new THREE.Shape();
            const c = Math.cos(th), s = Math.sin(th);
            const px = -s, py = c;            // perpendicular
            const w = 0.0042;
            b.moveTo(px * w, py * w);
            b.lineTo(c * rOrbita + px * w, s * rOrbita + py * w);
            b.absarc(c * rOrbita, s * rOrbita, w, th + Math.PI / 2, th - Math.PI / 2, true);
            b.lineTo(-px * w, -py * w);
            b.closePath();
            brazos.push(b);
        }
        const geoArana = [arana, ...brazos].map((f) => extruirVertical(f, 0.003, { bisel: 0.0003 }));
        geoArana.forEach((g, i) => {
            const me = malla(g, mPortador, i === 0 ? 'Portasatelites centro' : `Brazo ${i}`);
            me.position.y = alto + 0.001;
            portador.add(me);
        });
        raiz.add(portador);

        // --- Animación: 8 s por ciclo ---
        const T = 8;
        const clip = new THREE.AnimationClip('Girar la manivela', T, [
            pistaGiro('Sol', 4, T),
            pistaGiro('Portasatelites', 1, T),
            ...[0, 1, 2].map((i) => pistaGiro(`Planeta ${i + 1}`, -3, T, { inicial: fase })),
        ]);

        return { escena: raiz, animaciones: [clip] };
    },
};
