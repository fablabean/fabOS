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
    /**
     * Como le llegaria un cobro al cliente: el correo maquetado, con el QR,
     * y la seccion «Pago» tal como la vera en su proyecto. Con lo escrito en
     * el formulario, que espera en la cache con una clave de un solo uso.
     */
    public function pago(Request $request, Project $project, string $token)
    {
        $quien = $request->user();

        abort_unless($quien instanceof User && $quien->hasAnyRole(User::ROLES_BACKOFFICE), 403);

        $datos = Cache::get('cobro:' . $token);

        abort_unless(is_array($datos) && (int) ($datos['project_id'] ?? 0) === $project->id, 404);

        $vista = app(\App\Services\Projects\PagosDeProyecto::class)->vistaPrevia(
            $project, (int) ($datos['valor'] ?? 0), $datos['concepto'] ?? null, $datos['mensaje'] ?? null, $quien,
        );

        return view('proyectos.pago-vista', [
            'proyecto' => $project,
            'vista'    => $vista,
            'valor'    => config('fabos.money.symbol') . number_format((float) ($datos['valor'] ?? 0), 0, ',', '.'),
            'concepto' => trim((string) ($datos['concepto'] ?? '')) ?: null,
            'qr'       => \App\Support\Settings::qrDePagos() ? route('pagos.qr') : null,
        ]);
    }

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
