/*
 * El taller: arma cada modelo, lo optimiza y saca los archivos de la galería.
 *
 *   {slug}.glb      para el visor web: optimizado y con geometría meshopt
 *   {slug}-ar.glb   para la realidad aumentada de Android (Scene Viewer), sin
 *                   extensiones de compresión, que no todas las apps leen
 *   {slug}.usdz     para la Vista rápida de iOS (AR Quick Look)
 *   {slug}.webp     miniatura con fondo transparente para la tarjeta
 *
 * Lo ejecuta construir.py en un Chromium sin ventana; también se puede abrir
 * a mano (taller.html) y llamar a construir('jarron') desde la consola.
 */
import { empaquetar } from '../../public/3d/procesar/empaquetar.js';
import { aLowPoly } from './lowpoly.js';

import engranajes from './modelos/engranajes.js';
import jarron from './modelos/jarron.js';
import trofeo from './modelos/trofeo.js';
import maceta from './modelos/maceta.js';
import tornillo from './modelos/tornillo.js';
import pez from './modelos/pez.js';
import impresion from './modelos/impresion.js';
import soporteCelular from './modelos/soporte-celular.js';
import ajedrez from './modelos/ajedrez.js';
import litofania from './modelos/litofania.js';
import cubo from './modelos/cubo.js';

export const MODELOS = Object.fromEntries(
    [engranajes, jarron, trofeo, maceta, tornillo, pez, impresion, soporteCelular, ajedrez, litofania, cubo].map((m) => [m.slug, m]),
);

const aBase64 = (buf) => {
    const u8 = new Uint8Array(buf);
    let s = '';
    for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
    return btoa(s);
};

/* ------------------------------------------------------------------ */
/* Construcción de un modelo                                            */
/* ------------------------------------------------------------------ */

window.construir = async function (slug) {
    const def = MODELOS[slug];
    if (!def) throw new Error(`No hay modelo «${slug}»`);
    const { escena, animaciones } = await def.crear();
    // Estilo de la galería: low poly, caras planas y colores sólidos (lowpoly.js).
    const lowpoly = def.lowpoly === false ? null : await aLowPoly(escena, def.lowpoly || {});
    const r = await empaquetar(escena, animaciones, { texturaMax: def.texturaMax, simplificacion: def.simplificacion });
    const { glb, glbAR, usdz, poster } = r.archivos;
    return {
        archivos: {
            [`${slug}.glb`]: aBase64(glb),
            [`${slug}-ar.glb`]: aBase64(glbAR),
            [`${slug}.usdz`]: aBase64(usdz),
            [`${slug}.webp`]: aBase64(await poster.arrayBuffer()),
        },
        estadisticas: r.estadisticas,
        lowpoly,
    };
};

window.modelos = Object.keys(MODELOS);
window.listo = true;
