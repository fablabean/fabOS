<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Supply;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingException;
use App\Services\Booking\EligibilityService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Http\Request;

/**
 * Escanear el QR pegado en la máquina (§2, principio «móvil y QR primero»).
 *
 * La pantalla se adapta a la situación de quien escanea: si tiene una reserva
 * a punto de empezar ofrece registrar la llegada; si está usando el equipo,
 * cerrarlo; y si no tiene nada, le muestra si podría reservarlo.
 */
class ScanController extends Controller
{
    public function __construct(
        private AttendanceService $asistencia,
        private EligibilityService $eligibility,
        private MaintenanceService $mantenimiento,
    ) {}

    /**
     * La camara, para escanear sin salir de la aplicacion.
     *
     * El QR pegado en la maquina sigue siendo la prueba de que se esta
     * delante de ella: eso no cambia. Lo unico que se ahorra es ir a buscar la
     * camara del telefono y volver.
     */
    public function camara()
    {
        return view('escaneo.camara');
    }

    public function show(Request $request, string $token)
    {
        $activo = Asset::where('qr_token', $token)->firstOrFail();
        $user = $request->user();

        return view('escaneo.equipo', [
            'activo'    => $activo->load(['area', 'riskFamily']),
            'reserva'   => $this->asistencia->reservaEnCurso($user, $activo),
            'veredicto' => $this->eligibility->evaluar($user, $activo),
            'ordenes'   => $this->mantenimiento->abiertasDe($activo),
            /*
             * Insumos del área del equipo: al cerrar se declara lo que se gastó.
             * Se ofrecen los de su área y no todo el inventario, porque nadie va
             * a buscar «filamento» en una lista de cincuenta cosas.
             *
             * Y solo insumos, no productos terminados. Comparten tabla —los dos
             * se cuentan, se descuentan y se reponen— pero un producto no se
             * consume usando una máquina: se vende. Sin este filtro, a quien
             * acababa de imprimir se le preguntaba cuántos «Capibara
             * geométrico» había gastado, entre otras quince cosas del mismo
             * tipo; el insumo de verdad quedaba enterrado en la lista.
             */
            'insumos'   => Supply::where('is_active', true)
                ->where('kind', 'insumo')
                ->where('stock', '>', 0)
                ->when($activo->area_id, fn ($q) => $q->where('area_id', $activo->area_id))
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Reportar una falla desde la máquina misma.
     *
     * Cualquiera que la use puede hacerlo: quien detecta el problema es quien
     * está delante del equipo, no quien administra el sistema.
     */
    public function reportarFalla(Request $request, string $token)
    {
        $activo = Asset::where('qr_token', $token)->firstOrFail();

        $datos = $request->validate([
            'problema' => ['required', 'string', 'max:500'],
            'detiene'  => ['nullable', 'boolean'],
        ]);

        $orden = $this->mantenimiento->reportarFalla(
            $activo,
            $request->user(),
            $datos['problema'],
            (bool) ($datos['detiene'] ?? false),
        );

        return back()->with('status', $orden->stops_equipment
            ? 'Falla reportada. El equipo quedó fuera de servicio hasta que se revise.'
            : 'Falla reportada. Gracias por avisar.');
    }

    public function checkIn(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        try {
            $this->asistencia->checkIn($reservation);
        } catch (BookingException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()]);
        }

        return back()->with('status', 'Llegada registrada. Buen trabajo.');
    }

    public function checkOut(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        // Cantidades declaradas de material. Van como texto desde el formulario
        // y se limpian aquí: lo que no sea un número positivo, se ignora.
        $numero = fn ($v) => (float) str_replace(',', '.', (string) $v);

        $materiales = collect($request->input('material', []))
            ->map($numero)
            ->filter(fn (float $cantidad) => $cantidad > 0)
            ->all();

        /*
         * Y lo que viene en lámina, por el trozo que se cortó.
         *
         * De una hoja de 120×90 no se gasta «una»: se cortan 30×40. Delante de
         * la máquina se sabe lo que se midió, no la fracción, y pedir la
         * fracción es pedir la regla de tres —o que se anote una hoja entera,
         * que descuenta de más del inventario y cobra de más—.
         *
         * Manda sobre la cantidad escrita a mano: si alguien llenó las dos, lo
         * concreto es el trozo.
         */
        $largos = (array) $request->input('largo', []);
        $anchos = (array) $request->input('ancho', []);

        foreach (Supply::find(array_keys($largos + $anchos)) as $insumo) {
            $laminas = $insumo->laminasDeUnTrozo(
                $numero($largos[$insumo->id] ?? null),
                $numero($anchos[$insumo->id] ?? null),
            );

            if ($laminas !== null && $laminas > 0) {
                $materiales[$insumo->id] = $laminas;
            }
        }

        // Lo que gastó y no estaba en la lista. Se guarda antes de cerrar: si
        // el cierre falla por otra cosa, lo escrito no se pierde.
        if (filled($nota = trim((string) $request->input('material_note')))) {
            $reservation->update(['material_note' => mb_substr($nota, 0, 500)]);
        }

        try {
            $this->asistencia->checkOut($reservation, $materiales);
        } catch (BookingException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()]);
        }

        $reservation->refresh();
        $minutos = $this->asistencia->minutosReales($reservation);
        $costo = $reservation->actual_cost_minor;

        return redirect()->route('reservas.index')->with(
            'status',
            'Equipo liberado. Uso registrado: ' . $minutos . ' minutos'
            . ($costo ? ' · ' . number_format($costo / config('fabos.currency.minor_units'), 2, ',', '.')
                . ' ' . config('fabos.currency.code') : '')
            . '.'
        );
    }
}
