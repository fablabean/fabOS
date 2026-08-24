<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\Eligibility;
use App\Services\Booking\EligibilityService;
use App\Services\Booking\WaitlistService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\QuoteService;
use App\Services\Staffing\CoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * La cara del motor de reservas para el usuario (§10).
 *
 * El principio de la pantalla: cuando alguien no puede reservar algo, no se le
 * cierra la puerta —se le dice qué le falta y por dónde empezar—.
 */
class ReservationController extends Controller
{
    public function __construct(
        private EligibilityService $eligibility,
        private BookingService $booking,
        private CoverageService $coverage,
        private QuoteService $cotizador,
        private LedgerService $libro,
        private WaitlistService $espera,
    ) {}

    /** Catálogo con el semáforo de cada equipo para quien mira. */
    public function index(Request $request)
    {
        $user = $request->user();

        // Una sola consulta de certifabs para todo el catálogo, y las relaciones
        // por adelantado: sin esto serían cientos de consultas.
        $this->eligibility->precargar($user);

        $equipos = Asset::query()
            ->with(['area', 'riskFamily', 'dependencies'])
            // Contar los asesores aqui y no por tarjeta: sin esto seria una
            // consulta por equipo solo para decidir si se pinta un boton.
            ->withCount('advisors')
            ->where('is_reservable', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Asset $a) => [
                'activo'    => $a,
                'veredicto' => $this->eligibility->evaluar($user, $a),
            ])
            ->groupBy(fn ($fila) => $fila['activo']->area?->name ?? 'Sin área');

        return view('reservas.index', [
            'porArea'  => $equipos,
            'misReservas' => $this->misReservas($request),
            'franjaHoy'   => $this->coverage->franjaAtendida(Carbon::now(config('fabos.lab.timezone'))),
        ]);
    }

    /** Ficha del equipo y formulario, si corresponde. */
    public function show(Request $request, Asset $asset)
    {
        abort_unless($asset->is_reservable, 404);

        $user = $request->user();
        $veredicto = $this->eligibility->evaluar($user, $asset);

        // Se cotiza una hora como referencia: es la unidad con la que la gente
        // piensa, y el desglose explica de dónde sale cada componente.
        $referencia = max(60, $asset->min_minutes);

        return view('reservas.show', [
            'activo'    => $asset->loadCount('advisors')->load(['area', 'riskFamily', 'dependencies']),
            'veredicto' => $veredicto,
            'franjaHoy' => $this->coverage->franjaAtendida(Carbon::now(config('fabos.lab.timezone'))),
            'cotizacion' => $this->cotizador->cotizar(
                $user, $asset, $referencia, $veredicto->requierePresencia()
            ),
            'minutosCotizados' => $referencia,
            'saldo' => $this->libro->saldoDe($user),
            'miEspera' => WaitlistEntry::where('asset_id', $asset->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['esperando', 'avisado'])
                ->first(),
        ]);
    }

    /**
     * Apuntarse a la lista de espera de un equipo (§10).
     *
     * Se pide la ventana en la que le sirve, no solo el equipo: avisar de un
     * hueco del martes a quien solo puede venir el jueves es ruido.
     */
    public function esperar(Request $request, Asset $asset)
    {
        $datos = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date'],
            'nota'  => ['nullable', 'string', 'max:255'],
        ]);

        $tz = config('fabos.lab.timezone');

        try {
            $this->espera->apuntar(
                $request->user(),
                $asset,
                Carbon::parse($datos['desde'], $tz)->startOfDay(),
                Carbon::parse($datos['hasta'], $tz)->endOfDay(),
                $datos['nota'] ?? null,
            );
        } catch (BookingException $e) {
            return back()->withErrors(['espera' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Te avisamos si se libera algo de ' . $asset->name . ' dentro de esas fechas.'
        );
    }

    public function salirDeEspera(Request $request, WaitlistEntry $entry)
    {
        abort_unless($entry->user_id === $request->user()->id, 403);

        $this->espera->cancelar($entry);

        return back()->with('status', 'Ya no te avisamos de ese equipo.');
    }

    public function store(Request $request, Asset $asset)
    {
        $datos = $request->validate([
            'fecha'    => ['required', 'date'],
            'inicio'   => ['required', 'date_format:H:i'],
            'duracion' => ['required', 'integer', 'min:15', 'max:1440'],
            'proposito' => ['nullable', 'string', 'max:500'],
        ]);

        $tz = config('fabos.lab.timezone');
        $desde = Carbon::parse($datos['fecha'] . ' ' . $datos['inicio'], $tz);
        $hasta = $desde->copy()->addMinutes((int) $datos['duracion']);

        if ($desde->isPast()) {
            return back()->withErrors(['fecha' => 'Esa hora ya pasó.'])->withInput();
        }

        try {
            $reserva = $this->booking->reservar(
                $request->user(), $asset, $desde, $hasta, $datos['proposito'] ?? null
            );
        } catch (BookingException $e) {
            return back()->withErrors(['fecha' => $e->getMessage()])->withInput();
        }

        $mensaje = $reserva->status === 'confirmada'
            ? 'Reserva confirmada para el ' . $desde->translatedFormat('d/m/Y \a \l\a\s H:i') . '.'
            : 'Solicitud enviada. Queda pendiente del visto bueno del responsable.';

        if ($reserva->supervisor_id) {
            $mensaje .= ' Te acompaña ' . $reserva->supervisor->name . '.';
        }

        return redirect()->route('reservas.index')->with('status', $mensaje);
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        if ($reservation->starts_at->isPast()) {
            return back()->withErrors(['reserva' => 'No se puede cancelar una reserva que ya empezó.']);
        }

        // Se delega en el servicio en vez de actualizar la fila aquí: es el que
        // suelta el bloque de quien acompaña, devuelve el saldo retenido y
        // avisa a la lista de espera. Duplicar eso en el controlador es cómo se
        // termina con dos versiones de la misma regla.
        try {
            $this->booking->cancelar($reservation, 'Cancelada por el usuario');
        } catch (BookingException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()]);
        }

        return back()->with('status', 'Reserva cancelada.');
    }

    private function misReservas(Request $request)
    {
        return Reservation::query()
            ->where('user_id', $request->user()->id)
            ->where('reservable_type', Asset::class)
            ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->with('supervisor')
            ->get()
            ->map(function (Reservation $r) {
                $r->setRelation('reservable', Asset::find($r->reservable_id));

                return $r;
            });
    }
}
