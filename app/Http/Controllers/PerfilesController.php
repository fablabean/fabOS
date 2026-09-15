<?php

namespace App\Http\Controllers;

use App\Models\ProfessionalProfile;
use App\Services\Personas\EntregaALaUniversidad;
use Illuminate\Http\Request;

/**
 * La hoja de un perfil profesional (§5).
 *
 * Vive detrás de la sesión y no de un enlace firmado, al revés que el resumen
 * de un lote de candidatos: aquí hay cédulas, RUT y certificaciones bancarias.
 * Un enlace se reenvía solo, y estos datos solo los ve quien entra al panel.
 */
class PerfilesController extends Controller
{
    public function hoja(Request $request, ProfessionalProfile $profile, EntregaALaUniversidad $entrega)
    {
        abort_unless($request->user()?->can('view', $profile), 403);

        return $entrega->hojas(collect([$profile]));
    }
}
