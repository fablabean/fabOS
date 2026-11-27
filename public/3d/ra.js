/*
 * La experiencia de realidad aumentada de una figura (/galeria-3d/{figura}/ra),
 * a donde lleva el código QR que se pone junto a la pieza.
 *
 * Arriba, la figura girando con su animación (three.js, el mismo de la
 * galería); abajo, un solo botón. Los navegadores no dejan abrir la cámara sin
 * que la persona toque algo, por eso no se abre sola:
 *
 *  - iPhone/iPad: Vista rápida de Apple con el .usdz. Dentro de otra app
 *    (Google, WhatsApp, Instagram, algunos lectores de QR) iOS no deja abrir
 *    la Vista rápida: modo cámara aquí mismo y un enlace para abrir en Safari.
 *  - Android, por orden (la gama del teléfono la decide capacidad.js):
 *      1. WebXR en la propia página (Chrome con ARCore), en gama media y
 *         alta: no depende de ninguna otra app y conserva la animación.
 *         En gama baja se salta: Scene Viewer es nativo y mucho más liviano.
 *      2. Scene Viewer de Google con el -ar.glb.
 *      3. Si a los pocos segundos nada se abrió (pasa cuando el lector de QR
 *         abre el enlace en su navegador interno, o falta la app de Google o
 *         los Servicios de Google Play para RA), se explica qué hacer: verla
 *         con la cámara, abrir en Chrome o instalar los servicios de RA.
 *      4. Modo cámara, para los Android sin ARCore (muchos de gama baja y
 *         media, como el Honor X8a, no están en la lista de Google): sin
 *         ARCore no hay realidad aumentada con seguimiento del piso, ni en
 *         Chrome ni en Scene Viewer. La figura se pone sobre la imagen en
 *         vivo de la cámara trasera y se acomoda con los dedos. Chrome dice
 *         si el teléfono tiene ARCore (WebXR): si no lo tiene, va directo aquí.
 *  - Computador: no hay botón; se muestra el QR para seguir en el teléfono.
 */
import * as THREE from 'three';
import { perfil, ajustes, MedidorDeCuadros } from 'fabos/capacidad';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';

const $ = (id) => document.getElementById(id);
const datos = JSON.parse($('ra-datos').textContent);
const urlAbsoluta = (u) => new URL(u, window.location.href).href;

/* ------------------------------------------------------------------ */
/* Qué puede este aparato                                               */
/* ------------------------------------------------------------------ */

const ua = navigator.userAgent;
const AR = {
    ios: (() => { const a = document.createElement('a'); return !!(a.relList && a.relList.supports && a.relList.supports('ar')); })(),
    android: /android/i.test(ua),
    webxr: false,
    // El navegador implementa WebXR (Chrome, Samsung Internet…) y contestó:
    // entonces «no» quiere decir que el teléfono no tiene ARCore.
    xrContesto: false,
};
// Un iPhone o iPad (los iPad nuevos se presentan como Mac con pantalla táctil).
const esIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
// iPhone sin Vista rápida: la página está dentro de otra app.
AR.iosSinVistaRapida = esIOS && !AR.ios;
const esTelefono = AR.ios || AR.android || esIOS;
// Chrome «de verdad» en Android. Los navegadores internos de los lectores de
// QR, de Google Lens, de las redes sociales o el WebView no abren otras apps.
// Brave se presenta igual que Chrome, pero se reconoce por navigator.brave.
// Puede traer WebXR bloqueado: su «no» no significa que falte ARCore.
const esBrave = !!navigator.brave;
const esChrome = AR.android && !esBrave && /Chrome\/\d+/.test(ua) && !/; wv\)|Version\/\d|GSA\/|SamsungBrowser|EdgA|OPR\/|MiuiBrowser|FBAN|FBAV|Instagram|Line\//.test(ua);
// Dentro de otra app (lector de QR, Lens, redes sociales). Samsung Internet,
// el navegador de Huawei/Honor, Edge u Opera son navegadores normales.
const enOtraApp = AR.android && /; wv\)|Version\/4\.0 Chrome|GSA\/|FBAN|FBAV|Instagram|Line\/|WhatsApp|TikTok|musical_ly/.test(ua);

let deteccionXR = Promise.resolve();
async function detectarWebXR() {
    try {
        if (window.isSecureContext && navigator.xr) {
            AR.webxr = !!(await navigator.xr.isSessionSupported('immersive-ar'));
            AR.xrContesto = true;
        }
    } catch (e) { AR.webxr = false; }
}

function avisar(texto) {
    const a = $('ra-aviso');
    a.textContent = texto;
    a.hidden = false;
}

/* ------------------------------------------------------------------ */
/* Compatibilidad: qué tiene este celular y qué necesita                */
/* ------------------------------------------------------------------ */

/**
 * Revisa, sin pedir nada a la persona, lo que la realidad aumentada necesita
 * en este celular: sistema, navegador, ARKit o ARCore, cámara, sensores,
 * gráficos, conexión segura y el permiso de la cámara. Devuelve cada punto
 * (ok, no, aviso o duda) y el nivel: «completa» (realidad aumentada de
 * verdad), «probable» (no se puede saber sin intentarlo), «camara» (el modo
 * cámara) o «ninguna».
 */
function versionDe(re) {
    const m = ua.match(re);
    return m ? parseFloat(`${m[1]}.${m[2] || 0}`) : null;
}
function nombreNavegador() {
    if (esIOS) {
        if (esBrave) return { nombre: 'Brave para iPhone', version: null, tipo: 'brave-ios' };
        if (/CriOS\//.test(ua)) return { nombre: 'Chrome para iPhone', version: versionDe(/CriOS\/(\d+)/), tipo: 'chrome-ios' };
        if (/FxiOS\//.test(ua)) return { nombre: 'Firefox para iPhone', version: versionDe(/FxiOS\/(\d+)/), tipo: 'otro-ios' };
        if (/GSA\//.test(ua)) return { nombre: 'la app de Google', version: null, tipo: 'app' };
        if (/FBAN|FBAV|Instagram|WhatsApp|Line\/|TikTok|musical_ly/.test(ua)) return { nombre: 'el navegador de otra app (red social o mensajería)', version: null, tipo: 'app' };
        if (/Version\/[\d.]+.*Safari/.test(ua)) return { nombre: 'Safari', version: versionDe(/Version\/(\d+)\.?(\d+)?/), tipo: 'safari' };
        return { nombre: 'el navegador de otra app', version: null, tipo: 'app' };
    }
    if (/SamsungBrowser\//.test(ua)) return { nombre: 'Samsung Internet', version: versionDe(/SamsungBrowser\/(\d+)/), tipo: 'samsung' };
    if (enOtraApp) return { nombre: 'el navegador de otra app (lector de QR, red social o mensajería)', version: null, tipo: 'app' };
    if (esBrave) return { nombre: 'Brave', version: versionDe(/Chrome\/(\d+)/), tipo: 'brave' };
    if (esChrome) return { nombre: 'Chrome', version: versionDe(/Chrome\/(\d+)/), tipo: 'chrome' };
    if (/HuaweiBrowser\//.test(ua)) return { nombre: 'el navegador de Huawei', version: versionDe(/HuaweiBrowser\/(\d+)/), tipo: 'otro' };
    if (/EdgA\//.test(ua)) return { nombre: 'Edge', version: versionDe(/EdgA\/(\d+)/), tipo: 'otro' };
    if (/OPR\//.test(ua)) return { nombre: 'Opera', version: versionDe(/OPR\/(\d+)/), tipo: 'otro' };
    if (/Firefox\//.test(ua)) return { nombre: 'Firefox', version: versionDe(/Firefox\/(\d+)/), tipo: 'firefox' };
    return { nombre: 'este navegador', version: null, tipo: 'otro' };
}

async function revisarCompatibilidad() {
    await deteccionXR;
    const items = [];
    const poner = (titulo, estado, detalle) => items.push({ titulo, estado, detalle });

    // 1. Sistema operativo
    let soBien = true;
    if (esIOS) {
        const v = versionDe(/OS (\d+)[_.](\d+)?/) ?? versionDe(/Version\/(\d+)\.?(\d+)?/);
        soBien = v == null || v >= 12;
        poner('Sistema operativo', v == null ? 'duda' : soBien ? 'ok' : 'no',
            v == null ? 'iPhone o iPad (no se pudo leer la versión). Se necesita iOS 12 o más reciente.'
                : soBien ? `iOS ${String(v).replace('.', ',')}: cumple (se necesita iOS 12 o más reciente).`
                    : `iOS ${String(v).replace('.', ',')}: es muy antiguo. Se necesita iOS 12 o más reciente.`);
    } else if (AR.android) {
        const v = versionDe(/Android (\d+)(?:\.(\d+))?/);
        soBien = v == null || v >= 7;
        poner('Sistema operativo', v == null ? 'duda' : soBien ? 'ok' : 'no',
            v == null ? 'Android (no se pudo leer la versión). Se necesita Android 7.0 o más reciente.'
                : soBien ? `Android ${String(v).replace('.', ',')}: cumple (se necesita Android 7.0 o más reciente).`
                    : `Android ${String(v).replace('.', ',')}: es muy antiguo. Se necesita Android 7.0 o más reciente.`);
    } else {
        soBien = false;
        poner('Dispositivo', 'no', 'Esto es un computador: la realidad aumentada necesita la cámara de un celular o una tableta. Escanea el código QR con el celular.');
    }

    // 2. Navegador
    const nav = nombreNavegador();
    if (esIOS) {
        if (nav.tipo === 'safari' || nav.tipo === 'chrome-ios') poner('Navegador', 'ok', `${nav.nombre}: abre la realidad aumentada del iPhone.`);
        else if (nav.tipo === 'brave-ios') poner('Navegador', AR.ios ? 'ok' : 'aviso', AR.ios ? 'Brave para iPhone: abre la realidad aumentada del iPhone.' : 'Brave para iPhone: aquí no se abre la Vista rápida de Apple. Ábrela en Safari para la experiencia completa, o usa el modo cámara.');
        else poner('Navegador', 'aviso', `Estás en ${nav.nombre}, donde el iPhone no abre su realidad aumentada. Ábrela en Safari para la experiencia completa.`);
    } else if (AR.android) {
        if (nav.tipo === 'chrome') poner('Navegador', nav.version >= 81 ? 'ok' : 'no', nav.version >= 81 ? `Chrome ${nav.version}: cumple (se necesita Chrome 81 o más reciente).` : `Chrome ${nav.version}: está desactualizado. Actualízalo desde Play Store (se necesita Chrome 81 o más reciente).`);
        else if (nav.tipo === 'brave') poner('Navegador', 'aviso', `Brave${nav.version ? ' (Chromium ' + nav.version + ')' : ''}: puede abrir la realidad aumentada de Google, pero sus protecciones a veces la bloquean. Si no abre, usa Chrome o el modo cámara, y revisa que Brave tenga permiso de cámara.`);
        else if (nav.tipo === 'samsung') poner('Navegador', 'ok', `Samsung Internet ${nav.version ?? ''}: puede abrir la realidad aumentada. Si tienes problemas, prueba con Chrome.`);
        else if (nav.tipo === 'app') poner('Navegador', 'aviso', `Estás en ${nav.nombre}, que casi nunca deja abrir la realidad aumentada. Ábrela en Chrome.`);
        else poner('Navegador', 'aviso', `${nav.nombre[0].toUpperCase() + nav.nombre.slice(1)}${nav.version ? ' ' + nav.version : ''}: puede que no abra la realidad aumentada. Se recomienda Chrome.`);
    }

    // 3. Tecnología de realidad aumentada
    let arcore = 'duda';
    if (esIOS) {
        poner('Realidad aumentada (ARKit)', AR.ios ? (soBien ? 'ok' : 'no') : 'aviso', AR.ios
            ? (soBien ? 'La Vista rápida de Apple (ARKit) está disponible: la figura queda fija en tu espacio.' : 'La Vista rápida de Apple necesita iOS 12 o más reciente y un iPhone 6s o más nuevo.')
            : 'Aquí no está disponible la Vista rápida de Apple. En Safari sí (iPhone 6s o más nuevo).');
    } else if (AR.android) {
        arcore = AR.webxr ? 'ok' : (AR.xrContesto && !esBrave) ? 'no' : 'duda';
        poner('Realidad aumentada (ARCore)', arcore, arcore === 'ok'
            ? 'Este celular tiene los Servicios de Google Play para RA (ARCore): la figura queda fija en el piso.'
            : arcore === 'no'
                ? 'Este celular no tiene ARCore (los «Servicios de Google Play para RA»). Google solo lo da a los modelos que certifica, así que no puede fijar la figura al piso. Sí puedes verla con la cámara.'
                : 'Este navegador no deja comprobarlo. Se intentará con la app de Google; si no abre, el celular no tiene ARCore o falta instalarlo.');
    }

    // 4. Cámara
    let hayCamara = null;
    try {
        if (navigator.mediaDevices?.enumerateDevices) {
            const d = await navigator.mediaDevices.enumerateDevices();
            hayCamara = d.some((x) => x.kind === 'videoinput');
        }
    } catch (e) { hayCamara = null; }
    if (esTelefono) {
        poner('Cámara', hayCamara === false ? 'no' : hayCamara ? 'ok' : (AR.ios ? 'ok' : 'duda'), hayCamara === false
            ? 'No se encontró una cámara disponible (puede que otra app la esté usando).'
            : hayCamara || AR.ios ? 'Cámara trasera disponible.' : 'No se pudo comprobar la cámara desde este navegador.');
    }

    // 5. Sensores
    const giro = 'DeviceOrientationEvent' in window;
    if (esTelefono) {
        poner('Giroscopio y acelerómetro', giro ? 'ok' : 'aviso', giro
            ? (esIOS && typeof DeviceOrientationEvent.requestPermission === 'function' ? 'Disponibles. En el modo cámara, el iPhone pedirá permiso de «Movimiento y orientación».' : 'Disponibles: siguen el movimiento del celular.')
            : 'Este navegador no da los sensores de movimiento: en el modo cámara la figura no seguirá el giro del celular.');
    }

    // 6. Gráficos 3D
    const gl = hayWebGL();
    poner('Gráficos 3D (WebGL)', gl ? 'ok' : 'no', gl ? 'El celular dibuja figuras en 3D.' : 'Este navegador no tiene gráficos 3D (WebGL) o están desactivados. Actualiza el navegador o prueba con otro.');

    // 7. Conexión segura
    const https = window.isSecureContext;
    poner('Conexión segura (https)', https ? 'ok' : 'no', https ? 'La página está abierta con https.' : 'La página no está abierta con https, y así los celulares no dejan usar la cámara. Abre el enlace del QR de la galería.');

    // 8. Permiso de cámara
    let permiso = 'prompt';
    try {
        const q = await navigator.permissions?.query({ name: 'camera' });
        if (q) permiso = q.state;
    } catch (e) { permiso = 'prompt'; }
    if (esTelefono) {
        poner('Permiso de la cámara', permiso === 'granted' ? 'ok' : permiso === 'denied' ? 'no' : 'duda', permiso === 'granted'
            ? 'Ya diste permiso para usar la cámara.'
            : permiso === 'denied'
                ? (esIOS ? 'La cámara está bloqueada para esta página. Actívala en Ajustes › Safari › Cámara (o en «aA» › Configuración del sitio web › Cámara) y recarga.'
                    : 'La cámara está bloqueada para esta página. Toca el candado junto a la dirección › Permisos › Cámara › Permitir, y recarga.')
                : 'Se te pedirá al abrir la realidad aumentada: toca «Permitir».');
    }

    const camaraPosible = esTelefono && puedeModoCamara() && hayCamara !== false && permiso !== 'denied' && soBien;
    let nivel;
    if (!esTelefono || !soBien) nivel = 'ninguna';
    else if (AR.ios) nivel = 'completa';
    else if (AR.android && arcore === 'ok' && https && gl) nivel = 'completa';
    else if (AR.android && arcore === 'duda' && https) nivel = 'probable';
    else if (camaraPosible) nivel = 'camara';
    // todo está, menos el permiso de la cámara: no es que no sea compatible
    else if (permiso === 'denied' && puedeModoCamara() && hayCamara !== false && soBien) nivel = 'permiso';
    else nivel = 'ninguna';
    return { items, nivel, nav, arcore, permiso, camaraPosible };
}

/** El aviso: qué se revisó, por qué no (si no), qué se necesita y qué hacer. */
/**
 * Cómo activar los permisos que necesita la realidad aumentada, según el
 * celular. Se muestra cuando la RA no logra iniciar: la causa más común es
 * que se negó (o nunca se dio) el permiso de la cámara.
 */
function pasosPermisos() {
    if (esIOS) {
        return [
            'En Safari, toca «aA» junto a la dirección › Configuración del sitio web › Cámara › Permitir.',
            'Si no aparece: Ajustes del iPhone › Safari › Cámara › Permitir (en Chrome para iPhone: Ajustes › Chrome › Cámara).',
            'Para el modo cámara: Ajustes › Safari › activa «Acceso a movimiento y orientación».',
            'Vuelve a esta página, recárgala y toca otra vez «Ver en tu espacio».',
        ];
    }
    return [
        'Toca el candado (o el ícono de ajustes) junto a la dirección › Permisos › Cámara › Permitir.',
        'Si no aparece: Ajustes del teléfono › Aplicaciones › Chrome (o tu navegador) › Permisos › Cámara › Permitir.',
        'Para la realidad aumentada de Google: Ajustes › Aplicaciones › Servicios de Google Play para RA y Google › Permisos › Cámara › Permitir.',
        'Vuelve a esta página, recárgala y toca otra vez «Ver en tu espacio».',
    ];
}

/** La revisión dice que el permiso de la cámara se negó (lo supo al intentar abrirla). */
function marcarPermisoNegado(compat) {
    const p = compat.items.find((i) => i.titulo === 'Permiso de la cámara');
    if (p) {
        p.estado = 'no';
        p.detalle = 'No se dio permiso para la cámara. Arriba, cómo activarlo.';
    }
    compat.camaraPosible = false;
    compat.nivel = 'permiso';
}

/** El recuadro de advertencia de permisos dentro del aviso de compatibilidad. */
function mostrarAvisoPermisos(texto) {
    const caja = $('ra-compat-permisos');
    if (!caja) return;
    $('ra-compat-permisos-texto').textContent = texto
        || (esIOS
            ? 'La realidad aumentada no pudo iniciar. Para que funcione, esta página necesita permiso para usar la cámara y, en el modo cámara, «Movimiento y orientación».'
            : 'La realidad aumentada no pudo iniciar. Para que funcione, esta página necesita permiso para usar la cámara del celular.');
    $('ra-compat-permisos-pasos').replaceChildren(...pasosPermisos().map((t) => {
        const li = document.createElement('li');
        li.textContent = t;
        return li;
    }));
    caja.hidden = false;
}

function mostrarCompatibilidad(c, motivo = '', { permisos = false, textoPermisos = '' } = {}) {
    const etiqueta = $('ra-compat-etiqueta');
    const textos = {
        completa: ['completa', 'Compatible', 'Tu celular cumple los requisitos para ver la figura en realidad aumentada.'],
        probable: ['camara', 'Probablemente compatible', 'No se puede saber del todo desde este navegador; se intentará abrir la realidad aumentada de Google. Si no abre, puedes verla con la cámara.'],
        camara: ['camara', 'Compatible con el modo cámara', 'Tu celular no tiene todo lo que necesita la realidad aumentada completa (abajo, en rojo o en naranja, lo que falta). Puedes ver la figura sobre la imagen de la cámara: gira contigo, pero no queda fija al piso.'],
        ninguna: ['ninguna', 'No compatible', 'Por lo que se marca en rojo, este dispositivo no puede abrir la realidad aumentada. Abajo están los requisitos mínimos.'],
        permiso: ['camara', 'Falta un permiso', 'Tu celular sirve, pero la cámara está bloqueada para esta página, y sin ella la realidad aumentada no puede iniciar.'],
    };
    const [clase, titulo, resumen] = textos[c.nivel];
    etiqueta.className = `etiqueta ${clase}`;
    etiqueta.textContent = titulo;
    $('ra-compat-resumen').textContent = motivo || resumen;
    $('ra-compat-permisos').hidden = true;
    if (permisos || c.nivel === 'permiso') mostrarAvisoPermisos(textoPermisos);
    const lista = $('ra-compat-lista');
    lista.replaceChildren(...c.items.map((i) => {
        const li = document.createElement('li');
        li.className = i.estado;
        const icono = document.createElement('span');
        icono.className = 'icono';
        icono.setAttribute('aria-hidden', 'true');
        icono.textContent = { ok: '✓', no: '✕', aviso: '!', duda: '?' }[i.estado];
        const cuerpo = document.createElement('div');
        const t = document.createElement('strong');
        t.textContent = i.titulo;
        const d = document.createElement('span');
        d.textContent = i.detalle;
        const lector = document.createElement('span');
        lector.className = 'sr-only';
        lector.textContent = { ok: 'Cumple. ', no: 'No cumple. ', aviso: 'Con limitaciones. ', duda: 'Sin comprobar. ' }[i.estado];
        cuerpo.append(t, d);
        li.append(icono, cuerpo);
        li.prepend(lector);
        return li;
    }));
    // qué se puede hacer
    const camara = $('ra-compat-camara');
    camara.hidden = !(c.camaraPosible && c.nivel !== 'completa');
    camara.onclick = async () => { cerrarCompatibilidad(); if (esIOS) await pedirPermisoGiroscopio(); abrirModoCamara(); };
    const nav = $('ra-compat-navegador');
    nav.hidden = true;
    if (AR.android && !esChrome) {
        nav.textContent = 'Abrir en Chrome';
        nav.href = `intent://${window.location.host}${window.location.pathname}#Intent;scheme=https;package=com.android.chrome;end`;
        nav.hidden = false;
    } else if (AR.iosSinVistaRapida) {
        nav.textContent = 'Abrir en Safari';
        nav.href = `x-safari-https://${window.location.host}${window.location.pathname}`;
        nav.hidden = false;
    }
    $('ra-compat-arcore').hidden = !(AR.android && c.arcore === 'duda');
    $('ra-compat-fondo').hidden = false;
    $('ra-compat').scrollTop = 0;   // la advertencia de permisos va arriba: que se vea primero
    $('ra-compat').focus();
}
function cerrarCompatibilidad() {
    $('ra-compat-fondo').hidden = true;
    $('ra-boton')?.focus({ preventScroll: true });
}
$('ra-compat-cerrar').addEventListener('click', cerrarCompatibilidad);
$('ra-compat-fondo').addEventListener('click', (e) => { if (e.target === e.currentTarget) cerrarCompatibilidad(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('ra-compat-fondo').hidden) cerrarCompatibilidad(); });

/* ------------------------------------------------------------------ */
/* iPhone y Scene Viewer                                                */
/* ------------------------------------------------------------------ */

/** Vista rápida: un enlace rel="ar" cuyo único hijo es una imagen, como pide Safari. */
function abrirVistaRapida() {
    const a = document.createElement('a');
    a.rel = 'ar';
    a.href = urlAbsoluta(datos.urls.usdz) + (datos.escalaFija ? '#allowsContentScaling=0' : '');
    a.appendChild(document.createElement('img'));
    a.click();
}

function parametrosSceneViewer() {
    const p = new URLSearchParams({ file: urlAbsoluta(datos.urls.glb_ar), mode: 'ar_preferred', title: datos.nombre });
    if (datos.escalaFija) p.set('resizable', 'false');
    return p;
}

/** El enlace https de Scene Viewer: Android lo abre en la app de Google si la tiene. */
const enlaceSceneViewer = () => `https://arvr.google.com/scene-viewer/1.0?${parametrosSceneViewer()}`;

function abrirSceneViewer() {
    const volver = `${window.location.href.split('#')[0]}#sin-ar`;
    window.location.href = `intent://arvr.google.com/scene-viewer/1.0?${parametrosSceneViewer()}#Intent;scheme=https;package=com.google.android.googlequicksearchbox;action=android.intent.action.VIEW;S.browser_fallback_url=${encodeURIComponent(volver)};end;`;
    vigilarSiSeAbrio();
}

/**
 * Si al rato la página sigue a la vista y nada la tapó, Scene Viewer no se
 * abrió (el navegador no deja abrir otras apps, o falta la de Google).
 */
function vigilarSiSeAbrio() {
    let seFue = false;
    const marcar = () => { seFue = true; };
    window.addEventListener('blur', marcar, { once: true });
    document.addEventListener('visibilitychange', marcar, { once: true });
    window.addEventListener('pagehide', marcar, { once: true });
    setTimeout(() => {
        window.removeEventListener('blur', marcar);
        document.removeEventListener('visibilitychange', marcar);
        if (!seFue && document.visibilityState === 'visible') mostrarAyudaAndroid('no-abrio');
    }, 2800);
}

/** La ayuda de Android: qué hacer si la realidad aumentada no se abrió. */
function mostrarAyudaAndroid(motivo) {
    const caja = $('ra-android');
    if (!caja) return;
    $('ra-android-chrome').href = `intent://${window.location.host}${window.location.pathname}#Intent;scheme=https;package=com.android.chrome;end`;
    $('ra-android-chrome').hidden = esChrome;
    $('ra-android-escena').href = enlaceSceneViewer();
    const camara = $('ra-android-camara');
    camara.hidden = !puedeModoCamara();
    camara.onclick = () => abrirModoCamara();
    $('ra-android-titulo').textContent = motivo === 'sin-ar'
        ? 'Este teléfono no tiene realidad aumentada de Google'
        : 'No se abrió la realidad aumentada';
    $('ra-android-texto').textContent = motivo === 'sin-ar'
        ? 'Le faltan los Servicios de Google Play para RA (ARCore), que Google solo da a algunos modelos. Puedes ver la figura sobre la imagen de la cámara y acomodarla con los dedos.'
        : enOtraApp
            ? 'Esta página se abrió dentro de otra app (el lector de QR, la cámara o una red social), que no deja abrir la realidad aumentada. Puedes verla con la cámara o abrir la página en Chrome.'
            : 'Revisa que el teléfono tenga la app de Google y los Servicios de Google Play para RA (ARCore) instalados y actualizados, y que tengan permiso para usar la cámara (Ajustes › Aplicaciones › Google › Permisos › Cámara › Permitir). Mientras tanto, puedes verla con la cámara.';
    caja.hidden = false;
    caja.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/* ------------------------------------------------------------------ */
/* La figura girando                                                    */
/* ------------------------------------------------------------------ */

function hayWebGL() {
    try {
        const c = document.createElement('canvas');
        return !!(c.getContext('webgl2') || c.getContext('webgl'));
    } catch (e) {
        return false;
    }
}

/** Lo que comparten la vista de la página y la realidad aumentada en Android. */
const figura = { listo: false };
let avisarFiguraLista;
const figuraCargada = new Promise((ok) => { avisarFiguraLista = ok; });

async function mostrarFigura() {
    const escenario = $('ra-escena');
    const lienzo = $('ra-lienzo');
    const renderer = new THREE.WebGLRenderer({ canvas: lienzo, antialias: ajustes.antialias, alpha: true, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, ajustes.pixelRatio));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    // Si el teléfono le quita el contexto 3D (pasa al volver de la Vista
    // rápida o de Scene Viewer), se pide de vuelta en lugar de quedar negro.
    lienzo.addEventListener('webglcontextlost', (e) => e.preventDefault());

    const escena = new THREE.Scene();
    const pmrem = new THREE.PMREMGenerator(renderer);
    escena.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    pmrem.dispose();
    const sol = new THREE.DirectionalLight(0xffffff, 1.2);
    sol.position.set(2, 4, 3);
    escena.add(sol);
    // La luz de la realidad aumentada vive aquí desde el principio, apagada:
    // si se agregara al entrar, three.js recompilaría todos los materiales
    // justo cuando aparece la figura, y se notaría un tirón.
    const ambiente = new THREE.HemisphereLight(0xffffff, 0x888877, 0);
    escena.add(ambiente);

    const camara = new THREE.PerspectiveCamera(35, 1, 0.001, 100);
    const controles = new OrbitControls(camara, lienzo);
    controles.enableDamping = true;
    controles.enablePan = false;
    controles.autoRotate = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    controles.autoRotateSpeed = 1.6;

    const cargador = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
    const gltf = await cargador.loadAsync(datos.urls.glb);
    const modelo = gltf.scene;
    escena.add(modelo);

    // Encuadre: la figura entera, un poco desde arriba.
    const caja = new THREE.Box3().setFromObject(modelo);
    const centro = caja.getCenter(new THREE.Vector3());
    const radio = caja.getSize(new THREE.Vector3()).length() / 2;
    controles.target.copy(centro);
    controles.minDistance = radio * 1.2;
    controles.maxDistance = radio * 6;

    let mezclador = null;
    if (gltf.animations.length) {
        mezclador = new THREE.AnimationMixer(modelo);
        gltf.animations.forEach((clip) => mezclador.clipAction(clip).play());
    }

    const ajustar = () => {
        const { clientWidth: w, clientHeight: h } = escenario;
        if (!w || !h || renderer.xr.isPresenting || figura.enCamara) return;
        renderer.setSize(w, h, false);
        camara.aspect = w / h;
        const distancia = (radio / Math.sin(THREE.MathUtils.degToRad(camara.fov / 2))) * (camara.aspect < 1 ? 0.95 / Math.max(camara.aspect, 0.7) : 1.05);
        camara.position.copy(centro).add(new THREE.Vector3(0.75, 0.55, 1).normalize().multiplyScalar(distancia));
        camara.updateProjectionMatrix();
    };
    new ResizeObserver(ajustar).observe(escenario);
    ajustar();

    // Un reloj sencillo: segundos desde el cuadro anterior.
    const reloj = { t: performance.now(), getDelta() { const a = performance.now(); const d = (a - this.t) / 1000; this.t = a; return d; } };
    const cuadroVista = () => {
        const dt = Math.min(reloj.getDelta(), 0.1);
        if (mezclador) mezclador.update(dt);
        controles.update();
        renderer.render(escena, camara);
    };
    // Solo se dibuja mientras se ve: con la pestaña oculta (en la Vista
    // rápida, en Scene Viewer) o la figura fuera de pantalla, quieto. Ahorra
    // batería y deja el teléfono fresco para la realidad aumentada.
    let enPantalla = true;
    const decidir = () => {
        if (renderer.xr.isPresenting || figura.enCamara) return;
        renderer.setAnimationLoop(!document.hidden && enPantalla ? cuadroVista : null);
        reloj.getDelta();
    };
    document.addEventListener('visibilitychange', decidir);
    new IntersectionObserver(([e]) => { enPantalla = e.isIntersecting; decidir(); }).observe(escenario);
    lienzo.addEventListener('webglcontextrestored', decidir);
    decidir();

    Object.assign(figura, { listo: true, renderer, escena, camara, controles, modelo, mezclador, caja, centro, reloj, cuadroVista, decidir, ajustar, sol, ambiente });
    escenario.classList.add('listo');
    avisarFiguraLista();
}

/* ------------------------------------------------------------------ */
/* Android: realidad aumentada en la propia página (WebXR)              */
/* ------------------------------------------------------------------ */

class SesionWebXR {
    async iniciar() {
        const f = figura;
        const capa = $('ra-xr');
        const sesion = await navigator.xr.requestSession('immersive-ar', {
            requiredFeatures: ['hit-test'],
            // anchors: ARCore mantiene la figura clavada en su punto del piso
            // aunque corrija su mapa al mover el teléfono (sin ancla, la
            // figura se deslizaba o saltaba con esas correcciones).
            optionalFeatures: ['dom-overlay', 'anchors'],
            domOverlay: { root: capa },
        });
        this.sesion = sesion;
        // Desde ya: si se cierra mientras se prepara (la persona sale enseguida,
        // otra app toma la cámara), igual se deja todo como estaba.
        sesion.addEventListener('end', () => this.terminar());
        capa.hidden = false;
        this.planos = [f.camara.near, f.camara.far];
        f.camara.near = 0.01; f.camara.far = 30; f.camara.updateProjectionMatrix();
        f.renderer.xr.enabled = true;
        f.renderer.xr.setReferenceSpaceType('local');
        // A la resolución de la cámara en gama alta; algo menos en gama media,
        // donde dibujar la imagen completa haría perder cuadros.
        f.renderer.xr.setFramebufferScaleFactor(ajustes.escalaXR);
        try {
            await this.preparar(sesion, capa);
        } catch (err) {
            // A mitad de camino: se deja todo como estaba y se cierra la
            // sesión, para que verEnTuEspacio pruebe con Scene Viewer.
            this.terminar();
            sesion.end().catch(() => {});
            throw err;
        }
    }

    async preparar(sesion, capa) {
        const f = figura;
        await f.renderer.xr.setSession(sesion);

        // El modelo cuelga de un ancla, con la base en el suelo y centrado.
        this.ancla = new THREE.Group();
        this.ancla.visible = false;
        this.posicionAntes = f.modelo.position.clone();
        f.modelo.position.set(-f.centro.x, -f.caja.min.y, -f.centro.z);
        this.ancla.add(f.modelo);
        f.escena.add(this.ancla);
        f.ambiente.intensity = 1.2;
        this.vidrioLiviano(f.modelo);

        this.reticula = new THREE.Mesh(
            new THREE.RingGeometry(0.06, 0.075, 40).rotateX(-Math.PI / 2),
            new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.9 }),
        );
        this.reticula.matrixAutoUpdate = false;
        f.escena.add(this.reticula);
        // la retícula sigue al impacto suavizado: no tiembla con cada cuadro
        this.reticulaPos = new THREE.Vector3();
        this.reticulaRot = new THREE.Quaternion();
        this.impactoPos = new THREE.Vector3();
        this.impactoRot = new THREE.Quaternion();
        this.escalaUno = new THREE.Vector3(1, 1, 1);
        this.reticulaLista = false;

        // una sombra suave bajo la figura: la «apoya» en el piso
        const tam = f.caja.getSize(new THREE.Vector3());
        this.sombra = sombraSuave();
        this.sombra.scale.set(Math.max(tam.x, tam.z) * 1.6, 1, Math.max(tam.x, tam.z) * 1.6);
        this.sombra.position.y = 0.001;
        this.sombra.renderOrder = -1;
        this.ancla.add(this.sombra);
        this.anclaXR = null;
        this.anclaMatriz = new THREE.Matrix4();
        this.anclaPos = new THREE.Vector3();
        this.anclaRot = new THREE.Quaternion();
        this.anclaEsc = new THREE.Vector3();

        // Los materiales se preparan ahora, con todo a la vista un instante, y
        // no en el cuadro en que la persona pone la figura.
        this.ancla.visible = true;
        this.reticula.visible = true;
        try { f.renderer.compile(f.escena, f.camara); } catch (e) { /* se compila al dibujar */ }
        this.ancla.visible = false;
        this.reticula.visible = false;
        this.ultimoImpacto = new THREE.Matrix4();
        this.hayImpacto = false;

        const espacioVista = await sesion.requestReferenceSpace('viewer');
        this.fuente = await sesion.requestHitTestSource({ space: espacioVista });
        this.ultimoResultado = null;
        this.colocado = false;
        this.escala = 1;

        sesion.addEventListener('select', () => this.tocar());
        $('ra-xr-salir').onclick = () => sesion.end().catch(() => this.terminar());
        // Si el teléfono no da los cuadros, se recuerda: la próxima vez,
        // menos resolución (o Scene Viewer en los más modestos).
        this.medidor = new MedidorDeCuadros(() => console.info('Realidad aumentada lenta en este teléfono; la próxima vez irá más liviana.'));
        $('ra-xr-otra-vez').onclick = () => { this.colocado = false; this.ancla.visible = false; this.anclaXR?.delete?.(); this.anclaXR = null; this.anclaPedida = null; $('ra-xr-otra-vez').hidden = true; };
        capa.querySelector('.acciones').addEventListener('beforexrselect', (e) => e.preventDefault());
        this.gestos();
        this.decir('Mueve el teléfono despacio sobre el piso o una mesa');

        f.reloj.getDelta();
        f.renderer.setAnimationLoop((t, frame) => this.cuadro(frame));
    }

    /** El mensaje de arriba; solo se toca la página si cambia (no en cada cuadro). */
    decir(t) {
        if (this.mensaje === t) return;
        this.mensaje = t;
        $('ra-xr-mensaje').textContent = t;
    }

    /**
     * El vidrio de verdad (transmission) obliga a dibujar la escena dos veces
     * por cuadro. En la realidad aumentada se cambia por un vidrio
     * transparente que se ve casi igual y cuesta la mitad; al salir vuelve.
     */
    vidrioLiviano(modelo) {
        this.vidrios = [];
        modelo.traverse((o) => {
            for (const m of [].concat(o.material || [])) {
                if (!(m.transmission > 0) || this.vidrios.some((v) => v.m === m)) continue;
                this.vidrios.push({ m, antes: { transmission: m.transmission, transparent: m.transparent, opacity: m.opacity, depthWrite: m.depthWrite } });
                m.opacity = Math.min(m.opacity, 0.25 + 0.6 * (1 - m.transmission));
                m.transmission = 0;
                m.transparent = true;
                m.depthWrite = false;
                m.needsUpdate = true;
            }
        });
    }

    tocar() {
        if (this.arrastrando || !this.hayImpacto) return;
        this.ancla.position.setFromMatrixPosition(this.ultimoImpacto);
        if (!this.colocado) {
            // de frente a quien mira
            const p = new THREE.Vector3().setFromMatrixPosition(figura.renderer.xr.getCamera().matrixWorld);
            this.ancla.rotation.y = Math.atan2(p.x - this.ancla.position.x, p.z - this.ancla.position.z);
        }
        this.anclar();
        this.colocado = true;
        this.ancla.visible = true;
        $('ra-xr-otra-vez').hidden = false;
        this.decir(datos.escalaFija ? 'A tamaño real · arrastra para girarlo' : 'Arrastra para girarlo · pellizca para cambiar el tamaño');
    }

    /**
     * Un ancla de ARCore en el punto donde quedó la figura. Mientras exista,
     * la figura sigue ese punto del mundo real cuadro a cuadro, aunque ARCore
     * corrija su mapa. Sin soporte de anclas (otros navegadores), la figura
     * queda donde se puso, como antes.
     */
    anclar() {
        this.anclaXR?.delete?.();
        this.anclaXR = null;
        const r = this.ultimoResultado;
        if (!r?.createAnchor) return;
        const pedida = r.createAnchor();
        this.anclaPedida = pedida;
        pedida?.then((a) => {
            if (this.terminada || this.anclaPedida !== pedida) { a.delete?.(); return; }
            this.anclaXR = a;
        }).catch(() => { /* sin ancla: queda donde se puso */ });
    }

    gestos() {
        const zona = $('ra-xr-zona');
        const toques = new Map();
        let inicio = null;
        zona.onpointerdown = (e) => {
            toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
            inicio = { rot: this.ancla.rotation.y, escala: this.escala, puntos: [...toques.values()].map((p) => ({ ...p })) };
        };
        zona.onpointermove = (e) => {
            if (!toques.has(e.pointerId) || !inicio || !this.colocado) return;
            toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
            const p = [...toques.values()];
            if (p.length === 1 && inicio.puntos.length === 1) {
                const dx = p[0].x - inicio.puntos[0].x;
                if (Math.abs(dx) > 8) this.arrastrando = true;
                this.ancla.rotation.y = inicio.rot + dx * 0.012;
            } else if (p.length >= 2 && inicio.puntos.length >= 2 && !datos.escalaFija) {
                const d0 = Math.hypot(inicio.puntos[0].x - inicio.puntos[1].x, inicio.puntos[0].y - inicio.puntos[1].y);
                const d1 = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                this.escala = THREE.MathUtils.clamp(inicio.escala * (d1 / Math.max(d0, 1)), 0.25, 4);
                this.ancla.scale.setScalar(this.escala);
                this.arrastrando = true;
            }
        };
        const soltar = (e) => {
            toques.delete(e.pointerId);
            if (toques.size === 0) setTimeout(() => (this.arrastrando = false), 350);
            inicio = toques.size ? { rot: this.ancla.rotation.y, escala: this.escala, puntos: [...toques.values()].map((p) => ({ ...p })) } : null;
        };
        zona.onpointerup = soltar;
        zona.onpointercancel = soltar;
    }

    cuadro(frame) {
        const f = figura;
        const dt = Math.min(f.reloj.getDelta(), 0.1);
        if (frame && this.fuente) {
            const espacio = f.renderer.xr.getReferenceSpace();
            // Sin seguimiento (el teléfono se movió muy rápido, poca luz, una
            // pared lisa): la figura se queda donde está y se pide calma.
            const vista = frame.getViewerPose(espacio);
            this.sinSeguimiento = !vista || vista.emulatedPosition;

            const impactos = frame.getHitTestResults(this.fuente);
            const pose = impactos.length ? impactos[0].getPose(espacio) : null;
            if (pose) {
                // La misma matriz de siempre: crear una por cuadro llena la
                // memoria y el teléfono se detiene a limpiarla (tirones).
                this.ultimoImpacto.fromArray(pose.transform.matrix);
                this.ultimoResultado = impactos[0];
                this.hayImpacto = true;
                if (!this.colocado) {
                    // la retícula se acerca al impacto en vez de saltar a él
                    this.ultimoImpacto.decompose(this.impactoPos, this.impactoRot, this.anclaEsc);
                    if (!this.reticulaLista) { this.reticulaPos.copy(this.impactoPos); this.reticulaRot.copy(this.impactoRot); this.reticulaLista = true; }
                    const k = 1 - Math.pow(0.001, dt);   // igual de suave a 30 o a 60 cuadros por segundo
                    this.reticulaPos.lerp(this.impactoPos, k);
                    this.reticulaRot.slerp(this.impactoRot, k);
                    this.reticula.matrix.compose(this.reticulaPos, this.reticulaRot, this.escalaUno);
                    this.decir('Toca la pantalla para ponerlo aquí');
                }
                this.reticula.visible = !this.colocado;
            } else {
                this.reticula.visible = false;
                this.reticulaLista = false;
                this.hayImpacto = false;
            }

            // la figura sigue su ancla (solo la posición: la orientación la
            // manejan los dedos)
            if (this.anclaXR && this.colocado) {
                const pa = frame.getPose(this.anclaXR.anchorSpace, espacio);
                if (pa) {
                    this.anclaMatriz.fromArray(pa.transform.matrix);
                    this.anclaMatriz.decompose(this.anclaPos, this.anclaRot, this.anclaEsc);
                    this.ancla.position.copy(this.anclaPos);
                }
            }
            if (this.sinSeguimiento) this.decir('Mueve el teléfono más despacio, apuntando al piso con buena luz');
            else if (this.colocado && this.mensaje?.startsWith('Mueve el teléfono más')) this.decir(datos.escalaFija ? 'A tamaño real · arrastra para girarlo' : 'Arrastra para girarlo · pellizca para cambiar el tamaño');
        }
        if (f.mezclador) f.mezclador.update(dt);
        f.renderer.render(f.escena, f.camara);
        this.medidor?.cuadro(dt, this.colocado);
    }

    /** Deja todo como antes. Sirve también si la sesión se cayó a medio preparar. */
    terminar() {
        if (this.terminada) return;
        this.terminada = true;
        const f = figura;
        this.fuente?.cancel?.();
        this.anclaXR?.delete?.();
        this.anclaXR = null;
        if (this.sombra) {
            this.sombra.parent?.remove(this.sombra);
            this.sombra.geometry.dispose();
            this.sombra.material.map?.dispose();
            this.sombra.material.dispose();
        }
        $('ra-xr').hidden = true;
        if (this.reticula) {
            f.escena.remove(this.reticula);
            this.reticula.geometry.dispose();
            this.reticula.material.dispose();
        }
        f.ambiente.intensity = 0;
        for (const { m, antes } of this.vidrios || []) {
            Object.assign(m, antes);
            m.needsUpdate = true;
        }
        if (this.ancla) {
            f.escena.add(f.modelo);
            f.escena.remove(this.ancla);
            f.modelo.position.copy(this.posicionAntes);
        }
        [f.camara.near, f.camara.far] = this.planos;
        f.camara.updateProjectionMatrix();
        f.renderer.xr.enabled = false;
        window.dispatchEvent(new Event('resize'));
        f.decidir();
    }
}

/* ------------------------------------------------------------------ */
/* Android sin ARCore: la figura sobre la imagen de la cámara           */
/* ------------------------------------------------------------------ */

function puedeModoCamara() {
    return !!(window.isSecureContext && navigator.mediaDevices?.getUserMedia && hayWebGL());
}

/** Una sombra suave bajo la figura, para que parezca apoyada. */
function sombraSuave() {
    const c = document.createElement('canvas');
    c.width = c.height = 128;
    const g = c.getContext('2d');
    const r = g.createRadialGradient(64, 64, 0, 64, 64, 64);
    r.addColorStop(0, 'rgba(0,0,0,0.45)');
    r.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = r;
    g.fillRect(0, 0, 128, 128);
    const t = new THREE.CanvasTexture(c);
    return new THREE.Mesh(
        new THREE.PlaneGeometry(1, 1).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ map: t, transparent: true, depthWrite: false }),
    );
}

/**
 * El giroscopio: con él, la figura del modo cámara se queda en su lugar al
 * girar el teléfono (como si estuviera puesta ahí), en vez de girar pegada a
 * la pantalla. Sin ARCore no se sabe si el teléfono se desplaza, pero sí cómo
 * gira; eso es lo que más se nota. En iPhone hay que pedir permiso, y solo
 * dentro de un toque.
 */
let permisoGiroscopio = null;
async function pedirPermisoGiroscopio() {
    const D = window.DeviceOrientationEvent;
    if (!D || typeof D.requestPermission !== 'function') return true;   // Android: no se pide
    if (permisoGiroscopio !== null) return permisoGiroscopio;
    try { permisoGiroscopio = (await D.requestPermission()) === 'granted'; } catch (e) { permisoGiroscopio = false; }
    return permisoGiroscopio;
}

/** El giro del teléfono como un cuaternión (convención de three.js para la cámara). */
const Giroscopio = (() => {
    const zee = new THREE.Vector3(0, 0, 1);
    const euler = new THREE.Euler();
    const q0 = new THREE.Quaternion();
    const q1 = new THREE.Quaternion(-Math.sqrt(0.5), 0, 0, Math.sqrt(0.5));   // -90° en X: la cámara mira por detrás del teléfono
    return {
        cuaternion(q, alfa, beta, gamma, orientacion) {
            euler.set(beta, alfa, -gamma, 'YXZ');
            q.setFromEuler(euler);
            q.multiply(q1);
            q.multiply(q0.setFromAxisAngle(zee, -orientacion));
            return q;
        },
    };
})();

class ModoCamara {
    async iniciar() {
        const f = figura;
        if (!f.listo) throw new Error('La figura todavía no cargó');
        this.flujo = await navigator.mediaDevices.getUserMedia({
            audio: false,
            // En gama baja, video más chico: el teléfono dibuja la figura sin tirones.
            video: { facingMode: { ideal: 'environment' }, ...(perfil.gama === 'baja' ? { width: { ideal: 640 }, height: { ideal: 480 } } : { width: { ideal: 1280 }, height: { ideal: 720 } }), frameRate: { ideal: 30, max: 30 } },
        });
        const capa = $('ra-cam');
        const video = $('ra-cam-video');
        video.srcObject = this.flujo;
        await video.play().catch(() => {});

        f.enCamara = true;
        f.renderer.setAnimationLoop(null);
        f.controles.enabled = false;
        // El mismo lienzo (y su contexto 3D) pasa a la capa de la cámara.
        this.lienzo = f.renderer.domElement;
        this.padre = this.lienzo.parentNode;
        this.siguiente = this.lienzo.nextSibling;
        capa.insertBefore(this.lienzo, $('ra-cam-mensaje'));
        capa.hidden = false;
        document.documentElement.style.overflow = 'hidden';

        // La figura cuelga de un ancla, con la base en el «piso» y centrada.
        this.antes = { pos: f.modelo.position.clone(), fov: f.camara.fov, near: f.camara.near, far: f.camara.far, camPos: f.camara.position.clone(), camQuat: f.camara.quaternion.clone() };
        const tam = f.caja.getSize(new THREE.Vector3());
        this.alto = tam.y;
        this.ancla = new THREE.Group();
        f.modelo.position.set(-f.centro.x, -f.caja.min.y, -f.centro.z);
        this.ancla.add(f.modelo);
        this.sombra = sombraSuave();
        this.sombra.scale.set(Math.max(tam.x, tam.z) * 1.5, 1, Math.max(tam.x, tam.z) * 1.5);
        this.ancla.add(this.sombra);
        f.escena.add(this.ancla);
        f.ambiente.intensity = 1.1;
        this.escala = 1;

        // Una cámara que mira la figura un poco desde arriba, como se mira
        // algo puesto en el piso o en una mesa.
        f.camara.fov = 55;
        const radio = tam.length() / 2;
        this.distancia = (radio / Math.tan(THREE.MathUtils.degToRad(f.camara.fov / 2))) * 1.9;
        f.camara.near = this.distancia / 100;
        f.camara.far = this.distancia * 20;
        this.encuadrar();
        this.redimensionar = () => this.encuadrar();
        window.addEventListener('resize', this.redimensionar);

        this.gestos();
        this.giroscopio();
        this.vigilarPermisoGiro();
        $('ra-cam-salir').onclick = () => this.salir();
        $('ra-cam-foto').onclick = () => this.foto();
        $('ra-cam-centrar').onclick = () => this.centrar();
        // El botón «atrás» del teléfono cierra la cámara en vez de irse.
        history.pushState({ camara: true }, '');
        this.atras = () => this.salir(true);
        window.addEventListener('popstate', this.atras);
        // Al volver de otra app el teléfono puede haber apagado la cámara: se
        // vuelve a pedir; si no se puede, se sale.
        this.alVolver = async () => {
            if (document.hidden || this.cerrado) return;
            const pista = this.flujo.getVideoTracks()[0];
            if (pista && pista.readyState === 'live') { video.play().catch(() => {}); return; }
            try {
                this.flujo = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
                video.srcObject = this.flujo;
                video.play().catch(() => {});
            } catch (e) {
                this.salir();
            }
        };
        document.addEventListener('visibilitychange', this.alVolver);

        f.reloj.getDelta();
        f.renderer.setAnimationLoop(() => {
            const dt = Math.min(f.reloj.getDelta(), 0.1);
            if (f.mezclador) f.mezclador.update(dt);
            this.seguirGiro(dt);
            f.renderer.render(f.escena, f.camara);
        });
    }

    giroscopio() {
        // this.base: la cámara mirando la figura, sin giro (la pone encuadrar)
        this.inicial = null;                      // el giro del teléfono al empezar (o al centrar)
        this.actual = new THREE.Quaternion();     // el giro que dice el sensor
        this.suave = new THREE.Quaternion();      // el giro aplicado, suavizado
        this.relativo = new THREE.Quaternion();
        this.hayGiro = false;
        this.alGirar = (e) => {
            if (e.alpha == null && e.beta == null) return;
            const r = THREE.MathUtils.degToRad;
            const orient = r(screen.orientation?.angle ?? window.orientation ?? 0);
            Giroscopio.cuaternion(this.actual, r(e.alpha || 0), r(e.beta || 0), r(e.gamma || 0), orient);
            if (!this.inicial) {
                this.inicial = this.actual.clone();
                this.suave.copy(this.actual);
                $('ra-cam-centrar').hidden = false;
                $('ra-cam-mensaje').textContent = 'Gira el teléfono: la figura se queda en su lugar';
                clearTimeout(this.relojMensaje);
                this.relojMensaje = setTimeout(() => { $('ra-cam-mensaje').textContent = 'Un dedo: girar · dos dedos: mover y cambiar el tamaño'; }, 3500);
            }
            this.hayGiro = true;
        };
        window.addEventListener('deviceorientation', this.alGirar);
    }

    /**
     * En iPhone el giro necesita el permiso «Movimiento y orientación». Si no
     * llega ningún dato del sensor, se avisa cómo activarlo (en Android el
     * sensor no pide permiso: si no llega, es que el teléfono no lo tiene).
     */
    vigilarPermisoGiro() {
        const aviso = $('ra-cam-permiso');
        if (!aviso || !esIOS) return;
        this.relojPermiso = setTimeout(() => {
            if (this.cerrado || this.hayGiro) return;
            aviso.textContent = permisoGiroscopio === false
                ? '⚠ No se dio permiso de «Movimiento y orientación»: la figura no seguirá el giro del teléfono. Actívalo en Ajustes › Safari › «Acceso a movimiento y orientación» y vuelve a abrir la página.'
                : '⚠ No llegan datos del movimiento del teléfono. Revisa en Ajustes › Safari que «Acceso a movimiento y orientación» esté activado y vuelve a abrir la página.';
            aviso.hidden = false;
            this.relojOcultar = setTimeout(() => { aviso.hidden = true; }, 10000);
        }, 2500);
    }

    /** Vuelve a poner la figura delante, hacia donde apunta ahora el teléfono. */
    centrar() {
        if (this.hayGiro) { this.inicial = this.actual.clone(); this.suave.copy(this.actual); }
        this.ancla.position.set(0, 0, 0);
    }

    /**
     * La cámara gira lo mismo que giró el teléfono desde que empezó: así la
     * figura se queda quieta en el mundo. Suavizado para que el temblor de la
     * mano y el ruido del sensor no la hagan vibrar, pero sin retraso que se
     * note (igual de rápido a 30 o a 60 cuadros por segundo).
     */
    seguirGiro(dt) {
        const f = figura;
        f.camara.position.copy(this.posCamara);
        f.camara.quaternion.copy(this.base);
        if (!this.hayGiro || !this.inicial) return;
        const k = 1 - Math.pow(0.00002, dt);
        // por el camino corto (q y -q son el mismo giro)
        if (this.suave.dot(this.actual) < 0) this.actual.set(-this.actual.x, -this.actual.y, -this.actual.z, -this.actual.w);
        this.suave.slerp(this.actual, k);
        this.relativo.copy(this.inicial).invert().multiply(this.suave);
        f.camara.quaternion.multiply(this.relativo);
    }

    encuadrar() {
        const f = figura;
        const w = window.innerWidth, h = window.innerHeight;
        f.renderer.setSize(w, h, false);
        f.camara.aspect = w / h;
        // en vertical, más lejos para que quepa a lo ancho
        const d = this.distancia * (f.camara.aspect < 1 ? Math.min(1.6, 0.8 / Math.max(f.camara.aspect, 0.45)) : 1);
        f.camara.position.set(0, this.alto * 0.5 + d * 0.42, d);
        f.camara.lookAt(0, this.alto * 0.42, 0);
        f.camara.updateProjectionMatrix();
        this.posCamara = f.camara.position.clone();
        this.base = f.camara.quaternion.clone();
        this.unidadesPorPixel = (2 * d * Math.tan(THREE.MathUtils.degToRad(f.camara.fov / 2))) / h;
    }

    gestos() {
        const zona = this.lienzo;
        const toques = new Map();
        let inicio = null;
        const empezar = () => {
            const p = [...toques.values()];
            inicio = {
                rot: this.ancla.rotation.y, escala: this.escala, pos: this.ancla.position.clone(),
                puntos: p.map((q) => ({ ...q })),
                medio: p.length >= 2 ? { x: (p[0].x + p[1].x) / 2, y: (p[0].y + p[1].y) / 2 } : null,
            };
        };
        this.alTocar = (e) => { zona.setPointerCapture?.(e.pointerId); toques.set(e.pointerId, { x: e.clientX, y: e.clientY }); empezar(); };
        this.alMover = (e) => {
            if (!toques.has(e.pointerId) || !inicio) return;
            toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
            const p = [...toques.values()];
            if (p.length === 1 && inicio.puntos.length === 1) {
                this.ancla.rotation.y = inicio.rot + (p[0].x - inicio.puntos[0].x) * 0.012;
            } else if (p.length >= 2 && inicio.medio) {
                const d0 = Math.hypot(inicio.puntos[0].x - inicio.puntos[1].x, inicio.puntos[0].y - inicio.puntos[1].y);
                const d1 = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                this.escala = THREE.MathUtils.clamp(inicio.escala * (d1 / Math.max(d0, 1)), 0.3, 3);
                this.ancla.scale.setScalar(this.escala);
                const medio = { x: (p[0].x + p[1].x) / 2, y: (p[0].y + p[1].y) / 2 };
                this.ancla.position.x = inicio.pos.x + (medio.x - inicio.medio.x) * this.unidadesPorPixel;
                this.ancla.position.z = inicio.pos.z + (medio.y - inicio.medio.y) * this.unidadesPorPixel * 1.6;
            }
        };
        this.alSoltar = (e) => { toques.delete(e.pointerId); if (toques.size) empezar(); else inicio = null; };
        zona.addEventListener('pointerdown', this.alTocar);
        zona.addEventListener('pointermove', this.alMover);
        zona.addEventListener('pointerup', this.alSoltar);
        zona.addEventListener('pointercancel', this.alSoltar);
    }

    /** Una foto: la imagen de la cámara con la figura encima. */
    foto() {
        const f = figura;
        const video = $('ra-cam-video');
        const w = this.lienzo.width, h = this.lienzo.height;
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        const g = c.getContext('2d');
        // la imagen de la cámara recortada como en pantalla (object-fit: cover)
        const vw = video.videoWidth || w, vh = video.videoHeight || h;
        const k = Math.max(w / vw, h / vh);
        g.drawImage(video, (w - vw * k) / 2, (h - vh * k) / 2, vw * k, vh * k);
        // se dibuja y se copia en el mismo instante (el lienzo se borra después)
        f.renderer.render(f.escena, f.camara);
        g.drawImage(this.lienzo, 0, 0, w, h);
        c.toBlob((blob) => {
            if (!blob) return;
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `${datos.slug || 'figura'}-fablab.jpg`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 4000);
        }, 'image/jpeg', 0.9);
        $('ra-cam-mensaje').textContent = 'Foto guardada en descargas';
        clearTimeout(this.relojMensaje);
        this.relojMensaje = setTimeout(() => { $('ra-cam-mensaje').textContent = 'Un dedo: girar · dos dedos: mover y cambiar el tamaño'; }, 2500);
    }

    /** Deja todo como antes. */
    salir(desdeAtras = false) {
        if (this.cerrado) return;
        this.cerrado = true;
        const f = figura;
        f.renderer.setAnimationLoop(null);
        this.flujo?.getTracks().forEach((t) => t.stop());
        $('ra-cam-video').srcObject = null;
        window.removeEventListener('resize', this.redimensionar);
        window.removeEventListener('popstate', this.atras);
        document.removeEventListener('visibilitychange', this.alVolver);
        window.removeEventListener('deviceorientation', this.alGirar);
        clearTimeout(this.relojPermiso);
        clearTimeout(this.relojOcultar);
        if ($('ra-cam-permiso')) $('ra-cam-permiso').hidden = true;
        $('ra-cam-centrar').hidden = true;
        if (!desdeAtras && history.state?.camara) history.back();
        const z = this.lienzo;
        z.removeEventListener('pointerdown', this.alTocar);
        z.removeEventListener('pointermove', this.alMover);
        z.removeEventListener('pointerup', this.alSoltar);
        z.removeEventListener('pointercancel', this.alSoltar);
        this.padre.insertBefore(z, this.siguiente);
        $('ra-cam').hidden = true;
        document.documentElement.style.overflow = '';

        f.escena.add(f.modelo);
        f.modelo.position.copy(this.antes.pos);
        f.escena.remove(this.ancla);
        this.sombra.geometry.dispose();
        this.sombra.material.map.dispose();
        this.sombra.material.dispose();
        f.ambiente.intensity = 0;
        Object.assign(f.camara, { fov: this.antes.fov, near: this.antes.near, far: this.antes.far });
        f.camara.position.copy(this.antes.camPos);
        f.camara.quaternion.copy(this.antes.camQuat);
        f.camara.updateProjectionMatrix();
        f.controles.enabled = true;
        f.enCamara = false;
        f.ajustar();
        f.decidir();
        modoCamara = null;
    }
}

let modoCamara = null;

async function abrirModoCamara() {
    if (modoCamara) return;
    if (!figura.listo) {
        // Todavía se está bajando (en un teléfono modesto o con mala señal):
        // se espera, en lugar de pedir que vuelva a tocar.
        avisar('Cargando la figura…');
        const llego = await Promise.race([figuraCargada.then(() => true), new Promise((ok) => setTimeout(() => ok(false), 30000))]);
        if (!llego) { avisar('La figura no terminó de cargar. Revisa la señal y vuelve a intentarlo.'); return; }
        $('ra-aviso').hidden = true;
    }
    $('ra-android').hidden = true;
    modoCamara = new ModoCamara();
    try {
        await modoCamara.iniciar();
    } catch (err) {
        console.warn('No se pudo abrir la cámara', err);
        const m = modoCamara;
        modoCamara = null;
        m.flujo?.getTracks().forEach((t) => t.stop());
        if (m.ancla) { m.cerrado = false; m.salir(); modoCamara = null; }
        const compat = await revisarCompatibilidad();
        if (err?.name === 'NotAllowedError' || err?.name === 'SecurityError') {
            // el permiso se negó (ahora o antes): el aviso dice cómo activarlo
            marcarPermisoNegado(compat);
            mostrarCompatibilidad(compat, 'La realidad aumentada no pudo iniciar porque la página no tiene permiso para usar la cámara.', {
                permisos: true,
                textoPermisos: 'Sin el permiso de la cámara la figura no se puede ver en tu espacio. Actívalo así:',
            });
        } else if (err?.name === 'NotReadableError' || err?.name === 'AbortError') {
            mostrarCompatibilidad(compat, 'La cámara está ocupada por otra app. Ciérrala y vuelve a intentarlo.', {
                permisos: true,
                textoPermisos: 'Si ninguna otra app la está usando, revisa que esta página tenga permiso para la cámara:',
            });
        } else if (err?.name === 'NotFoundError' || err?.name === 'OverconstrainedError') {
            mostrarCompatibilidad(compat, 'No se encontró una cámara que la página pueda usar en este celular.', {
                permisos: true,
                textoPermisos: 'Revisa también que esta página tenga permiso para la cámara:',
            });
        } else {
            mostrarCompatibilidad(compat, esChrome || esIOS
                ? 'No se pudo abrir la cámara de este celular. Abajo, lo que se revisó.'
                : 'Este navegador no deja usar la cámara desde la página. Ábrela en Chrome y vuelve a intentarlo.', { permisos: true });
        }
    }
}

/* ------------------------------------------------------------------ */
/* El botón                                                             */
/* ------------------------------------------------------------------ */

let abriendo = false;

async function verEnTuEspacio() {
    // La Vista rápida tiene que abrirse en el mismo toque (Safari no la abre
    // después de esperar): en iPhone con Vista rápida, solo lo que se sabe ya.
    if (AR.ios) {
        const v = versionDe(/OS (\d+)[_.](\d+)?/);
        if (v != null && v < 12) { mostrarCompatibilidad(await revisarCompatibilidad()); return; }
        abrirVistaRapida();
        return;
    }
    // Lo demás se revisa antes: si no se puede, el aviso en vez de un error
    const compat = await revisarCompatibilidad();
    if (compat.nivel === 'ninguna' || compat.nivel === 'permiso') { mostrarCompatibilidad(compat); return; }
    if (AR.iosSinVistaRapida) {
        // El permiso del giroscopio en iPhone se pide en el mismo toque, antes de nada
        await pedirPermisoGiroscopio();
        if (compat.camaraPosible) await abrirModoCamara();
        else mostrarCompatibilidad(compat);
        return;
    }
    // Un segundo toque mientras arranca abriría dos sesiones: se ignora.
    if (abriendo) return;
    abriendo = true;
    try {
        $('ra-android').hidden = true;
        await deteccionXR;
        // En gama baja, directo a Scene Viewer: nativo y mucho más liviano.
        if (AR.webxr && ajustes.webxr && figura.listo) {
            try {
                await new SesionWebXR().iniciar();
                return;
            } catch (err) {
                // Se negó el permiso de la cámara (no un toque vencido): Scene
                // Viewer también la necesitaría. Se avisa qué activar.
                if ((err?.name === 'NotAllowedError' || err?.name === 'SecurityError') && !/activation|gesture/i.test(err?.message || '')) {
                    console.warn('WebXR: permiso de la cámara negado', err);
                    marcarPermisoNegado(compat);
                    mostrarCompatibilidad(compat, 'La realidad aumentada no pudo iniciar porque no se dio permiso para usar la cámara.', {
                        permisos: true,
                        textoPermisos: 'Sin el permiso de la cámara la figura no se puede ver en tu espacio. Actívalo así:',
                    });
                    return;
                }
                console.warn('WebXR no arrancó; se prueba Scene Viewer', err);
            }
        }
        // El navegador dice que este teléfono no tiene ARCore: ni WebXR ni
        // Scene Viewer pueden abrir la cámara con realidad aumentada (Scene
        // Viewer solo mostraría la figura en 3D). Se explica por qué y se
        // ofrece el modo cámara.
        if ((esChrome || (AR.xrContesto && !esBrave)) && !AR.webxr) {
            mostrarCompatibilidad(compat);
            return;
        }
        abrirSceneViewer();
    } finally {
        setTimeout(() => { abriendo = false; }, 1500);
    }
}

/**
 * En iPhone, el .usdz se baja mientras la persona mira la figura: al tocar el
 * botón, la Vista rápida lo toma ya descargado y abre sin la barra de espera.
 * No se hace con «ahorro de datos» activado.
 */
function adelantarUsdz() {
    if (perfil.ahorroDatos) return;
    const bajar = () => fetch(urlAbsoluta(datos.urls.usdz), { priority: 'low' }).catch(() => {});
    if (document.readyState === 'complete') setTimeout(bajar, 600);
    else window.addEventListener('load', () => setTimeout(bajar, 600), { once: true });
}

const boton = $('ra-boton');
if (esTelefono) {
    boton.addEventListener('click', verEnTuEspacio);
    $('ra-compat-abrir').hidden = false;
    $('ra-compat-abrir').addEventListener('click', async () => mostrarCompatibilidad(await revisarCompatibilidad()));
    if (AR.ios) adelantarUsdz();
    if (AR.iosSinVistaRapida) {
        // iOS 17 abre Safari con este esquema; en los anteriores, el menú ··· de la app
        $('ra-safari').href = `x-safari-https://${window.location.host}${window.location.pathname}`;
        $('ra-safari-aviso').hidden = false;
    }
    if (AR.android) {
        deteccionXR = detectarWebXR();
        if (!window.isSecureContext) {
            avisar('Esta página no está abierta con https: Android no abre la realidad aumentada así. Escanea el QR otra vez desde la galería (con el túnel encendido) o usa el enlace del sitio publicado.');
        } else if (enOtraApp) {
            // Se avisa desde ya: en el navegador interno de un lector de QR casi
            // nunca se abre la cámara de realidad aumentada.
            $('ra-chrome').href = `intent://${window.location.host}${window.location.pathname}#Intent;scheme=https;package=com.android.chrome;end`;
            $('ra-chrome-aviso').hidden = false;
        }
    }
} else {
    // Computador: el QR para seguir en el teléfono (o el aviso si no serviría).
    boton.hidden = true;
    $('ra-ayuda').hidden = true;
    if (datos.qrAlcanzable === false) {
        avisar('Esta página está abierta como localhost y el enlace público está apagado: un QR no abriría en el teléfono. Abre la galería y toca «Encender el QR para el celular».');
    } else {
        const qr = $('ra-qr');
        qr.querySelector('img').src = datos.qr;
        qr.hidden = false;
    }
}

if (window.location.hash === '#camara' && (AR.android || esIOS)) {
    // Viene del botón de la galería en un celular sin realidad aumentada
    // completa: se explica por qué y se ofrece el modo cámara.
    history.replaceState(null, '', window.location.pathname);
    revisarCompatibilidad().then((c) => mostrarCompatibilidad(c));
} else if (window.location.hash === '#sin-ar' || window.location.hash === '#camara') {
    history.replaceState(null, '', window.location.pathname);
    if (AR.android) mostrarAyudaAndroid('sin-ar');
    else avisar('Este teléfono no pudo abrir la realidad aumentada. Mientras tanto, puedes girar la figura aquí.');
}

if (hayWebGL()) {
    mostrarFigura().catch((e) => console.error('No se pudo mostrar la figura en 3D; queda la foto.', e));
}
