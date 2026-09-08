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
    public function ver(Request $request, string $ruta)
    {
        $quien = $request->user();

        abort_unless(
            $quien && $quien->status === 'activo'
                && $quien->hasAnyRole([...User::ROLES_BACKOFFICE, User::ROL_COMUNICACIONES]),
            403,
        );

        abort_unless(ArchivoPrivado::permitida($ruta), 404);

        $disco = Storage::disk('local');

        abort_unless($disco->exists($ruta), 404);

        $mime = (string) $disco->mimeType($ruta);

        return $disco->response($ruta, basename($ruta), [
            'Cache-Control' => 'private, max-age=600',
            // Solo las imagenes se abren en linea: un archivo subido por
            // cualquiera desde un formulario publico, servido en linea, es
            // una pagina que se ejecuta en nuestro dominio.
            'Content-Disposition' => str_starts_with($mime, 'image/')
                ? 'inline'
                : 'attachment; filename="' . addslashes(basename($ruta)) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
