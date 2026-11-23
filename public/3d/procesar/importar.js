/*
 * Abrir un archivo 3D cualquiera y dejarlo listo para la galería.
 *
 * Lo que sale de aquí es siempre lo mismo, venga del formato que venga:
 * una escena en METROS, con Y hacia arriba, centrada y apoyada en y = 0
 * —así queda sobre el suelo en realidad aumentada— y con materiales PBR
 * (MeshStandardMaterial), que es lo único que entienden el .glb y el .usdz.
 *
 * Todo pasa en el navegador de quien sube el modelo: el archivo original
 * puede pesar cientos de megas y nunca viaja al servidor; al servidor solo
 * llega lo ya optimizado.
 *
 * Formatos:
 *   .glb .gltf (+ .bin y texturas)  materiales, texturas y animaciones
 *   .fbx                            materiales, texturas y animaciones
 *   .obj (+ .mtl y texturas)        materiales y texturas
 *   .3mf                            colores (impresión 3D a color)
 *   .stl                            solo forma: el color se elige al subirlo
 *   .step .stp .iges .igs           CAD, convertido con OpenCascade (WASM)
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { MTLLoader } from 'three/addons/loaders/MTLLoader.js';
import { FBXLoader } from 'three/addons/loaders/FBXLoader.js';
import { ThreeMFLoader } from 'three/addons/loaders/3MFLoader.js';
import { unzipSync, strFromU8 } from 'three/addons/libs/fflate.module.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import { toCreasedNormals } from 'three/addons/utils/BufferGeometryUtils.js';

/** Extensión → formato. El orden decide cuál es el principal si se sueltan varios. */
export const FORMATOS = {
    glb: 'glTF binario', gltf: 'glTF', fbx: 'FBX', '3mf': '3MF', obj: 'OBJ', stl: 'STL',
    step: 'STEP', stp: 'STEP', iges: 'IGES', igs: 'IGES',
};

/** Los que se reconocen pero no se pueden abrir, con qué hacer. */
const NO_SOPORTADOS = {
    sldprt: 'SolidWorks', sldasm: 'SolidWorks', slddrw: 'SolidWorks', f3d: 'Fusion 360', f3z: 'Fusion 360',
    ipt: 'Inventor', iam: 'Inventor', skp: 'SketchUp', blend: 'Blender', '3ds': '3ds Max', max: '3ds Max',
    c4d: 'Cinema 4D', dwg: 'AutoCAD', dxf: 'AutoCAD', catpart: 'CATIA', prt: 'Creo/NX', x_t: 'Parasolid', usdz: 'USDZ',
};
const EXPORTAR_DESDE = {
    SolidWorks: 'Archivo → Guardar como → STEP (*.step) o STL',
    'Fusion 360': 'Archivo → Exportar → STEP o 3MF',
    Inventor: 'Archivo → Exportar → Formato CAD → STEP',
    SketchUp: 'Archivo → Exportar → Modelo 3D → OBJ o glTF (con extensión)',
    Blender: 'Archivo → Exportar → glTF 2.0 (.glb)',
    '3ds Max': 'Archivo → Exportar → FBX',
    'Cinema 4D': 'Archivo → Exportar → FBX o glTF',
    AutoCAD: 'exporta el sólido como STEP o STL (comando STEPOUT o STLOUT); los planos 2D no son modelos 3D',
    CATIA: 'Archivo → Guardar como → STEP',
    'Creo/NX': 'Archivo → Guardar copia → STEP',
    Parasolid: 'expórtalo como STEP desde el programa que lo creó',
    USDZ: 'súbelo en su formato original (glb, fbx, obj…): el .usdz lo genera la galería sola',
};

/** Factores a metros. */
export const UNIDADES = { mm: 0.001, cm: 0.01, m: 1, in: 0.0254 };

/**
 * La unidad que declara un 3MF (<model unit="…">, milímetros si no dice).
 * Devuelve la clave de UNIDADES; micras y pies no están en el selector, así
 * que llevan `escala` para pasarlos a milímetros. null si no se puede leer.
 */
export function unidadDe3MF(datos) {
    try {
        const zip = unzipSync(new Uint8Array(datos), { filter: (f) => /\.model$/i.test(f.name) || /(^|\/)\.rels$/i.test(f.name) });
        const rels = Object.keys(zip).find((n) => /^_rels\/\.rels$/i.test(n));
        const raiz = rels && /Target="\/?([^"]+\.model)"/i.exec(strFromU8(zip[rels]))?.[1];
        const nombre = (raiz && Object.keys(zip).find((n) => n.toLowerCase() === raiz.toLowerCase())) || Object.keys(zip).find((n) => /\.model$/i.test(n));
        if (!nombre) return null;
        const cabecera = strFromU8(zip[nombre].subarray(0, 4096));
        const unidad = (/<model\b[^>]*\bunit\s*=\s*"([a-z]+)"/i.exec(cabecera)?.[1] || 'millimeter').toLowerCase();
        return {
            millimeter: { clave: 'mm' }, centimeter: { clave: 'cm' }, meter: { clave: 'm' }, inch: { clave: 'in' },
            micron: { clave: 'mm', escala: 0.001 }, foot: { clave: 'mm', escala: 304.8 },
        }[unidad] || { clave: 'mm' };
    } catch (e) {
        return null;
    }
}

/**
 * Las unidades más probables. Un STL no dice en qué está: casi siempre en
 * milímetros, pero hay quien lo exporta en metros o en pulgadas. Se prueba la
 * sugerida y, si da un tamaño absurdo para algo que se fabrica en un
 * laboratorio (menos de 5 mm o más de 5 m), la primera que dé algo razonable.
 */
export function unidadesProbables(escena, sugeridas) {
    const caja = new THREE.Box3().setFromObject(escena);
    const s = caja.getSize(new THREE.Vector3());
    const mayor = Math.max(s.x, s.y, s.z);
    if (!isFinite(mayor) || mayor <= 0) return sugeridas;
    const razonable = (u) => { const m = mayor * UNIDADES[u]; return m >= 0.005 && m <= 5; };
    if (razonable(sugeridas)) return sugeridas;
    return ['mm', 'cm', 'in', 'm'].find(razonable) || sugeridas;
}

const ext = (nombre) => nombre.toLowerCase().split('.').pop();
const base = (nombre) => nombre.split(/[\\/]/).pop().toLowerCase();

/** Lo que se sugiere al abrir cada formato. */
function sugerencias(formato) {
    switch (formato) {
        case 'stl': case 'obj': case '3mf': return { unidades: 'mm', zArriba: formato !== 'obj' };
        case 'fbx': return { unidades: 'cm', zArriba: false };
        case 'step': case 'stp': case 'iges': case 'igs': return { unidades: 'm', zArriba: true };   // OpenCascade ya entrega metros
        default: return { unidades: 'm', zArriba: false };
    }
}

/* ------------------------------------------------------------------ */
/* Materiales                                                           */
/* ------------------------------------------------------------------ */

/** Acabados para lo que llega sin color (STL, STEP sin colores, OBJ sin .mtl). */
export const ACABADOS_BASE = {
    'pla-mate':      { nombre: 'PLA mate', rugosidad: 0.62, metal: 0 },
    'pla-brillante': { nombre: 'PLA brillante', rugosidad: 0.32, metal: 0 },
    'pla-seda':      { nombre: 'PLA seda', rugosidad: 0.3, metal: 0.45 },
    resina:          { nombre: 'Resina', rugosidad: 0.15, metal: 0, barniz: 0.8 },
    metal:           { nombre: 'Metal', rugosidad: 0.32, metal: 1 },
    madera:          { nombre: 'Madera / MDF', rugosidad: 0.8, metal: 0 },
};

export function materialBase(color = '#0d6e63', acabado = 'pla-mate', nombre) {
    const a = ACABADOS_BASE[acabado] || ACABADOS_BASE['pla-mate'];
    const m = new THREE.MeshPhysicalMaterial({
        name: nombre || a.nombre, color, roughness: a.rugosidad, metalness: a.metal,
        clearcoat: a.barniz || 0, clearcoatRoughness: 0.1,
    });
    m.userData.base = true;
    return m;
}

/** Phong, Lambert y compañía → Standard: el .glb y el .usdz solo entienden PBR. */
function aPBR(m) {
    if (m.isMeshStandardMaterial) return m;
    const brillo = m.shininess ?? 30;
    const n = new THREE.MeshStandardMaterial({
        name: m.name || 'Material',
        color: m.color ? m.color.clone() : new THREE.Color('#cccccc'),
        map: m.map || null,
        normalMap: m.normalMap || null,
        alphaMap: m.alphaMap || null,
        aoMap: m.aoMap || null,
        emissive: m.emissive ? m.emissive.clone() : new THREE.Color(0),
        emissiveMap: m.emissiveMap || null,
        roughness: Math.min(1, Math.max(0.05, Math.sqrt(2 / (brillo + 2)) * 1.6)),
        metalness: 0,
        transparent: m.transparent || m.opacity < 1,
        opacity: m.opacity ?? 1,
        side: m.side ?? THREE.FrontSide,
        vertexColors: m.vertexColors || false,
    });
    if (n.map) n.map.colorSpace = THREE.SRGBColorSpace;
    return n;
}

/* ------------------------------------------------------------------ */
/* Lectores                                                             */
/* ------------------------------------------------------------------ */

/** Un gestor de carga que encuentra las texturas y el .bin entre los archivos soltados. */
function gestor(archivos) {
    const urls = new Map();
    for (const f of archivos) urls.set(base(f.name), URL.createObjectURL(f));
    const m = new THREE.LoadingManager();
    m.setURLModifier((url) => {
        if (url.startsWith('blob:') || url.startsWith('data:')) return url;
        const nombre = base(decodeURIComponent(url));
        return urls.get(nombre) || url;
    });
    // Las texturas llegan después del modelo: hay que esperarlas antes de
    // exportar, o el .glb sale sin imágenes.
    let pendientes = 0;
    const inicio = m.itemStart.bind(m), fin = m.itemEnd.bind(m), fallo = m.itemError.bind(m);
    m.itemStart = (u) => { pendientes++; inicio(u); };
    m.itemEnd = (u) => { pendientes--; fin(u); };
    m.itemError = (u) => { fallo(u); };
    m.esperar = (maximo = 30000) => new Promise((ok) => {
        const t0 = performance.now();
        const mirar = () => (pendientes <= 0 || performance.now() - t0 > maximo ? ok() : setTimeout(mirar, 40));
        setTimeout(mirar, 0);
    });
    m.liberar = () => urls.forEach((u) => URL.revokeObjectURL(u));
    m.tiene = (nombre) => urls.has(base(nombre));
    return m;
}

let occtPromesa = null;
function cargarOCCT() {
    if (occtPromesa) return occtPromesa;
    const carpeta = new URL('../vendor/occt-import-js/', import.meta.url).href;
    occtPromesa = new Promise((ok, mal) => {
        const s = document.createElement('script');
        s.src = carpeta + 'occt-import-js.js';
        s.onload = () => window.occtimportjs({ locateFile: (f) => carpeta + f }).then(ok, mal);
        s.onerror = () => mal(new Error('No se pudo cargar el lector de CAD.'));
        document.head.appendChild(s);
    });
    return occtPromesa;
}

async function leerCAD(archivo, formato) {
    const occt = await cargarOCCT();
    const datos = new Uint8Array(await archivo.arrayBuffer());
    const params = { linearUnit: 'meter', linearDeflectionType: 'bounding_box_ratio', linearDeflection: 0.0015, angularDeflection: 0.35 };
    const r = formato.startsWith('st') ? occt.ReadStepFile(datos, params) : occt.ReadIgesFile(datos, params);
    if (!r.success || !r.meshes.length) throw new Error('El archivo CAD no tiene sólidos que se puedan convertir en malla.');

    const grupo = new THREE.Group();
    const porColor = new Map();
    const material = (rgb, nombre) => {
        if (!rgb) return null;
        const clave = rgb.map((c) => c.toFixed(3)).join(',');
        if (!porColor.has(clave)) porColor.set(clave, materialBase(new THREE.Color(...rgb), 'pla-mate', nombre || `Color ${porColor.size + 1}`));
        return porColor.get(clave);
    };
    let algunColor = false;
    r.meshes.forEach((m, i) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.Float32BufferAttribute(m.attributes.position.array, 3));
        if (m.attributes.normal) g.setAttribute('normal', new THREE.Float32BufferAttribute(m.attributes.normal.array, 3));
        g.setIndex(m.index.array);
        if (!m.attributes.normal) g.computeVertexNormals();
        // colores por cara: un grupo por cada tramo de triángulos del mismo color
        const mats = [];
        const caras = (m.brep_faces || []).filter((f) => f.color);
        if (caras.length) {
            algunColor = true;
            const general = material(m.color) || materialBase('#b9bcc2', 'pla-mate', 'Sin color');
            let cursor = 0;
            const indiceDe = (mat) => { let k = mats.indexOf(mat); if (k < 0) { mats.push(mat); k = mats.length - 1; } return k; };
            for (const f of m.brep_faces) {
                const mat = f.color ? material(f.color) : general;
                if (f.first > cursor) g.addGroup(cursor * 3, (f.first - cursor) * 3, indiceDe(general));
                g.addGroup(f.first * 3, (f.last - f.first + 1) * 3, indiceDe(mat));
                cursor = f.last + 1;
            }
            if (cursor * 3 < m.index.array.length) g.addGroup(cursor * 3, m.index.array.length - cursor * 3, indiceDe(general));
        } else if (m.color) {
            algunColor = true;
            mats.push(material(m.color));
        }
        const malla = new THREE.Mesh(g, mats.length > 1 ? mats : (mats[0] || null));
        malla.name = m.name || `Pieza ${i + 1}`;
        grupo.add(malla);
    });
    return { escena: grupo, animaciones: [], sinMateriales: !algunColor };
}

/**
 * Abre el archivo y devuelve la escena tal como venía, más lo que se sabe de ella.
 * Las unidades y la orientación se aplican después con `colocar`, para poder
 * cambiarlas sin volver a leer el archivo.
 */
export async function abrir(archivos) {
    archivos = [...archivos];
    if (!archivos.length) throw new Error('No se eligió ningún archivo.');

    for (const f of archivos) {
        const programa = NO_SOPORTADOS[ext(f.name)];
        if (programa) {
            throw new Error(`«${f.name}» es un archivo de ${programa}, que es privado y no se puede abrir fuera de ese programa. `
                + `Expórtalo así: ${EXPORTAR_DESDE[programa]}, y sube ese archivo.`);
        }
    }

    const principal = Object.keys(FORMATOS).map((e) => archivos.find((f) => ext(f.name) === e)).find(Boolean);
    if (!principal) {
        throw new Error('No hay ningún modelo 3D entre los archivos. Se aceptan: ' + [...new Set(Object.values(FORMATOS))].join(', ') + '.');
    }
    const formato = ext(principal.name);
    const g = gestor(archivos);
    const avisos = [];
    let escena, animaciones = [], sinMateriales = false, unidad3MF = null;

    try {
        if (formato === 'glb' || formato === 'gltf') {
            const loader = new GLTFLoader(g).setMeshoptDecoder(MeshoptDecoder);
            const datos = formato === 'glb' ? await principal.arrayBuffer() : await principal.text();
            const gltf = await new Promise((ok, mal) => loader.parse(datos, '', ok, mal));
            escena = gltf.scene; animaciones = gltf.animations;
        } else if (formato === 'fbx') {
            escena = new FBXLoader(g).parse(await principal.arrayBuffer(), '');
            animaciones = escena.animations || [];
        } else if (formato === '3mf') {
            const datos = await principal.arrayBuffer();
            try {
                escena = new ThreeMFLoader(g).parse(datos);
            } catch (err) {
                console.warn(err);
                throw new Error('No se pudo leer el 3MF: está dañado o usa partes del formato que el navegador no lee (por ejemplo, solo pintura por triángulo de Bambu Studio). Exporta de nuevo como 3MF estándar o como STL.');
            }
            if (!escena || !escena.children.length) throw new Error('El 3MF no trae ninguna pieza para mostrar.');
            unidad3MF = unidadDe3MF(datos);
            if (unidad3MF?.escala) {
                // micras o pies: se pasa a milímetros para el selector de unidades
                const envoltorio = new THREE.Group();
                envoltorio.add(escena);
                escena.scale.multiplyScalar(unidad3MF.escala);
                escena = envoltorio;
            }
        } else if (formato === 'obj') {
            const obj = new OBJLoader(g);
            const texto = await principal.text();
            const mtl = archivos.find((f) => ext(f.name) === 'mtl');
            const pideMtl = /^\s*mtllib\s+(.+)$/m.exec(texto);
            if (mtl) {
                const materiales = new MTLLoader(g).parse(await mtl.text(), '');
                materiales.preload();
                obj.setMaterials(materiales);
            } else {
                sinMateriales = true;
                if (pideMtl) avisos.push(`El OBJ usa «${pideMtl[1].trim()}» para sus materiales y no venía: súbelo junto con el .obj (y sus texturas) para ver los colores.`);
            }
            escena = obj.parse(texto);
        } else if (formato === 'stl') {
            const geo = new STLLoader().parse(await principal.arrayBuffer());
            const colores = !!geo.hasColors;
            const mat = colores ? new THREE.MeshStandardMaterial({ name: 'Color de la pieza', vertexColors: true, roughness: 0.6 }) : null;
            escena = new THREE.Group();
            const malla = new THREE.Mesh(geo, mat);
            malla.name = principal.name.replace(/\.[^.]+$/, '');
            escena.add(malla);
            sinMateriales = !colores;
        } else {
            ({ escena, animaciones, sinMateriales } = await leerCAD(principal, formato));
        }
        await g.esperar();
    } finally {
        // las texturas ya se leyeron; las URL temporales sobran
        setTimeout(() => g.liberar(), 30_000);
    }

    // Texturas pedidas que no llegaron: se quitan (sin imagen no se pueden
    // exportar) y se dice cuáles eran, para subirlas la próxima vez.
    const faltan = new Set();
    escena.traverse((o) => {
        if (!o.isMesh) return;
        for (const m of [].concat(o.material || [])) {
            if (!m) continue;
            for (const k of Object.keys(m)) {
                const t = m[k];
                if (!t || !t.isTexture) continue;
                const img = t.image;
                if (!img || !(img.width || img.videoWidth || img.data)) {
                    faltan.add(t.name || base(t.source?.data?.src || '') || k);
                    m[k] = null;
                    m.needsUpdate = true;
                }
            }
        }
    });
    if (faltan.size) avisos.push(`No se encontraron algunas texturas (${[...faltan].filter(Boolean).join(', ')}): el modelo se verá sin ellas. Súbelas junto con el modelo.`);

    // Un 3MF, OBJ o FBX con materiales «vacíos» (blancos o grises, sin
    // textura ni colores por vértice) es, en la práctica, un archivo sin color:
    // se le ofrece elegirlo, igual que a un STL.
    if (!sinMateriales && ['3mf', 'obj', 'fbx'].includes(formato)) sinMateriales = materialesVacios(escena);

    prepararMallas(escena, { sinMateriales });
    const sugerido = sugerencias(formato);
    // Un 3MF dice en qué unidades está (el STL no): se le cree.
    if (unidad3MF) sugerido.unidades = unidad3MF.clave;
    else if (!['step', 'stp', 'iges', 'igs'].includes(formato)) sugerido.unidades = unidadesProbables(escena, sugerido.unidades);
    return { escena, animaciones, formato, nombre: principal.name, sinMateriales, avisos, sugerido };
}

function materialesVacios(escena) {
    let vacios = true, alguno = false;
    escena.traverse((o) => {
        if (!o.isMesh) return;
        for (const m of [].concat(o.material || [])) {
            alguno = true;
            if (!m) continue;
            const gris = m.color && Math.abs(m.color.r - m.color.g) < 0.02 && Math.abs(m.color.g - m.color.b) < 0.02 && m.color.r > 0.6;
            if (m.map || m.vertexColors || o.geometry.attributes.color || !(m.name === '__DEFAULT' || gris)) vacios = false;
        }
    });
    return alguno && vacios;
}

/** Normales, materiales PBR y nombres: lo que todos los formatos necesitan igual. */
function prepararMallas(escena, { sinMateriales }) {
    let n = 0;
    const comun = materialBase();   // uno para todas: así se pueden juntar en una sola malla
    escena.traverse((o) => {
        if (!o.isMesh) return;
        n++;
        const g = o.geometry;
        if (!g.attributes.normal) g.computeVertexNormals();
        // STL y algunos OBJ traen una normal por triángulo: se ve facetado.
        // Las aristas vivas (más de 35°) se respetan; lo curvo se suaviza.
        if (!g.index && g.attributes.position.count > 60) {
            o.geometry = toCreasedNormals(g, THREE.MathUtils.degToRad(35));
        }
        if (!o.material || sinMateriales) {
            o.material = comun;
        } else {
            o.material = Array.isArray(o.material) ? o.material.map(aPBR) : aPBR(o.material);
        }
        if (!o.name) o.name = `Pieza ${n}`;
    });
    if (!n) throw new Error('El archivo no tiene ninguna malla 3D (¿es un dibujo 2D o solo curvas?).');
}

/** Cambia el color y el acabado de lo que llegó sin materiales. */
export function pintar(escena, color, acabado) {
    const nuevo = materialBase(color, acabado);
    escena.traverse((o) => {
        if (!o.isMesh) return;
        const cambiar = (m) => (m.userData?.base ? nuevo : m);
        o.material = Array.isArray(o.material) ? o.material.map(cambiar) : cambiar(o.material);
    });
}

/**
 * Pone la escena en metros, con Y arriba, centrada y apoyada en el suelo.
 * Devuelve un grupo nuevo (la escena original queda dentro, sin tocar) y las
 * medidas resultantes, para enseñárselas a quien sube el modelo.
 */
export function colocar(escena, { unidades = 'm', zArriba = false, giroX = 0, giroY = 0 } = {}) {
    const envoltorio = new THREE.Group();
    envoltorio.name = 'Modelo';
    const orientacion = new THREE.Group();
    orientacion.name = 'Orientación';
    if (escena.parent) escena.parent.remove(escena);
    orientacion.add(escena);
    envoltorio.add(orientacion);

    const f = UNIDADES[unidades] ?? 1;
    orientacion.scale.setScalar(f);
    // primero el giro sobre el eje vertical, después levantarlo: así «Girar»
    // siempre gira sobre el suelo, venga como venga el archivo
    orientacion.rotation.order = 'YXZ';
    orientacion.rotation.set((zArriba ? -Math.PI / 2 : 0) + giroX, giroY, 0);
    orientacion.updateMatrixWorld(true);

    const caja = new THREE.Box3().setFromObject(orientacion);
    const centro = caja.getCenter(new THREE.Vector3());
    orientacion.position.set(-centro.x, -caja.min.y, -centro.z);
    envoltorio.updateMatrixWorld(true);

    const tam = caja.getSize(new THREE.Vector3());
    const avisos = [];
    const mayor = Math.max(tam.x, tam.y, tam.z);
    if (mayor > 20) avisos.push(`Con estas unidades el modelo mide ${mayor.toFixed(1)} m de largo: ¿seguro que no está en milímetros?`);
    if (mayor < 0.003) avisos.push(`Con estas unidades el modelo mide ${(mayor * 1000).toFixed(2)} mm: ¿seguro que no está en metros?`);
    return { escena: envoltorio, medidas: tam, avisos };
}

/**
 * La «calidad» que se elige al subir: el máximo de triángulos. Si el modelo
 * pasa, se simplifica con un error de hasta el 1 % de su tamaño —invisible en
 * un escaneo o una pieza orgánica—; si no pasa, no se toca.
 */
export const CALIDADES = {
    alta: { nombre: 'Alta', triangulos: 150000, texturas: 2048 },
    equilibrada: { nombre: 'Equilibrada', triangulos: 60000, texturas: 1024 },
    ligera: { nombre: 'Ligera', triangulos: 25000, texturas: 1024 },
};

export function opcionesDeCalidad(escena, calidad = 'equilibrada') {
    const c = CALIDADES[calidad] || CALIDADES.equilibrada;
    let total = 0;
    const vistas = new Set();
    escena.traverse((o) => {
        if (!o.isMesh || vistas.has(o.geometry)) return;
        vistas.add(o.geometry);
        total += (o.geometry.index ? o.geometry.index.count : o.geometry.attributes.position.count) / 3;
    });
    const simplificacion = total > c.triangulos
        ? { umbral: 1500, proporcion: c.triangulos / total, error: 0.01, pesoNormales: 0.05 }
        : { umbral: Infinity };
    return { texturaMax: c.texturas, simplificacion, triangulos: Math.round(total) };
}
