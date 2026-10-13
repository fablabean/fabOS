<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Reservation;
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
            'tarifaMaterial' => \App\Services\Money\MaterialDelEquipo::tarifa($activo),
            'materialesOfrecidos' => \App\Services\Money\MaterialDelEquipo::ofrecidas($activo),
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
            // Con el laboratorio bloqueado nadie entra por su cuenta.
            \App\Support\BloqueoDeReservas::exigirAbierto();

            $this->asistencia->checkIn($reservation);
        } catch (BookingException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()]);
        }

        return back()->with('status', 'Llegada registrada. Buen trabajo.');
    }

    public function checkOut(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        /*
         * Al cerrar no se pasa inventario.
         *
         * Se ofrecia la lista de insumos del area para declarar cuanto se
         * gasto, y era una lista que el catalogo no sostiene: en impresion 3D
         * habia un insumo de verdad y quince productos terminados tapandolo.
         * Quien acaba de usar la maquina viene a soltarla, no a hacer un
         * inventario.
         *
         * Lo que se quiera llevar se compra en la tienda, que es donde hay
         * precio y existencia. Y lo que se gasto se dice con palabras, abajo:
         * de ahi sale lo que falta por cargar.
         *
         * Se guarda antes de cerrar: si el cierre falla por otra cosa, lo
         * escrito no se pierde.
         */
        if (filled($nota = trim((string) $request->input('material_note')))) {
            $reservation->update(['material_note' => mb_substr($nota, 0, 500)]);
        }

        /*
         * El material tarifado sí se pide: es lo que se cobra.
         *
         * Al reservar se dijo lo que daba el laminador; aquí se confirma lo
         * que se gastó de verdad, y la diferencia vuelve al saldo o se cobra.
         */
        $equipo = $reservation->reservable_type === Asset::class ? Asset::find($reservation->reservable_id) : null;
        $tarifaMaterial = $equipo ? \App\Services\Money\MaterialDelEquipo::tarifa($equipo) : null;
        $gastado = null;
        $elegida = null;

        if ($tarifaMaterial) {
            $unidad = $tarifaMaterial->unit ?: 'unidades';

            $gastado = (float) $request->validate([
                'material_used' => ['required', 'numeric', 'min:0', 'max:100000'],
            ], [
                'material_used.required' => 'Dinos cuánto material gastaste (' . $unidad . '). Si no gastaste, escribe 0.',
                'material_used.numeric'  => 'La cantidad de material va en números (' . $unidad . ').',
            ])['material_used'];
        } elseif ($equipo && ($ofrecidas = \App\Services\Money\MaterialDelEquipo::ofrecidas($equipo))->isNotEmpty()) {
            // El material opcional: lo que compró al final, que puede no ser
            // lo que pensaba al reservar. O nada.
            $material = $request->validate([
                'material_tarifa' => ['nullable', 'integer', \Illuminate\Validation\Rule::in($ofrecidas->pluck('id')->all())],
                'material_used'   => ['nullable', 'required_with:material_tarifa', 'numeric', 'min:0', 'max:1000000'],
            ], [
                'material_used.required_with' => 'Dinos cuánto material gastaste, o elige que no compraste.',
            ]);

            $elegida = $material['material_tarifa'] ?? null;
            $gastado = $elegida ? (float) $material['material_used'] : 0.0;
        }

        try {
            // Todo o nada: si no alcanza para lo que se gastó de más, el
            // equipo no queda cerrado a medias ni el material anotado sin cobrar.
            \Illuminate\Support\Facades\DB::transaction(function () use ($reservation, $gastado, $tarifaMaterial, $elegida) {
                if ($gastado !== null) {
                    $reservation->update(['material_used' => round($gastado, 2)]
                        + ($tarifaMaterial ? [] : ['material_rate_card_id' => $elegida]));
                }

                $this->asistencia->checkOut($reservation);
            });
        } catch (BookingException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()])->withInput();
        } catch (\App\Services\Ledger\LedgerException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()
                . ' Gastaste más de lo que se había retenido: recarga la diferencia con el equipo del laboratorio y vuelve a cerrar.',
            ])->withInput();
        }

        $reservation->refresh();
        $minutos = $this->asistencia->minutosReales($reservation);
        $costo = $reservation->actual_cost_minor;

        return redirect()->route('home')->with(
            'status',
            'Equipo liberado. Uso registrado: ' . $minutos . ' minutos'
            . ($costo ? ' · ' . number_format($costo / config('fabos.currency.minor_units'), 2, ',', '.')
                . ' ' . config('fabos.currency.code') : '')
            . '.'
        );
    }
}
