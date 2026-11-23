<?php

namespace App\Http\Controllers;

use App\Services\Qr\QrRenderer;
use App\Support\Galeria3d;
use App\Support\Galeria3dContadores;
use App\Support\TunelCelular;
use Illuminate\Http\Request;

/**
 * La Galería 3D, vista desde fuera.
 *
 * Una sola página con el visor arriba y la colección debajo. Cada modelo tiene
 * además su propia dirección (/galeria-3d/{modelo}): es la que se comparte, la
 * que lleva el código QR para abrirlo en el teléfono y la que indexan los
 * buscadores. Es la misma página con ese modelo ya cargado; al elegir otro en
 * la colección, el visor lo cambia sin recargar y la dirección se actualiza.
 *
 * Sin cuenta, como el resto de lo público: mirar no debería costar un registro.
 */
class Galeria3dController extends Controller
{
    public function index()
    {
        $modelos = Galeria3d::modelos();
        abort_if($modelos->isEmpty(), 404);

        return $this->pagina($modelos->first()['slug'], false);
    }

    public function show(string $modelo)
    {
        abort_unless(Galeria3d::modelo($modelo), 404);

        return $this->pagina($modelo, true);
    }

    /**
     * El QR que abre el modelo en el teléfono, para quien mira desde un
     * computador y quiere verlo en realidad aumentada. SVG: se ve nítido a
     * cualquier tamaño y no hace falta generar imágenes.
     */
    public function qr(string $modelo, QrRenderer $qr)
    {
        abort_unless(Galeria3d::modelo($modelo), 404);

        // Lleva a la experiencia de realidad aumentada de esa figura.
        $cabeceras = [
            'Content-Type'  => 'image/svg+xml',
            // Corto: si cambia la dirección pública, el QR viejo no debe quedarse.
            'Cache-Control' => 'public, max-age=300',
        ];
        if (request()->boolean('descargar')) {
            $cabeceras['Content-Disposition'] = 'attachment; filename="qr-' . $modelo . '.svg"';
        }

        return response($qr->svg(Galeria3d::urlRA($modelo), 240, '#111111'), 200, $cabeceras);
    }

    /**
     * La experiencia de realidad aumentada de una figura: a donde lleva su QR.
     * Una página para el teléfono, sin menú: la figura girando con su
     * animación y un solo botón para verla en el espacio.
     */
    public function ra(string $modelo)
    {
        $m = Galeria3d::modelo($modelo);
        abort_unless($m, 404);

        return view('publico.galeria-3d-ra', [
            'm'            => $m,
            'qr'           => route('galeria.qr', ['modelo' => $modelo, 'b' => substr(md5(Galeria3d::baseQr()), 0, 8)]),
            'qrAlcanzable' => Galeria3d::qrAlcanzable(),
        ]);
    }

    /*
     * El túnel del celular, desde la ficha: «Encender el QR para el celular».
     * Solo en el computador de desarrollo (APP_ENV=local) y pedido desde él
     * mismo (localhost o la red), nunca por el propio túnel.
     */

    private function soloDesdeEsteComputador(Request $request): void
    {
        abort_unless(app()->environment('local') && Galeria3d::hostLocal($request->getHost()), 403);
    }

    private function estado(): array
    {
        $enlace = TunelCelular::enlace(true);

        return [
            'enlace'    => $enlace,
            'corriendo' => TunelCelular::corriendo(),
            'error'     => $enlace ? null : (TunelCelular::explicacion() ?? TunelCelular::ultimoError()),
            'version'   => $enlace ? substr(md5($enlace), 0, 8) : null,
        ];
    }

    public function estadoTunel(Request $request)
    {
        $this->soloDesdeEsteComputador($request);

        return response()->json($this->estado());
    }

    public function encenderTunel(Request $request)
    {
        $this->soloDesdeEsteComputador($request);

        try {
            // El puerto en el que escucha esta aplicación dentro del contenedor.
            TunelCelular::encender((int) ($request->server('SERVER_PORT') ?: 80));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()] + $this->estado(), 422);
        }

        return response()->json($this->estado());
    }

    /**
     * Cuenta una vista, un «me gusta» (o lo quita) o una descarga de una
     * figura, y devuelve sus cifras. Sin cuentas de usuario: la sesión del
     * navegador evita que recargar o tocar varias veces infle las cifras.
     */
    public function contar(string $modelo, \Illuminate\Http\Request $request)
    {
        abort_unless(Galeria3d::modelo($modelo), 404);
        $accion = $request->validate(['accion' => 'required|in:vista,me-gusta,ya-no,descarga'])['accion'];

        match ($accion) {
            'vista'    => Galeria3dContadores::vista($modelo),
            'me-gusta' => Galeria3dContadores::meGusta($modelo, true),
            'ya-no'    => Galeria3dContadores::meGusta($modelo, false),
            'descarga' => Galeria3dContadores::descarga($modelo),
        };

        return response()->json(Galeria3dContadores::de($modelo) + [
            'meGusta' => in_array($modelo, Galeria3dContadores::meGustaDeEstaSesion(), true),
        ]);
    }

    private function pagina(string $actual, bool $esFicha)
    {
        return view('publico.galeria-3d', [
            'modelos'      => Galeria3d::modelos(),
            'categorias'   => Galeria3d::filtros(),
            'contadores'   => Galeria3dContadores::todos(),
            'meGusta'      => Galeria3dContadores::meGustaDeEstaSesion(),
            'actual'       => Galeria3d::modelo($actual),
            'esFicha'      => $esFicha,
            'optimizacion' => Galeria3d::resumenOptimizacion(),
        ]);
    }
}
