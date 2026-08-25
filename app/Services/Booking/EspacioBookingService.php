<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Staffing\CoverageService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservar un espacio, y dentro de él las herramientas que hagan falta (§7).
 *
 * Es el uso normal del laboratorio: nadie reserva un juego de llaves suelto,
 * reserva la mesa del taller y toma lo que necesita. Por eso la herramienta no
 * se pide por su cuenta sino desde el espacio donde se va a usar.
 *
 * **Reservar el espacio no bloquea sus máquinas.** Una charla en el taller no
 * tiene por qué dejar parada la fresadora del rincón. Lo que sí queda ocupado
 * es lo que se marque explícitamente.
 */
class EspacioBookingService
{
    public function __construct(private CoverageService $cobertura) {}

    /**
     * Herramientas que se pueden tomar en este espacio, libres en esa franja.
     *
     * @return Collection<int,Asset>
     */
    public function herramientasLibres(Space $espacio, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return $espacio->herramientasDisponibles()
            ->orderBy('name')
            ->get()
            ->filter(fn (Asset $h) => $this->libre(Asset::class, $h->id, $desde, $hasta))
            ->values();
    }

    /**
     * @param  list<int>  $herramientaIds
     */
    public function reservar(
        User $user,
        Space $espacio,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        int $participantes = 1,
        array $herramientaIds = [],
        ?string $proposito = null,
    ): Reservation {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new BookingException('La hora de fin debe ser posterior a la de inicio.');
        }

        if (! $espacio->is_reservable) {
            throw new BookingException('Este espacio no se reserva.');
        }

        if ($participantes < 1) {
            throw new BookingException('Tiene que ir al menos una persona.');
        }

        // El aforo es un dato del espacio, editable desde el backoffice. El
        // mensaje dice el numero para que quien lo lea sepa si es un limite
        // real o uno que nadie ha revisado todavia.
        if ($espacio->capacity && $participantes > $espacio->capacity) {
            throw new BookingException(
                'En ' . $espacio->name . ' caben ' . $espacio->capacity . ' personas, y pediste '
                . $participantes . '. Si el aforo real es otro, se corrige en Espacios.'
            );
        }

        if (! $this->cobertura->franjaAtendida($desde)) {
            throw new BookingException('Ese día el laboratorio no atiende.');
        }

        $herramientas = $this->comprobarHerramientas($espacio, $herramientaIds, $desde, $hasta);

        try {
            return DB::transaction(function () use ($user, $espacio, $desde, $hasta, $participantes, $herramientas, $proposito) {
                $reserva = Reservation::create([
                    'reservable_type' => Space::class,
                    'reservable_id'   => $espacio->id,
                    'user_id'         => $user->id,
                    'status'          => 'confirmada',
                    'mode'            => 'directa',
                    'starts_at'       => $desde,
                    'ends_at'         => $hasta,
                    'participants'    => $participantes,
                    'purpose'         => $proposito,
                ]);

                // Cada herramienta, colgada de la reserva del espacio: asi se
                // sueltan todas juntas al cancelar, y ninguna queda reservada
                // para una sesion que ya no existe.
                foreach ($herramientas as $herramienta) {
                    Reservation::create([
                        'parent_reservation_id' => $reserva->id,
                        'reservable_type'       => Asset::class,
                        'reservable_id'         => $herramienta->id,
                        'user_id'               => $user->id,
                        'status'                => 'confirmada',
                        'mode'                  => 'directa',
                        'starts_at'             => $desde,
                        'ends_at'               => $hasta,
                        'purpose'               => 'En ' . $espacio->name,
                    ]);
                }

                return $reserva;
            });
        } catch (QueryException $e) {
            // La restriccion EXCLUDE es la ultima palabra: entre comprobar y
            // grabar puede haberse colado otra reserva.
            if (str_contains($e->getMessage(), 'sin_traslape')) {
                throw new BookingException(
                    'Alguien tomó ese espacio o una de esas herramientas mientras elegías. Prueba otra hora.'
                );
            }

            throw $e;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int,Asset>
     */
    private function comprobarHerramientas(
        Space $espacio,
        array $ids,
        CarbonInterface $desde,
        CarbonInterface $hasta,
    ): Collection {
        if ($ids === []) {
            return collect();
        }

        $permitidas = $espacio->herramientasDisponibles()->whereIn('assets.id', $ids)->get();

        // Pedir una herramienta que no sale de otro espacio no es un error de
        // quien reserva: es que la pantalla no deberia haberla ofrecido. Aun
        // asi se comprueba aqui, porque el formulario se puede manipular.
        if ($permitidas->count() !== count(array_unique($ids))) {
            throw new BookingException(
                'Alguna de esas herramientas no se puede usar en ' . $espacio->name . '.'
            );
        }

        foreach ($permitidas as $herramienta) {
            if (! $this->libre(Asset::class, $herramienta->id, $desde, $hasta)) {
                throw new BookingException($herramienta->name . ' ya está reservada a esa hora.');
            }
        }

        return $permitidas;
    }

    private function libre(string $tipo, int $id, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return ! Reservation::where('reservable_type', $tipo)
            ->where('reservable_id', $id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            // A UTC antes de comparar: si no, se contrasta la hora de pared
            // contra un instante y la disponibilidad sale corrida.
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->exists();
    }
}
