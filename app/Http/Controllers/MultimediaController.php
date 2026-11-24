<?php

namespace App\Http\Controllers;

use App\Models\ArchivoMultimedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve un archivo de la carpeta multimedia.
 *
 * - **Para cualquier origen (CORS)**: la animación corre en un iframe aislado
 *   cuyo origen es «null»; sin este permiso, `fetch` del STL falla.
 * - **Con rangos**: un video se puede adelantar sin descargarlo entero.
 * - **Inerte si se abre solo**: `sandbox` y `nosniff`, para que un SVG con
 *   código no corra con la sesión de quien lo abra en el dominio del lab.
 *
 * Es público a propósito: lo que lleva una página publicada lo ve cualquiera,
 * y la dirección no se adivina (un código de 10 letras).
 */
class MultimediaController extends Controller
{
    public function __invoke(string $codigo, ?string $nombre = null): Response
    {
        $archivo = ArchivoMultimedia::where('codigo', $codigo)->firstOrFail();
        $disco = Storage::disk('local');

        abort_unless($archivo->archivo && $disco->exists($archivo->archivo), 404);

        $respuesta = new BinaryFileResponse($disco->path($archivo->archivo), 200, [
            'Content-Type' => $archivo->tipoServido(),
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'",
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ], public: true, autoEtag: true, autoLastModified: true);

        $respuesta->setContentDisposition('inline', $archivo->nombreEnLaDireccion(), 'archivo.' . ($archivo->extension() ?: 'bin'));

        return $respuesta;
    }
}
