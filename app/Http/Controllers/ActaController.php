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
            // Solo si el acta no la traía: entonces es obligatoria.
            'cedula'  => [blank($acta->{"{$parte}_cedula"}) ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9.\- ]+$/'],
        ], [
            'firma.required'  => 'Dibuja tu firma en el recuadro.',
            'acepto.accepted' => 'Marca la casilla para confirmar que revisaste el acta.',
            'cedula.required' => 'Escribe tu número de documento: el formato lo exige.',
            'cedula.regex'    => 'El número de documento solo lleva números, letras, puntos o guiones.',
        ]);

        try {
            $this->actas->firmar($acta, $parte, $datos['firma'], $request->ip(), $datos['cedula'] ?? null);
        } catch (ActaException $e) {
            return back()->withInput($request->only('cedula'))->withErrors(['firma' => $e->getMessage()]);
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

    // ------------------------------------------------------- devoluciones

    /** La pantalla donde quien recibe en el laboratorio anota lo que volvió. */
    public function devolver(Request $request, Acta $acta)
    {
        $this->soloElEquipo($request);

        abort_unless(in_array($acta->status, ['firmada', 'enviada'], true), 404);

        return view('actas.devolver', [
            'acta'       => $acta->load('devoluciones.items'),
            'pendientes' => $acta->pendientesDeVolver()->get(),
        ]);
    }

    public function guardarDevolucion(Request $request, Acta $acta)
    {
        $this->soloElEquipo($request);

        $datos = $request->validate([
            'fecha'            => ['required', 'date', 'before_or_equal:today'],
            'observaciones'    => ['nullable', 'string', 'max:1000'],
            'firma'            => ['required', 'string'],
            'items'            => ['required', 'array'],
            'items.*.vuelve'   => ['nullable'],
            'items.*.estado'   => ['nullable', 'in:B,R,M'],
            'items.*.nota'     => ['nullable', 'string', 'max:300'],
        ], [
            'firma.required' => 'Dibuja tu firma en el recuadro.',
            'fecha.before_or_equal' => 'La devolución no puede tener una fecha futura.',
        ]);

        // Solo lo que se marcó como que vuelve.
        $vuelven = collect($datos['items'])
            ->filter(fn ($i) => ! empty($i['vuelve']))
            ->map(fn ($i) => ['estado' => $i['estado'] ?? null, 'nota' => $i['nota'] ?? null])
            ->all();

        try {
            $devolucion = $this->actas->registrarDevolucion(
                $acta,
                $request->user(),
                $vuelven,
                \Illuminate\Support\Carbon::parse($datos['fecha'], config('fabos.lab.timezone')),
                $datos['observaciones'] ?? null,
                $datos['firma'],
            );
        } catch (ActaException $e) {
            return back()->withInput($request->except('firma'))->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('actas.devolucion.ver', $devolucion)->with('status', 'Devolución registrada.');
    }

    public function verDevolucion(Request $request, \App\Models\ActaDevolucion $devolucion)
    {
        $this->soloElEquipo($request);

        return view('actas.devolucion', $this->actas->datosDeLaDevolucion($devolucion) + ['paraPdf' => false]);
    }

    public function pdfDevolucion(Request $request, \App\Models\ActaDevolucion $devolucion)
    {
        $this->soloElEquipo($request);

        $pdf = $this->actas->pdfDeLaDevolucion($devolucion);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'Devolucion_' . $devolucion->code . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** Avisar a Planta Física de que regresó: opcional, y lo pulsa alguien. */
    public function enviarDevolucion(Request $request, \App\Models\ActaDevolucion $devolucion)
    {
        $this->soloElEquipo($request);

        try {
            $aviso = $this->actas->enviarDevolucionAPlantaFisica($devolucion, $request->user());
        } catch (ActaException $e) {
            return back()->withErrors(['envio' => $e->getMessage()]);
        }

        return $aviso->status === 'enviado'
            ? back()->with('status', 'Enviada a Planta Física (' . $aviso->to . '), con el formato adjunto.')
            : back()->withErrors(['envio' => 'El correo no salió' . ($aviso->reason ? ': ' . $aviso->reason : '') . '.']);
    }

    private function soloElEquipo(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(User::rolesDelEquipo()), 403);
    }
}
