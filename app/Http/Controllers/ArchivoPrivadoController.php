<?php

namespace App\Http\Controllers;

use App\Filament\Componentes\ArchivoPrivado;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Sirve al panel un archivo del disco privado (§11).
 *
 * Es la puerta por la que el campo de subida enseña la vista previa de lo
 * que ya esta guardado. Solo para quien entra al panel, y solo de los
 * directorios del trabajo de proyectos: nada mas del disco se asoma.
 */
class ArchivoPrivadoController extends Controller
{
    public function ver(Request $request)
    {
        $quien = $request->user();
        $ruta = (string) $request->query('ruta', '');

        abort_unless(
            $quien && $quien->status === 'activo'
                && $quien->hasAnyRole([...User::ROLES_BACKOFFICE, User::ROL_COMUNICACIONES]),
            403,
        );

        abort_unless(ArchivoPrivado::permitida($ruta), 404);

        $disco = Storage::disk('local');

        abort_unless($disco->exists($ruta), 404);

        $mime = (string) $disco->mimeType($ruta);

        // Con el nombre con que llego, si se sabe: «kitek+ziewa_stls.zip» y
        // no el aleatorio con que se guardo. Sin barras ni comillas, que el
        // nombre viene de la direccion y podria traer cualquier cosa.
        $nombre = trim(str_replace(['/', '\\', '"'], '', (string) $request->query('nombre', ''))) ?: basename($ruta);

        // Se descarga cuando se pide, o cuando no es una imagen: un archivo
        // subido por cualquiera desde un formulario publico, servido en
        // linea, es una pagina que se ejecuta en nuestro dominio.
        $descargar = $request->boolean('descargar') || ! str_starts_with($mime, 'image/');

        return $disco->response($ruta, $nombre, [
            'Cache-Control' => 'private, max-age=600',
            'Content-Disposition' => ($descargar ? 'attachment' : 'inline') . '; filename="' . $nombre . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
