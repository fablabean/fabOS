/*
 * Texturas procedurales para los modelos de la Galería 3D.
 *
 * Se dibujan en un <canvas> en lugar de traer fotografías: así cada modelo se
 * puede volver a generar desde este repositorio sin depender de archivos
 * sueltos, y el tamaño de cada textura lo decide el optimizador, no la foto.
 *
 * Las de detalle (capas de impresión, cepillado del metal) son mosaicos
 * pequeños que se repiten: 256 px cubren toda una pieza sin pesar nada.
 */
import * as THREE from 'three';

/** Generador pseudoaleatorio con semilla (mulberry32): mismas texturas en cada construcción. */
export function azar(semilla = 1) {
    let a = semilla >>> 0;
    return function () {
        a |= 0; a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Ruido de valor 2D, suave y que se repite cada `periodo` unidades (para mosaicos sin costuras). */
export function ruido(semilla = 7, periodo = 64) {
    const r = azar(semilla);
    const tabla = new Float32Array(periodo * periodo).map(() => r());
    const v = (x, y) => tabla[((y % periodo + periodo) % periodo) * periodo + ((x % periodo + periodo) % periodo)];
    const s = (t) => t * t * (3 - 2 * t);
    return function (x, y) {
        const xi = Math.floor(x), yi = Math.floor(y);
        const xf = s(x - xi), yf = s(y - yi);
        const a = v(xi, yi), b = v(xi + 1, yi), c = v(xi, yi + 1), d = v(xi + 1, yi + 1);
        return a + (b - a) * xf + (c - a) * yf + (a - b - c + d) * xf * yf;
    };
}

/** Ruido fractal (varias octavas) en [0,1]. */
export function fractal(n, x, y, octavas = 4) {
    let suma = 0, amp = 0.5, f = 1, total = 0;
    for (let i = 0; i < octavas; i++) {
        suma += n(x * f, y * f) * amp;
        total += amp; amp *= 0.5; f *= 2;
    }
    return suma / total;
}

export function lienzo(ancho, alto = ancho) {
    const c = document.createElement('canvas');
    c.width = ancho; c.height = alto;
    return c;
}

/** Recorre cada píxel: `fn(x, y)` devuelve [r, g, b] en 0..255. */
export function pintarPixeles(canvas, fn) {
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(canvas.width, canvas.height);
    const d = img.data;
    let i = 0;
    for (let y = 0; y < canvas.height; y++) {
        for (let x = 0; x < canvas.width; x++) {
            const [r, g, b] = fn(x, y);
            d[i++] = r; d[i++] = g; d[i++] = b; d[i++] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    return canvas;
}

/** Mapa de normales (espacio tangente, convención OpenGL/glTF) a partir de un mapa de alturas en gris. */
export function normalDesdeAltura(alturas, fuerza = 2) {
    const w = alturas.width, h = alturas.height;
    const src = alturas.getContext('2d').getImageData(0, 0, w, h).data;
    const H = (x, y) => src[(((y + h) % h) * w + ((x + w) % w)) * 4] / 255;
    const salida = lienzo(w, h);
    return pintarPixeles(salida, (x, y) => {
        const dx = (H(x + 1, y) - H(x - 1, y)) * fuerza;
        const dy = (H(x, y + 1) - H(x, y - 1)) * fuerza;
        // En el lienzo la Y crece hacia abajo; en glTF la V crece hacia arriba.
        const nx = -dx, ny = dy, nz = 1;
        const l = Math.hypot(nx, ny, nz);
        return [(nx / l * 0.5 + 0.5) * 255, (ny / l * 0.5 + 0.5) * 255, (nz / l * 0.5 + 0.5) * 255];
    });
}

const hex = (c) => new THREE.Color(c);
const mezclar = (a, b, t) => [
    (a.r + (b.r - a.r) * t) * 255,
    (a.g + (b.g - a.g) * t) * 255,
    (a.b + (b.b - a.b) * t) * 255,
];

/**
 * Envuelve un lienzo en una textura de three.
 * `color`: true para color base y emisión (sRGB); false para datos (normales, rugosidad).
 */
export function textura(canvas, { color = true, repetir = true, formato = 'image/jpeg', nombre = '' } = {}) {
    const t = new THREE.CanvasTexture(canvas);
    t.colorSpace = color ? THREE.SRGBColorSpace : THREE.NoColorSpace;
    if (repetir) t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.anisotropy = 4;
    t.name = nombre;
    t.userData.mimeType = formato;
    return t;
}

/* ------------------------------------------------------------------ */
/* Detalle de fabricación                                               */
/* ------------------------------------------------------------------ */

/**
 * Las capas de una impresión FDM: líneas horizontales de sección redondeada.
 * El mosaico tiene `capas` líneas; las UV de la pieza deciden cuántos milímetros mide.
 */
export function capasImpresion({ tam = 256, capas = 32, semilla = 3 } = {}) {
    const n = ruido(semilla, 32);
    const alto = lienzo(tam);
    const paso = tam / capas;
    pintarPixeles(alto, (x, y) => {
        const f = (y % paso) / paso;                       // posición dentro de la capa
        const perfil = Math.sin(f * Math.PI);              // la capa es un cordón redondeado
        const irregular = (n(x / 8, y / paso) - 0.5) * 0.25; // el extrusor no es perfecto
        const v = Math.max(0, Math.min(1, perfil * 0.85 + irregular + 0.1)) * 255;
        return [v, v, v];
    });
    return normalDesdeAltura(alto, 3.2);
}

/** Rugosidad del aluminio fresado/cepillado: vetas finas en una dirección. */
export function cepillado({ tam = 256, semilla = 11, base = 0.32, variacion = 0.18 } = {}) {
    const n = ruido(semilla, 64);
    const c = lienzo(tam);
    return pintarPixeles(c, (x, y) => {
        const veta = fractal(n, x / 64, y * 1.7, 3);
        const v = Math.max(0, Math.min(1, base + (veta - 0.5) * variacion * 2)) * 255;
        // glTF lee la rugosidad del canal G (y el metal del B): los dos van en el mismo mapa.
        return [255, v, 255];
    });
}

/**
 * Madera de chapa (abedul, nogal) o MDF. La veta corre a lo largo de V: líneas
 * largas, casi rectas, de poco contraste, con fibras finas entre ellas.
 */
export function madera({ tam = 1024, base = '#e6cfa9', veta = '#c9a678', semilla = 5, anillos = 9, mdf = false, contraste = 0.55 } = {}) {
    const n = ruido(semilla, 128);
    const a = hex(base), b = hex(veta);
    const c = lienzo(tam);
    return pintarPixeles(c, (x, y) => {
        const u = x / tam, v = y / tam;
        if (mdf) {
            const g = fractal(n, u * 90, v * 90, 3);
            return mezclar(a, b, 0.15 + g * 0.35);
        }
        // ondulación lenta a lo largo de la veta (la tabla no es un código de barras)
        const deformacion = (fractal(n, u * 2.2, v * 1.1, 3) - 0.5) * 1.6;
        const t = ((u * anillos + deformacion) % 1 + 1) % 1;
        const linea = Math.pow(1 - Math.abs(t * 2 - 1), 10);        // la línea de crecimiento, fina
        const zona = 0.5 + 0.5 * Math.sin(t * Math.PI * 2);         // madera temprana / tardía
        const fibras = fractal(n, u * 180, v * 6, 2);               // poros alargados
        const mancha = fractal(n, u * 3, v * 3, 3);
        const k = (linea * 0.75 + zona * 0.18 + fibras * 0.22 + mancha * 0.12) * contraste;
        return mezclar(a, b, Math.min(1, k));
    });
}

/** Canto de contrachapado: láminas alternas claras y oscuras, a lo largo del espesor. */
export function cantoContrachapado({ tam = 256, laminas = 5, semilla = 2, quemado = 0 } = {}) {
    const n = ruido(semilla, 32);
    const claro = hex('#e4c79c'), oscuro = hex('#a87a45'), carbon = hex('#3a2616');
    const c = lienzo(tam);
    return pintarPixeles(c, (x, y) => {
        const f = (y / tam) * laminas;
        const linea = Math.pow(Math.abs(Math.cos(f * Math.PI)), 18); // la cola entre láminas
        const grano = n(x / 6, y / 3) * 0.2;
        let col = mezclar(claro, oscuro, Math.min(1, linea * 0.8 + grano));
        if (quemado > 0) {
            const q = quemado * (0.75 + n(x / 4, y / 4) * 0.25);
            col = col.map((ch, i) => ch * (1 - q) + [carbon.r, carbon.g, carbon.b][i] * 255 * q);
        }
        return col;
    });
}

/** Tierra de maceta: granos marrones con piedritas. */
export function tierra({ tam = 512, semilla = 21 } = {}) {
    const n = ruido(semilla, 64);
    const r = azar(semilla);
    const a = hex('#3b2a1e'), b = hex('#6b4f37');
    const c = lienzo(tam);
    pintarPixeles(c, (x, y) => mezclar(a, b, fractal(n, x / 6, y / 6, 4)));
    const ctx = c.getContext('2d');
    for (let i = 0; i < tam * 1.2; i++) {
        const x = r() * tam, y = r() * tam, s = 1 + r() * 3;
        ctx.fillStyle = `hsl(${30 + r() * 15}, ${10 + r() * 20}%, ${45 + r() * 35}%)`;
        ctx.beginPath(); ctx.ellipse(x, y, s, s * (0.6 + r() * 0.4), r() * 3, 0, Math.PI * 2); ctx.fill();
    }
    return c;
}

/** Cerámica esmaltada con motas (para la maceta impresa con acabado tipo gres). */
export function ceramica({ tam = 512, base = '#e9e4da', mota = '#7a6f62', semilla = 31 } = {}) {
    const n = ruido(semilla, 64);
    const r = azar(semilla);
    const a = hex(base);
    const c = lienzo(tam);
    pintarPixeles(c, (x, y) => {
        const g = (fractal(n, x / 40, y / 40, 3) - 0.5) * 0.06;
        return [(a.r + g) * 255, (a.g + g) * 255, (a.b + g) * 255];
    });
    const ctx = c.getContext('2d');
    ctx.fillStyle = mota;
    for (let i = 0; i < tam * 2; i++) {
        ctx.globalAlpha = 0.3 + r() * 0.6;
        ctx.fillRect(r() * tam, r() * tam, 1 + r() * 1.6, 1 + r() * 1.6);
    }
    ctx.globalAlpha = 1;
    return c;
}

/** Texto en una línea, centrado, con la fuente del sistema. */
export function texto(ctx, cadena, x, y, { tam = 40, peso = 700, color = '#fff', alinear = 'center', fuente = 'system-ui, "Segoe UI", Arial, sans-serif', espaciado = 0 } = {}) {
    ctx.save();
    ctx.fillStyle = color;
    ctx.textAlign = alinear;
    ctx.textBaseline = 'middle';
    ctx.font = `${peso} ${tam}px ${fuente}`;
    if (espaciado) ctx.letterSpacing = `${espaciado}px`;
    ctx.fillText(cadena, x, y);
    ctx.restore();
}

/** Ícono de engranaje en 2D (para grabados y serigrafías). */
export function iconoEngranaje(ctx, cx, cy, radio, dientes = 10) {
    ctx.beginPath();
    for (let i = 0; i <= dientes * 4; i++) {
        const a = (i / (dientes * 4)) * Math.PI * 2;
        const fuera = (i % 4 === 1 || i % 4 === 2);
        const r = fuera ? radio : radio * 0.8;
        ctx.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
    }
    ctx.closePath();
    ctx.moveTo(cx + radio * 0.35, cy);
    ctx.arc(cx, cy, radio * 0.35, 0, Math.PI * 2, true);
    ctx.fill('evenodd');
}
