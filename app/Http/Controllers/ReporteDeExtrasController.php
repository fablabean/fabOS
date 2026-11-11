<?php

namespace App\Http\Controllers;

use App\Models\ReporteDeExtras;
use App\Services\Booking\BookingException;
use App\Services\Staffing\FormatoAdf404;
use App\Services\Staffing\ReporteDeNomina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * El reporte de horas extras fuera del panel (§5).
 *
 * - **Volver a bajarlo**, con sesión: el mismo archivo, sin tocar los saldos.
 *   Lo baja su dueño, o quien ve los reportes de nómina en el panel.
 * - **El enlace de firma**, sin sesión, como en las actas de movimiento: es
 *   largo y aleatorio, y sirve para ver ese reporte y firmarlo. Le llega a
 *   quien coordina por correo; a quien lo generó, se le abre al generarlo.
 */
class ReporteDeExtrasController extends Controller
{
    public function __construct(private ReporteDeNomina $reportes) {}

    public function descargar(Request $request, ReporteDeExtras $reporte)
    {
        $u = $request->user();
        abort_unless($u && ($reporte->user_id === $u->id || $u->can('view', $reporte)), 403);

        return $this->archivoDe($reporte);
    }

    public function firmar(string $token)
    {
        $reporte = ReporteDeExtras::porToken($token) ?? abort(404);
        $parte = $reporte->parteDelToken($token);
        $reporte->loadMissing(['user', 'coordinador']);

        return view('nomina.firmar', [
            'reporte' => $reporte,
            'token'   => $token,
            'parte'   => $parte,
            'yaFirmo' => $reporte->firmo($parte),
            'filas'   => collect($reporte->datos['filas'] ?? [])->map(fn (array $f) => $f + [
                'partes' => FormatoAdf404::partirFranja(
                    \Illuminate\Support\Carbon::parse($f['fecha'] . ' ' . $f['extra_inicio'], config('fabos.lab.timezone')),
                    \Illuminate\Support\Carbon::parse($f['fecha'] . ' ' . $f['extra_fin'], config('fabos.lab.timezone'))
                        ->when($f['extra_fin'] <= $f['extra_inicio'], fn ($c) => $c->addDay()),
                ),
            ]),
        ]);
    }

    public function guardarFirma(Request $request, string $token)
    {
        $reporte = ReporteDeExtras::porToken($token) ?? abort(404);
        $parte = $reporte->parteDelToken($token);

        $datos = $request->validate([
            'firma'  => ['required', 'string'],
            'acepto' => ['accepted'],
        ], [
            'firma.required'  => 'Dibuja tu firma en el recuadro.',
            'acepto.accepted' => 'Marca la casilla para confirmar que revisaste el reporte.',
        ]);

        try {
            $this->reportes->firmar($reporte, $parte, $datos['firma'], $request->ip());
        } catch (BookingException $e) {
            return back()->withErrors(['firma' => $e->getMessage()]);
        }

        return redirect()->route('nomina.firmar', $token)->with('firmado', true);
    }

    /** El Excel tal como va, para quien tiene el enlace de firma. */
    public function archivo(string $token)
    {
        $reporte = ReporteDeExtras::porToken($token) ?? abort(404);

        return $this->archivoDe($reporte);
    }

    private function archivoDe(ReporteDeExtras $reporte)
    {
        abort_unless($reporte->tieneArchivo() && Storage::disk('local')->exists($reporte->archivo_path), 404);

        return Storage::disk('local')->download($reporte->archivo_path, $reporte->nombreDelArchivo(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
