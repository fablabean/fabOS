<?php

namespace App\Http\Controllers;

use App\Models\Acta;
use App\Models\User;
use App\Services\Activos\ActaException;
use App\Services\Activos\ActaService;
use Illuminate\Http\Request;

/**
 * Las actas de movimiento de activos, fuera del panel (§8).
 *
 * Dos puertas, como la requisición de compras:
 *
 * - **El enlace de firma**, sin sesión: le llega por correo a quien entrega y
 *   a quien recibe, es largo y aleatorio, y sirve para una sola cosa: ver esa
 *   acta y firmarla. Quien recibe un equipo no debería tener que entrar al
 *   sistema para firmar que lo recibió.
 * - **El formato y su PDF**, con sesión, para el equipo del laboratorio.
 */
class ActaController extends Controller
{
    public function __construct(private ActaService $actas) {}

    public function firmar(string $token)
    {
        $acta = Acta::porToken($token) ?? abort(404);
        $parte = $acta->parteDelToken($token);

        return view('actas.firmar', $this->actas->datosDelFormato($acta) + [
            'token' => $token,
            'parte' => $parte,
            'yaFirmo' => $acta->firmo($parte),
        ]);
    }

    public function guardarFirma(Request $request, string $token)
    {
        $acta = Acta::porToken($token) ?? abort(404);
        $parte = $acta->parteDelToken($token);

        $datos = $request->validate([
            'firma'   => ['required', 'string'],
            'acepto'  => ['accepted'],
        ], [
            'firma.required'  => 'Dibuja tu firma en el recuadro.',
            'acepto.accepted' => 'Marca la casilla para confirmar que revisaste el acta.',
        ]);

        try {
            $this->actas->firmar($acta, $parte, $datos['firma'], $request->ip());
        } catch (ActaException $e) {
            return back()->withErrors(['firma' => $e->getMessage()]);
        }

        return redirect()->route('actas.firmar', $token)->with('firmada', true);
    }

    /** El formato en pantalla, para el equipo. */
    public function ver(Request $request, Acta $acta)
    {
        $this->soloElEquipo($request);

        return view('actas.formato', $this->actas->datosDelFormato($acta) + [
            'paraPdf'   => false,
            'enlacePdf' => route('actas.pdf', $acta),
        ]);
    }

    public function pdf(Request $request, Acta $acta)
    {
        $this->soloElEquipo($request);

        $pdf = $this->actas->pdf($acta);

        // En flujo, como el resto de los PDF que se bajan desde el panel.
        return response()->streamDownload(
            fn () => print($pdf->output()),
            'FRF-207-f7_' . $acta->code . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function soloElEquipo(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(User::rolesDelEquipo()), 403);
    }
}
