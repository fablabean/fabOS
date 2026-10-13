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
            'tarifaMaterial' => \App\Services\Money\MaterialDelEquipo::tarifa($asset),
            'materialesOfrecidos' => \App\Services\Money\MaterialDelEquipo::ofrecidas($asset),
            'verComo' => $user->hasRole(\App\Models\User::ROL_SUPERADMIN)
                ? \App\Models\UserCategory::orderBy('name')->get(['id', 'name', 'rate_factor'])
                : collect(),
            'saldo' => $this->libro->saldoDe($user),
            'miEspera' => WaitlistEntry::where('asset_id', $asset->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['esperando', 'avisado'])
                ->first(),
        ]);
    }

    /**
     * Lo que costaría, con lo que hay escrito en el formulario (§12).
     *
     * La página lo pide cada vez que cambia la duración o el material. La
     * cuenta se hace aquí y no en el navegador: lleva el cupo del certifab, la
     * categoría de la persona, el regalo del material y el redondeo, y dos
     * copias de esa regla acaban dando dos números.
     */
    public function cotizar(Request $request, Asset $asset)
    {
        abort_unless($asset->is_reservable, 404);

        $user = $request->user();
        $real = $user;

        /*
         * «Ver como», solo para superadmin y solo para la cuenta.
         *
         * Quien coordina es Colaborador, y a un colaborador el tiempo le sale
         * en cero: no tiene forma de ver lo que le va a costar a un estudiante
         * o a un externo. Aquí se cotiza con otra categoría sin tocar a nadie;
         * la reserva, si la hace, sigue siendo la suya y con su precio.
         */
        if ($request->query('como') && $user->hasRole(\App\Models\User::ROL_SUPERADMIN)
            && ($categoria = \App\Models\UserCategory::find((int) $request->query('como')))) {
            $user = (clone $user)->setRelation('category', $categoria);
        }

        $minutos = max(1, min(1440, (int) $request->query('duracion', max(60, $asset->min_minutes))));
        $material = (float) str_replace(',', '.', (string) $request->query('material', '0'));
        $tarifa = \App\Services\Money\MaterialDelEquipo::elegida($asset, $request->query('material_tarifa'));

        try {
            $cuando = $request->query('fecha')
                ? Carbon::parse($request->query('fecha') . ' ' . $request->query('inicio', '12:00'), config('fabos.lab.timezone'))
                : null;
        } catch (\Throwable) {
            $cuando = null;
        }

        $q = $this->cotizador->cotizar(
            $user, $asset, $minutos, $this->eligibility->evaluar($user, $asset)->requierePresencia(),
            cuando: $cuando, material: $material > 0 ? $material : 0.0, tarifaMaterial: $tarifa,
        );

        $unidades = (int) config('fabos.currency.minor_units');
        $fbc = fn (int $menor) => number_format($menor / $unidades, 2, ',', '.');
        $cobra = \App\Support\Settings::cobrosActivos();
        $saldo = $this->libro->saldoDe($real);
        $falta = $cobra ? max(0, $q->comprometidoMenor() - $saldo) : 0;
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return response()->json([
            'tiempo'  => trim(($h ? $h . ($h > 1 ? ' horas' : ' hora') : '') . ($m ? ' ' . $m . ' minutos' : '')),
            'lineas'  => array_map(fn (array $l) => [
                'concepto' => $l['concepto'], 'detalle' => $l['detalle'], 'importe' => $fbc((int) $l['importe']),
            ], $q->lineas),
            'total'   => $fbc($q->totalMenor),
            'retiene' => $q->comprometidoMenor() > 0 ? $fbc($q->comprometidoMenor()) : null,
            'esGarantia' => $q->depositoMenor > 0,
            'falta'   => $falta > 0 ? $fbc($falta) : null,
            'faltaEnPesos' => $falta > 0
                ? config('fabos.money.symbol') . number_format(app(\App\Services\Money\PricingService::class)->aPesos($falta), 0, ',', '.')
                : null,
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

        // Donde el material está tarifado, se dice cuánto antes de reservar:
        // de ahí sale lo que se retiene.
        $tarifaMaterial = \App\Services\Money\MaterialDelEquipo::tarifa($asset);

        if ($tarifaMaterial) {
            $unidad = $tarifaMaterial->unit ?: 'unidades';

            $datos += $request->validate([
                'material' => ['required', 'numeric', 'min:1', 'max:100000'],
            ], [
                'material.required' => 'Dinos cuánto material vas a usar (' . $unidad . '): con eso se calcula lo que cuesta.',
                'material.numeric'  => 'La cantidad de material va en números (' . $unidad . ').',
                'material.min'      => 'La cantidad de material debe ser de al menos 1 ' . $unidad . '.',
                'material.max'      => 'Esa cantidad de material no parece correcta. Revísala.',
            ]);
        } elseif (($ofrecidas = \App\Services\Money\MaterialDelEquipo::ofrecidas($asset))->isNotEmpty()) {
            // Aquí el material es una opción: se compra del que hay, o no.
            $datos += $request->validate([
                'material_tarifa' => ['nullable', 'integer', \Illuminate\Validation\Rule::in($ofrecidas->pluck('id')->all())],
                'material'        => ['nullable', 'required_with:material_tarifa', 'numeric', 'min:1', 'max:1000000'],
            ], [
                'material.required_with' => 'Dinos cuánto material vas a comprar, o elige que no compras.',
                'material.min'           => 'La cantidad de material debe ser mayor que cero.',
                'material.numeric'       => 'La cantidad de material va en números.',
            ]);
        }

        $tz = config('fabos.lab.timezone');
        $desde = Carbon::parse($datos['fecha'] . ' ' . $datos['inicio'], $tz);
        $hasta = $desde->copy()->addMinutes((int) $datos['duracion']);

        if ($desde->isPast()) {
            return back()->withErrors(['fecha' => 'Esa hora ya pasó.'])->withInput();
        }

        try {
            \App\Support\HorarioDeAutoservicio::exigir('maquinas', $desde, $hasta);

            $reserva = $this->booking->reservar(
                $request->user(), $asset, $desde, $hasta, $datos['proposito'] ?? null,
                material: isset($datos['material']) ? (float) $datos['material'] : null,
                tarifaMaterial: isset($datos['material_tarifa']) ? (int) $datos['material_tarifa'] : null,
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

        // A Mi cuenta: ahí están las próximas reservas, con su «Cancelar».
        return redirect()->route('home')->with('status', $mensaje);
    }

    /**
     * Cambiar cuantas personas van a un espacio, sin perder la reserva.
     *
     * Reservar para diez y despues ser dos es lo normal; cancelar y volver a
     * pedir perdia el turno en la bandeja.
     */
    public function personas(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);

        $datos = $request->validate(['participantes' => ['required', 'integer', 'min:1', 'max:500']]);

        try {
            app(\App\Services\Booking\EspacioBookingService::class)->cambiarParticipantes($reservation, (int) $datos['participantes']);
        } catch (BookingException $e) {
            return back()->withErrors(['reserva' => $e->getMessage()]);
        }

        return back()->with('status', 'Listo: ' . $datos['participantes'] . ($datos['participantes'] == 1 ? ' persona' : ' personas') . ' en ' . ($reservation->reservable?->name ?? 'el espacio') . '.');
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

}
