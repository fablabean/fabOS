<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * El informe de cierre imprimible (§17).
 *
 * Es lo que se le entrega a la Universidad. Va con sesión y restringido al
 * backoffice: son datos agregados de operación, no información pública.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $informes) {}

    public function cierre(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);

        $tz = config('fabos.lab.timezone');

        $datos = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        // Sin fechas, el mes en curso: es el cierre que más se pide.
        [$desde, $hasta] = isset($datos['desde'], $datos['hasta'])
            ? [
                Carbon::parse($datos['desde'], $tz)->startOfDay(),
                Carbon::parse($datos['hasta'], $tz)->endOfDay(),
            ]
            : $this->informes->mesDe(Carbon::now($tz));

        return view('informes.cierre', [
            'informe' => $this->informes->generar($desde, $hasta),
        ]);
    }
}
