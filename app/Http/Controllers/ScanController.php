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
            // Insumos del área del equipo: al cerrar se declara lo que se gastó.
            // Se ofrecen los de su área y no todo el inventario, porque nadie va
            // a buscar «filamento» en una lista de cincuenta cosas.
            'insumos'   => Supply::where('is_active', true)
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
        $materiales = collect($request->input('material', []))
            ->map(fn ($cantidad) => (float) str_replace(',', '.', (string) $cantidad))
            ->filter(fn (float $cantidad) => $cantidad > 0)
            ->all();

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
