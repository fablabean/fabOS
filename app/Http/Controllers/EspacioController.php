<?php

namespace App\Http\Controllers;

use App\Models\Space;
use App\Services\Booking\BookingException;
use App\Services\Booking\EspacioBookingService;
use App\Services\Staffing\CoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Reservar un espacio (§7).
 *
 * Un espacio no se elige como una máquina: lo que importa es cuánta gente cabe
 * y qué se puede tomar dentro. Por eso tiene su propia pantalla en vez de
 * colarse en el catálogo de equipos.
 */
class EspacioController extends Controller
{
    public function __construct(
        private EspacioBookingService $espacios,
        private CoverageService $cobertura,
    ) {}

    public function index()
    {
        return view('espacios.index', [
            'espacios'  => Space::where('is_reservable', true)
                ->with('areas')
                ->withCount('assets')
                ->orderBy('name')
                ->get(),
            'franjaHoy' => $this->cobertura->franjaAtendida(Carbon::now(config('fabos.lab.timezone'))),
        ]);
    }

    public function show(Request $request, Space $space)
    {
        abort_unless($space->is_reservable, 404);

        $tz = config('fabos.lab.timezone');

        // Una franja de referencia para listar las herramientas: la de hoy, o
        // la que venga en la URL si la persona ya eligió.
        $desde = $request->filled('fecha') && $request->filled('inicio')
            ? Carbon::parse($request->date('fecha')->format('Y-m-d') . ' ' . $request->string('inicio'), $tz)
            : Carbon::now($tz)->addHour()->startOfHour();

        $hasta = $desde->copy()->addMinutes((int) $request->integer('duracion', 60) ?: 60);

        return view('espacios.show', [
            'espacio'       => $space->load('areas'),
            'herramientas'  => $this->espacios->herramientasLibres($space, $desde, $hasta),
            'desde'         => $desde,
            'duracion'      => (int) $request->integer('duracion', 60) ?: 60,
            'franjaHoy'     => $this->cobertura->franjaAtendida(Carbon::now($tz)),
        ]);
    }

    public function store(Request $request, Space $space)
    {
        abort_unless($space->is_reservable, 404);

        $datos = $request->validate([
            'fecha'          => ['required', 'date'],
            'inicio'         => ['required', 'date_format:H:i'],
            'duracion'       => ['required', 'integer', 'min:15', 'max:720'],
            'participantes'  => ['required', 'integer', 'min:1', 'max:500'],
            'herramientas'   => ['array'],
            'herramientas.*' => ['integer'],
            'proposito'      => ['nullable', 'string', 'max:500'],
        ]);

        $tz = config('fabos.lab.timezone');
        $desde = Carbon::parse($datos['fecha'] . ' ' . $datos['inicio'], $tz);
        $hasta = $desde->copy()->addMinutes((int) $datos['duracion']);

        try {
            $reserva = $this->espacios->reservar(
                $request->user(),
                $space,
                $desde,
                $hasta,
                (int) $datos['participantes'],
                array_map('intval', $datos['herramientas'] ?? []),
                $datos['proposito'] ?? null,
            );
        } catch (BookingException $e) {
            // El motivo va al campo que lo causó cuando se puede saber: así el
            // error aparece junto a lo que hay que cambiar.
            throw ValidationException::withMessages([
                str_contains($e->getMessage(), 'caben') ? 'participantes' : 'fecha' => $e->getMessage(),
            ]);
        }

        return redirect()->route('home')->with(
            'status',
            'Reservaste ' . $space->name . ' el ' . $desde->format('d/m/Y')
            . ' de ' . $desde->format('H:i') . ' a ' . $hasta->format('H:i')
            . ' para ' . $reserva->participants . ' persona' . ($reserva->participants > 1 ? 's' : '') . '.'
        );
    }
}
