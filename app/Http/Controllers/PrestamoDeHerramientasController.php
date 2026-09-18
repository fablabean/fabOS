<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EligibilityService;
use App\Services\Staffing\CoverageService;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Varias herramientas en una sola reserva (§7).
 *
 * Se llega desde la lista de herramientas con las casillas marcadas. Aquí se
 * ve, antes de elegir hora, cuál de ellas se puede pedir y cuál no —le falta
 * el certifab, está en mantenimiento—, porque enterarse al enviar es lo que
 * hace que la gente pida de una en una.
 */
class PrestamoDeHerramientasController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private EligibilityService $eligibility,
        private CoverageService $coverage,
    ) {}

    public function create(Request $request)
    {
        $herramientas = $this->elegidas($request->input('h', []));

        if ($herramientas->isEmpty()) {
            return redirect()->route('publico.reservas', ['modo' => 'herramientas']);
        }

        $quien = $request->user();
        $this->eligibility->precargar($quien);

        $tz = config('fabos.lab.timezone');

        return view('reservas.herramientas', [
            'herramientas' => $herramientas,
            'veredictos'   => $herramientas->mapWithKeys(fn (Asset $h) => [$h->id => $this->eligibility->evaluar($quien, $h)]),
            'tope'         => Settings::maxHerramientasPorReserva(),
            'franjaHoy'    => $this->coverage->franjaAtendida(Carbon::now($tz)),
            // La duración más corta que todas admiten y la más larga que todas
            // aguantan: una sola hora para el conjunto.
            'minMinutos'   => (int) $herramientas->max('min_minutes'),
            'maxMinutos'   => (int) $herramientas->min('max_minutes'),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'h'         => ['required', 'array', 'min:1'],
            'h.*'       => ['integer'],
            'fecha'     => ['required', 'date'],
            'inicio'    => ['required', 'date_format:H:i'],
            'duracion'  => ['required', 'integer', 'min:15', 'max:1440'],
            'proposito' => ['nullable', 'string', 'max:500'],
        ]);

        $herramientas = $this->elegidas($datos['h']);

        $tz = config('fabos.lab.timezone');
        $desde = Carbon::parse($datos['fecha'] . ' ' . $datos['inicio'], $tz);
        $hasta = $desde->copy()->addMinutes((int) $datos['duracion']);

        if ($desde->isPast()) {
            return back()->withErrors(['fecha' => 'Esa hora ya pasó.'])->withInput();
        }

        try {
            $reserva = $this->booking->reservarHerramientas(
                $request->user(), $herramientas, $desde, $hasta, $datos['proposito'] ?? null,
            );
        } catch (BookingException $e) {
            return back()->withErrors(['fecha' => $e->getMessage()])->withInput();
        }

        $cuantas = $herramientas->count();
        $mensaje = $reserva->status === 'confirmada'
            ? ($cuantas === 1 ? 'Herramienta reservada' : $cuantas . ' herramientas reservadas')
                . ' para el ' . $desde->translatedFormat('d/m/Y \a \l\a\s H:i') . '.'
            : 'Solicitud enviada. Las ' . $cuantas . ' herramientas quedan pendientes del visto bueno del responsable.';

        return redirect()->route('reservas.index')->with('status', $mensaje);
    }

    /**
     * Lo que se marcó, en el orden en que se marcó, y solo lo que se presta.
     *
     * Lo que no es herramienta o no se reserva se descarta en silencio: llega
     * únicamente si alguien manipuló la dirección, y no merece un error.
     *
     * @return \Illuminate\Support\Collection<int,Asset>
     */
    private function elegidas(array $ids): \Illuminate\Support\Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return Asset::query()
            ->with('area', 'riskFamily', 'space')
            ->whereIn('id', $ids)
            ->where('kind', 'herramienta')
            ->where('is_reservable', true)
            ->get()
            ->sortBy(fn (Asset $a) => array_search($a->id, $ids, true))
            ->values();
    }
}
