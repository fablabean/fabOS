<?php

namespace App\Http\Controllers;

use App\Models\CandidateBatch;
use App\Models\User;
use App\Services\Projects\LoteCompartido;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * La evaluación de un lote, para quien no entra al panel (§11).
 *
 * Se llega por el enlace firmado que genera el laboratorio —vale noventa
 * días y funciona sin cuenta— o con la sesión del backoffice. Es de solo
 * lectura: quien ve el resultado no evalúa; evaluar es del panel.
 */
class LoteCompartidoController extends Controller
{
    public function __construct(private LoteCompartido $compartido) {}

    public function ver(Request $request, CandidateBatch $batch)
    {
        abort_unless($this->puedeVerlo($request), 403);

        $tabla = $this->compartido->tabla($batch);

        return view('lotes.compartido', [
            'lote'     => $batch,
            'columnas' => $tabla['columnas'],
            'filas'    => $tabla['filas'],
            'extras'   => $tabla['extras'],
            'graficas' => $this->compartido->graficas($batch),
            'filtros'  => $this->compartido->filtros($batch),
            'csv'      => $request->hasValidSignature()
                ? $this->compartido->enlaceCsv($batch)
                : route('lotes.compartido.csv', $batch),
        ]);
    }

    public function csv(Request $request, CandidateBatch $batch)
    {
        abort_unless($this->puedeVerlo($request), 403);

        $nombre = Str::slug($batch->name) . '-evaluacion.csv';

        return response($this->compartido->csv($batch), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
        ]);
    }

    private function puedeVerlo(Request $request): bool
    {
        return $request->hasValidSignature()
            || ($request->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false);
    }
}
