/**
 * Qué tanto aguanta este teléfono o computador: gama 'baja', 'media' o 'alta'.
 *
 * La usan la galería (visor.js) y la página de realidad aumentada (ra.js)
 * para decidir resolución, sombras y, en Android, cómo abrir la realidad
 * aumentada:
 *
 *   baja  → Scene Viewer, la app de realidad aumentada de Google. Corre fuera
 *           del navegador y es mucho más liviana y estable que dibujar en la
 *           página; un teléfono modesto la mueve sin tirones.
 *   media → la cámara en la página (WebXR), al 75 % de resolución.
 *   alta  → la cámara en la página a resolución completa.
 *
 * En iPhone la realidad aumentada es la Vista rápida de Apple, nativa: la
 * gama solo cuenta para la vista 3D de la página.
 *
 * Se mira la memoria y los núcleos (Chrome los informa) y el chip gráfico; la
 * gama es la más baja de las tres. Si en una sesión de realidad aumentada el
 * teléfono no da los cuadros, se recuerda (recordarLento) y la próxima vez se
 * usa la gama de abajo. Para probar: ?gama=baja|media|alta en la dirección.
 */

const ORDEN = ['baja', 'media', 'alta'];
const CLAVE_LENTO = 'fabos-ra-lento';

/** El nombre del chip gráfico («Adreno (TM) 610», «Mali-G52 MC2»…), si se deja leer. */
function chipGrafico() {
    try {
        const lienzo = document.createElement('canvas');
        const gl = lienzo.getContext('webgl');
        if (!gl) return '';
        const info = gl.getExtension('WEBGL_debug_renderer_info');
        const nombre = String((info && gl.getParameter(info.UNMASKED_RENDERER_WEBGL)) || gl.getParameter(gl.RENDERER) || '');
        gl.getExtension('WEBGL_lose_context')?.loseContext();   // no dejar un contexto de más abierto
        return nombre;
    } catch (e) {
        return '';
    }
}

/** Gama según el chip; null si no se reconoce (no limita). */
function gamaDelChip(chip) {
    if (!chip) return null;
    // Chips de teléfonos de entrada o de hace muchos años.
    if (/Mali-(4\d\d|T\d{3}|G31|G51|G52)\b|Adreno \(TM\) ([1-4]\d\d|50\d|51\d)\b|PowerVR (SGX|Rogue G[EM]?\d|GE\d)|PowerVR Rogue GE/i.test(chip)) return 'baja';
    // Gama media de los últimos años.
    if (/Mali-(G57|G68|G71|G72|G76)\b|Adreno \(TM\) (53\d|54\d|6[0-4]\d)\b|PowerVR (B-Series )?BXM/i.test(chip)) return 'media';
    return 'alta';
}

function calcular() {
    const forzada = new URLSearchParams(window.location.search).get('gama');
    if (ORDEN.includes(forzada)) return { gama: forzada, motivo: 'forzada en la dirección', chip: '' };

    const limites = [];
    const memoria = navigator.deviceMemory;          // GB, solo Chrome (máximo 8)
    if (memoria > 0) limites.push([memoria <= 2 ? 'baja' : memoria <= 4 ? 'media' : 'alta', `memoria ${memoria} GB`]);
    const nucleos = navigator.hardwareConcurrency;
    if (nucleos > 0) limites.push([nucleos <= 2 ? 'baja' : nucleos <= 4 ? 'media' : 'alta', `${nucleos} núcleos`]);
    const chip = chipGrafico();
    const porChip = gamaDelChip(chip);
    if (porChip) limites.push([porChip, chip]);

    let [gama, motivo] = limites.reduce((a, b) => (ORDEN.indexOf(b[0]) < ORDEN.indexOf(a[0]) ? b : a), ['alta', 'sin límites conocidos']);

    // Una sesión anterior fue lenta en este teléfono: un escalón abajo.
    // Se olvida a los 7 días: un teléfono caliente o con mucho abierto no es un teléfono lento.
    let lento = false;
    try {
        const cuando = Number(localStorage.getItem(CLAVE_LENTO));
        lento = cuando > 0 && Date.now() - cuando < 7 * 86_400_000;
    } catch (e) { /* sin almacenamiento */ }
    if (lento && gama !== 'baja') {
        gama = ORDEN[ORDEN.indexOf(gama) - 1];
        motivo += ' · la última vez fue lenta';
    }
    return { gama, motivo, chip };
}

const calculado = calcular();

export const perfil = {
    ...calculado,
    /** «Ahorro de datos» activado: no bajar nada por adelantado. */
    ahorroDatos: !!navigator.connection?.saveData,
};

/** Lo que se ajusta en cada gama. */
export const ajustes = {
    baja:  { pixelRatio: 1,   antialias: false, sombra: 512,  webxr: false, escalaXR: 0.6 },
    media: { pixelRatio: 1.5, antialias: true,  sombra: 1024, webxr: true,  escalaXR: 0.75 },
    alta:  { pixelRatio: 2,   antialias: true,  sombra: 1024, webxr: true,  escalaXR: 1 },
}[perfil.gama];

/** La sesión no dio los cuadros: la próxima vez, un escalón abajo. */
export function recordarLento() {
    try { localStorage.setItem(CLAVE_LENTO, String(Date.now())); } catch (e) { /* sin almacenamiento */ }
}

/**
 * Cuenta los cuadros de una sesión de realidad aumentada. Después de unos
 * segundos con la figura puesta, si el promedio no llega a ~22 cuadros por
 * segundo, avisa una sola vez (alLento) para que la sesión aliviane lo que
 * pueda, y lo recuerda para la próxima.
 */
export class MedidorDeCuadros {
    constructor(alLento) {
        this.alLento = alLento;
        this.tiempos = [];
        this.avisado = false;
    }

    cuadro(dt, contar) {
        if (this.avisado || !contar || !(dt > 0)) return;
        this.tiempos.push(dt);
        if (this.tiempos.length < 120) return;          // unos segundos
        const promedio = this.tiempos.reduce((a, b) => a + b, 0) / this.tiempos.length;
        this.tiempos.length = 0;
        if (promedio > 1 / 22) {
            this.avisado = true;
            recordarLento();
            try { this.alLento?.(promedio); } catch (e) { console.warn(e); }
        }
    }
}
