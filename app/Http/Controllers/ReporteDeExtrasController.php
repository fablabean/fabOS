<?php

namespace App\Http\Controllers;

use App\Models\ReporteDeExtras;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Volver a bajar un reporte de horas extras (§5): el mismo archivo que se
 * generó, sin tocar los saldos. Lo baja su dueño, o quien ve los reportes de
 * nómina en el panel.
 */
class ReporteDeExtrasController extends Controller
{
    public function descargar(Request $request, ReporteDeExtras $reporte)
    {
        $u = $request->user();
        abort_unless($u && ($reporte->user_id === $u->id || $u->can('view', $reporte)), 403);
        abort_unless($reporte->tieneArchivo() && Storage::disk('local')->exists($reporte->archivo_path), 404);

        return Storage::disk('local')->download($reporte->archivo_path, $reporte->nombreDelArchivo(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
