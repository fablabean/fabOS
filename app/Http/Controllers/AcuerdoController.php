<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\Projects\AcuerdoDeServicio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * La vista previa del acuerdo de servicio, antes de generarlo (§11).
 *
 * El formulario del panel deja lo que se escribió en la caché, con una clave
 * de un solo uso, y esta página lo pinta tal como saldrá. Así se lee entero
 * —con sus cláusulas, sus fechas y su valor— antes de que exista el PDF y de
 * que se le mande a nadie.
 */
class AcuerdoController extends Controller
{
    public function vista(Request $request, Project $project, string $token)
    {
        $quien = $request->user();

        abort_unless(
            $quien instanceof User && $quien->hasAnyRole(User::ROLES_BACKOFFICE),
            403,
        );

        $datos = Cache::get('acuerdo:' . $token);

        abort_unless(is_array($datos) && (int) ($datos['project_id'] ?? 0) === $project->id, 404);

        $acuerdo = app(AcuerdoDeServicio::class);

        if ($request->boolean('pdf')) {
            return Pdf::loadHTML($acuerdo->render($project, $datos, paraPdf: true))
                ->setPaper('letter')
                ->stream('acuerdo-' . strtolower($project->code) . '.pdf');
        }

        return response($acuerdo->render($project, $datos));
    }
}
