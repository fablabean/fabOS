<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Services\Booking\AsesoriaService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pedir una asesoría (§10).
 *
 * Es la salida para quien todavía no tiene el certifab. El sistema ya se la
 * prometía en la pantalla de reservas —«Asesoría con el responsable del
 * equipo»— y hasta ahora no había forma de pedirla.
 */
class AsesoriaController extends Controller
{
    public function __construct(private AsesoriaService $asesorias) {}

    public function show(Request $request, Asset $asset)
    {
        // Un equipo sin asesores declarados no puede ofrecer asesorías: no
        // habría a quién asignárselas.
        abort_unless($this->asesorias->seAsesora($asset), 404);

        $asset->load(['area', 'riskFamily']);

        return $this->pantalla($request, $asset, [
            'titulo'      => $asset->name,
            'explicacion' => 'Todavía no tienes el certifab de '
                . ($asset->riskFamily?->name ?? $asset->name)
                . ', y no hace falta para esto: alguien del equipo te acompaña, resuelve tus '
                . 'dudas y te muestra cómo se usa.',
            'accion'      => route('asesoria.store', $asset),
            'volver'      => route('reservas.show', $asset),
        ]);
    }

    /**
     * Asesoría general de un área (§10).
     *
     * Quien llega con «quiero imprimir esto en 3D» todavía no sabe si le toca
     * la Prusa o la de resina: elegir la máquina es parte de lo que viene a
     * consultar. Obligarle a elegirla antes de poder preguntar es pedirle la
     * respuesta para dejarle hacer la pregunta.
     */
    public function showArea(Request $request, Area $area)
    {
        abort_unless($this->asesorias->seAsesora($area), 404);

        return $this->pantalla($request, $area, [
            'titulo'      => 'Asesoría general de ' . $area->name,
            'explicacion' => 'No hace falta que sepas qué máquina necesitas: alguien del '
                . 'equipo te escucha, te dice con qué se hace lo que quieres y te acompaña.',
            'accion'      => route('asesoria.area.store', $area),
            'volver'      => route('publico.equipos', ['modo' => 'asesoria', 'area' => $area->slug]),
        ]);
    }

    /** Lo común de las dos pantallas: las franjas con cupo de verdad. */
    private function pantalla(Request $request, Asset|Area $ambito, array $textos)
    {
        return view('reservas.asesoria', $textos + [
            'franjas' => $this->asesorias->franjasDisponibles(
                $ambito,
                $request->user(),
                (int) config('fabos.asesorias.dias_vista', 7),
            )->groupBy(fn (array $f) => $f['inicio']->toDateString()),
            'minutos' => (int) config('fabos.asesorias.minutos', 45),
        ]);
    }

    public function store(Request $request, Asset $asset)
    {
        abort_unless($this->asesorias->seAsesora($asset), 404);

        return $this->agendar($request, $asset, $asset->name);
    }

    public function storeArea(Request $request, Area $area)
    {
        abort_unless($this->asesorias->seAsesora($area), 404);

        return $this->agendar($request, $area, 'General de ' . $area->name);
    }

    private function agendar(Request $request, Asset|Area $ambito, string $sobreQue)
    {

        $datos = $request->validate([
            'inicio' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $tz = config('fabos.lab.timezone');
        $inicio = Carbon::parse($datos['inicio'], $tz);

        if ($inicio->isPast()) {
            throw ValidationException::withMessages([
                'inicio' => 'Esa hora ya pasó. Elige otra.',
            ]);
        }

        $fin = $inicio->copy()->addMinutes((int) config('fabos.asesorias.minutos', 45));

        $reserva = $this->asesorias->agendar(
            $request->user(), $ambito, $inicio, $fin, $datos['motivo'] ?? null,
        );

        // Entre ver la hora libre y pedirla puede haberla tomado otra persona.
        // No es un error del sistema, y el mensaje no debe sonar a eso.
        if (! $reserva) {
            return back()->withErrors([
                'inicio' => 'Justo esa hora acaba de ocuparse. Elige otra de la lista.',
            ]);
        }

        // Dos avisos distintos, a dos personas distintas.
        //
        // Antes salia uno solo, con la plantilla de «reserva confirmada», y le
        // llegaba a quien iba a ATENDER diciendole «tu reserva quedo
        // confirmada»: el mensaje equivocado a la persona equivocada. Y con los
        // huecos vacios, porque esa plantilla espera otras variables.
        $datos = [
            'equipo' => $sobreQue,
            'fecha'  => $inicio->format('d/m/Y'),
            'inicio' => $inicio->format('H:i'),
            'fin'    => $fin->format('H:i'),
        ];

        $avisos = app(NotificationService::class);

        $avisos->enviar('asesoria.confirmada', $request->user(), $datos + [
            'asesor' => $reserva->reservable->name,
        ], $reserva);

        $avisos->enviar('asesoria.asignada', $reserva->reservable, $datos + [
            'solicitante' => $request->user()->name,
            'motivo'      => $reserva->purpose ?: 'No lo dijo.',
        ], $reserva);

        return redirect()->route('home')->with(
            'status',
            'Asesoría confirmada para el ' . $inicio->format('d/m/Y') . ' a las '
            . $inicio->format('H:i') . ' con ' . $reserva->reservable->name . '.'
        );
    }
}
