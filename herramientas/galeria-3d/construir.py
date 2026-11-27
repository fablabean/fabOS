#!/usr/bin/env python3
"""
Construye los modelos de la Galería 3D y los deja en public/3d/modelos.

    python3 herramientas/galeria-3d/construir.py            # todos
    python3 herramientas/galeria-3d/construir.py jarron     # solo uno

Necesita Python 3 con Playwright (pip install playwright && playwright install
chromium). Levanta un servidor estático en la raíz del repositorio, abre
taller.html en un Chromium sin ventana (con WebGL por software, así funciona
también en un servidor sin tarjeta gráfica) y escribe lo que devuelve.

Al final imprime la tabla de antes/después y actualiza estadisticas.json,
que la página de la galería usa para la ficha técnica de cada modelo.
"""
import base64
import functools
import http.server
import json
import sys
import threading
from datetime import datetime, timezone
from pathlib import Path

from playwright.sync_api import sync_playwright

RAIZ = Path(__file__).resolve().parents[2]
SALIDA = RAIZ / "public" / "3d" / "modelos"


class Silencioso(http.server.SimpleHTTPRequestHandler):
    extensions_map = {**http.server.SimpleHTTPRequestHandler.extensions_map, ".js": "text/javascript"}

    def log_message(self, *args):
        pass


def servidor():
    handler = functools.partial(Silencioso, directory=str(RAIZ))
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


def kb(n):
    return f"{n / 1024:,.0f} KB".replace(",", ".")


def main(pedidos):
    SALIDA.mkdir(parents=True, exist_ok=True)
    archivo_estadisticas = SALIDA / "estadisticas.json"
    previas = json.loads(archivo_estadisticas.read_text("utf-8")) if archivo_estadisticas.exists() else {}

    srv = servidor()
    url = f"http://127.0.0.1:{srv.server_address[1]}/herramientas/galeria-3d/taller.html"
    with sync_playwright() as p:
        nav = p.chromium.launch(args=[
            "--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist",
        ])
        pagina = nav.new_page()
        errores = []
        pagina.on("pageerror", lambda e: errores.append(str(e)))
        pagina.on("console", lambda m: m.type == "error" and errores.append(m.text))
        pagina.goto(url)
        pagina.wait_for_function("window.listo === true", timeout=60_000)
        if errores:
            raise SystemExit("El taller no cargó:\n" + "\n".join(errores))

        slugs = pedidos or pagina.evaluate("window.modelos")
        estadisticas = dict(previas)
        print(f"{'modelo':<14}{'triángulos':>22}{'llamadas':>12}{'glb':>24}{'ar.glb':>10}{'usdz':>10}")
        for slug in slugs:
            r = pagina.evaluate("s => window.construir(s)", slug)
            for nombre, b64 in r["archivos"].items():
                (SALIDA / nombre).write_bytes(base64.b64decode(b64))
            e = r["estadisticas"]
            estadisticas[slug] = e
            f, o = e["fuente"], e["optimizado"]
            print(
                f"{slug:<14}"
                f"{f['triangulos']:>10,} → {o['triangulos']:>8,}"
                f"{f['llamadas']:>5} → {o['llamadas']:<4}"
                f"{kb(f['bytes']):>11} → {kb(o['bytes']):>9}"
                f"{kb(o['bytesAR']):>10}{kb(o['bytesUSDZ']):>10}".replace(",", ".")
            )
        nav.close()
    srv.shutdown()

    estadisticas = {k: estadisticas[k] for k in sorted(estadisticas)}
    estadisticas["_generado"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
    archivo_estadisticas.write_text(json.dumps(estadisticas, ensure_ascii=False, indent=2) + "\n", "utf-8")
    if errores:
        print("\nAvisos de la consola:\n  " + "\n  ".join(errores))


if __name__ == "__main__":
    main(sys.argv[1:])
