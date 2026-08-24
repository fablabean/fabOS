<?php

namespace App\Http\Controllers;

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

        return view('reservas.asesoria', [
            'activo'  => $asset->load(['area', 'riskFamily']),
            'franjas' => $this->asesorias->franjasDisponibles(
                $asset,
                $request->user(),
                (int) config('fabos.asesorias.dias_vista', 7),
            )->groupBy(fn (array $f) => $f['inicio']->toDateString()),
            'minutos' => (int) config('fabos.asesorias.minutos', 45),
        ]);
    }

    public function store(Request $request, Asset $asset)
    {
        abort_unless($this->asesorias->seAsesora($asset), 404);

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
            $request->user(), $asset, $inicio, $fin, $datos['motivo'] ?? null,
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
            'equipo' => $asset->name,
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
