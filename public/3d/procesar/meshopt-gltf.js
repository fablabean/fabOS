/*
 * Compresión EXT_meshopt_compression sobre un .glb ya exportado.
 *
 * GLTFExporter no comprime; esto toma su salida y codifica cada vista de
 * búfer con geometría (índices, atributos de vértice y claves de animación)
 * con el codificador de meshoptimizer. Las imágenes se dejan como están
 * (ya van en JPEG/PNG). El visor las descomprime con meshopt_decoder, que es
 * WebAssembly y tarda milisegundos: se gana en descarga sin perder en carga.
 *
 * Formato (especificación del EXT_meshopt_compression):
 *  - buffer 0: el BIN del .glb, con los datos comprimidos y las imágenes;
 *  - buffer 1: «de respaldo», sin datos, del tamaño de lo descomprimido;
 *  - cada vista comprimida apunta al buffer 1 y lleva en la extensión dónde
 *    están sus datos comprimidos dentro del buffer 0.
 */
import { MeshoptEncoder } from '../vendor/meshoptimizer/meshopt_encoder.module.js';

const TAM_COMPONENTE = { 5120: 1, 5121: 1, 5122: 2, 5123: 2, 5125: 4, 5126: 4 };
const NUM_COMPONENTES = { SCALAR: 1, VEC2: 2, VEC3: 3, VEC4: 4, MAT2: 4, MAT3: 9, MAT4: 16 };
const alinear = (n, a = 4) => Math.ceil(n / a) * a;

// Bits de mantisa del filtro exponencial por atributo (pérdida controlada).
// 16 bits en posiciones: error < 0,002 % del valor; invisible en pantalla.
const BITS = { POSITION: 16, NORMAL: 12, TANGENT: 12, TEXCOORD_0: 14, TEXCOORD_1: 14 };

export function leerGLB(arrayBuffer) {
    const dv = new DataView(arrayBuffer);
    if (dv.getUint32(0, true) !== 0x46546C67) throw new Error('No es un .glb');
    let off = 12, json = null, bin = new Uint8Array(0);
    while (off < arrayBuffer.byteLength) {
        const len = dv.getUint32(off, true), tipo = dv.getUint32(off + 4, true);
        const datos = new Uint8Array(arrayBuffer, off + 8, len);
        if (tipo === 0x4E4F534A) json = JSON.parse(new TextDecoder().decode(datos));
        else if (tipo === 0x004E4942) bin = datos.slice();
        off += 8 + len;
    }
    return { json, bin };
}

export function escribirGLB(json, bin) {
    const texto = new TextEncoder().encode(JSON.stringify(json));
    const lj = alinear(texto.length), lb = alinear(bin.length);
    const total = 12 + 8 + lj + (lb ? 8 + lb : 0);
    const out = new ArrayBuffer(total);
    const dv = new DataView(out), u8 = new Uint8Array(out);
    dv.setUint32(0, 0x46546C67, true); dv.setUint32(4, 2, true); dv.setUint32(8, total, true);
    dv.setUint32(12, lj, true); dv.setUint32(16, 0x4E4F534A, true);
    u8.set(texto, 20); for (let i = 20 + texto.length; i < 20 + lj; i++) u8[i] = 0x20;
    if (lb) {
        const o = 20 + lj;
        dv.setUint32(o, lb, true); dv.setUint32(o + 4, 0x004E4942, true);
        u8.set(bin, o + 8);
    }
    return out;
}

export async function comprimirMeshopt(arrayBuffer) {
    await MeshoptEncoder.ready;
    const { json, bin } = leerGLB(arrayBuffer);
    if (!json.bufferViews) return arrayBuffer;

    // ¿Para qué se usa cada vista?
    const uso = new Map();
    const marcar = (accIdx, modo, atributo) => {
        const acc = json.accessors[accIdx];
        if (acc.bufferView === undefined || acc.sparse) return;
        if (uso.has(acc.bufferView)) { uso.get(acc.bufferView).invalida = true; return; }
        uso.set(acc.bufferView, { modo, atributo, acc });
    };
    for (const malla of json.meshes || []) {
        for (const prim of malla.primitives) {
            for (const [nombre, acc] of Object.entries(prim.attributes)) marcar(acc, 'ATTRIBUTES', nombre);
            if (prim.indices !== undefined) marcar(prim.indices, (prim.mode ?? 4) === 4 ? 'TRIANGLES' : 'INDICES', 'indices');
        }
    }
    for (const anim of json.animations || []) {
        for (const s of anim.samplers) { marcar(s.input, 'ATTRIBUTES', 'tiempo'); marcar(s.output, 'ATTRIBUTES', 'clave'); }
    }

    const trozos = [];
    let largo = 0, respaldo = 0;
    const poner = (datos) => {
        const o = largo;
        trozos.push({ o, datos });
        largo = alinear(o + datos.length);
        return o;
    };

    json.bufferViews.forEach((bv, i) => {
        const original = bin.subarray(bv.byteOffset || 0, (bv.byteOffset || 0) + bv.byteLength);
        const u = uso.get(i);
        let comprimir = u && !u.invalida;
        let stride = 0, count = 0;
        if (comprimir) {
            const acc = u.acc;
            const tamElem = TAM_COMPONENTE[acc.componentType] * NUM_COMPONENTES[acc.type];
            if (u.modo === 'ATTRIBUTES') {
                stride = bv.byteStride || alinear(tamElem);
                count = acc.count;
                comprimir = stride % 4 === 0 && stride <= 256 && count * stride <= bv.byteLength;
            } else {
                stride = TAM_COMPONENTE[acc.componentType];
                count = acc.count;
                comprimir = (stride === 2 || stride === 4) && (u.modo !== 'TRIANGLES' || count % 3 === 0);
            }
        }
        if (!comprimir) {
            bv.byteOffset = poner(original);
            bv.buffer = 0;
            return;
        }

        let fuente = original.slice(0, count * stride);
        let filtro;
        const bits = BITS[u.atributo];
        if (u.modo === 'ATTRIBUTES' && bits && u.acc.componentType === 5126) {
            const f32 = new Float32Array(fuente.buffer, fuente.byteOffset, (count * stride) / 4);
            fuente = MeshoptEncoder.encodeFilterExp(f32, count, stride, bits);
            filtro = 'EXPONENTIAL';
        }
        const codificado = MeshoptEncoder.encodeGltfBuffer(fuente, count, stride, u.modo);
        const ext = { buffer: 0, byteOffset: poner(codificado), byteLength: codificado.length, byteStride: stride, count, mode: u.modo };
        if (filtro) ext.filter = filtro;
        bv.extensions = { ...(bv.extensions || {}), EXT_meshopt_compression: ext };
        bv.buffer = 1;
        bv.byteOffset = respaldo;
        respaldo = alinear(respaldo + bv.byteLength);
    });

    const nuevoBin = new Uint8Array(largo);
    for (const t of trozos) nuevoBin.set(t.datos, t.o);
    json.buffers = [{ byteLength: nuevoBin.length }];
    if (respaldo > 0) {
        json.buffers.push({ byteLength: respaldo, extensions: { EXT_meshopt_compression: { fallback: true } } });
        json.extensionsUsed = [...new Set([...(json.extensionsUsed || []), 'EXT_meshopt_compression'])];
        json.extensionsRequired = [...new Set([...(json.extensionsRequired || []), 'EXT_meshopt_compression'])];
    }
    json.asset = { ...json.asset, generator: (json.asset.generator || '') + ' + meshopt (fabOS galería 3D)' };
    return escribirGLB(json, nuevoBin);
}
