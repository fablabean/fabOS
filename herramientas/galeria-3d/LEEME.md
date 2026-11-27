# Taller de la Galería 3D

Aquí se construyen los **modelos de ejemplo** de `/galeria-3d`, que están
hechos con código. Los modelos del laboratorio no pasan por aquí: se suben
desde el panel (Comunicaciones → Galería 3D → Subir modelo).

```bash
pip install playwright && playwright install chromium   # una vez
python3 herramientas/galeria-3d/construir.py            # todos los modelos
python3 herramientas/galeria-3d/construir.py jarron     # solo uno
```

Escribe en `public/3d/modelos/` el `.glb` web, el `-ar.glb`, el `.usdz`, la
foto `.webp` y `estadisticas.json`, e imprime la tabla de antes/después.

| Archivo | Qué hace |
|---|---|
| `modelos/*.js` | Cada modelo, generado con código (medidas en metros). |
| `texturas.js` | Texturas procedurales: madera, capas de impresión, metal… |
| `piezas.js` | Engranajes, uniones dentadas, UV, animación. |
| `taller.js` / `taller.html` | Arma cada modelo y lo pasa por `public/3d/procesar/empaquetar.js` (optimizar, exportar, validar, foto): el mismo código que usa el panel. |
| `construir.py` | Lo ejecuta en un Chromium sin ventana y guarda los archivos. |

La guía completa —cómo añadir un modelo, la realidad aumentada, los
requisitos del servidor— está en [`docs/GALERIA-3D.md`](../../docs/GALERIA-3D.md).
