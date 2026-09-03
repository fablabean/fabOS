<?php

namespace App\Services\Booking;

use App\Models\Evidencia;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Money\ChargeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Borrar una reserva, de verdad (§7).
 *
 * Lo normal es cancelar: la reserva se queda, con su motivo, y el histórico
 * cuenta lo que pasó. Pero después de probar el sistema con veinte reservas de
 * «test» y «hola», la lista es ruido, y una lista con ruido se deja de mirar.
 * Para eso existe esto, y por eso pasa por un solo sitio —la fila, la
 * selección múltiple y la ficha de edición borran igual—: si cada botón
 * borrara a su manera, uno de los tres se olvidaría del dinero.
 *
 * Tres cosas que cuida, y ninguna sobra:
 *
 *  · **Devuelve lo comprometido.** Reservar retiene FabCoins en garantías.
 *    Borrar la fila sin devolverlos dejaría el saldo de esa persona
 *    descontado por una reserva que ya no existe, y nadie sabría por qué. Se
 *    devuelve igual que al cancelar, con la misma clave: no se devuelve dos
 *    veces.
 *  · **Borra los archivos.** La evidencia es polimórfica y la base no la
 *    conoce: sin recorrerla a mano quedarían fotos huérfanas en el disco.
 *  · **No deshace lo que pasó.** El material que salió del inventario y el
 *    dinero que ya se liquidó ocurrieron: el libro es inmutable y el
 *    inventario ya se movió. Borrar la reserva no los devuelve, igual que
 *    borrar un proyecto no devuelve sus compras.
 *
 * Las reservas hijas —las de una en conjunto— se van con la madre por la
 * clave foránea; aquí solo se limpian sus archivos.
 */
class EliminarReserva
{
    public function __construct(private ChargeService $cobros) {}

    /**
     * @return array<string,int|string> qué se borró, para poder decirlo
     */
    public function __invoke(Reservation $reserva, ?User $quien = null): array
    {
        return DB::transaction(function () use ($reserva, $quien) {
            $hijas = Reservation::where('parent_reservation_id', $reserva->id)->pluck('id')->all();

            // Se mide antes de devolver: despues de la devolucion ya no hay
            // nada comprometido, y el resumen diria «devuelto: 0» mintiendo.
            $comprometido = (int) $this->cobros->comprometidoDe($reserva);

            $devuelto = $this->cobros->devolver(
                $reserva,
                'Devolución: reserva #' . $reserva->id . ' borrada'
                . ($quien ? ' por ' . $quien->name : ''),
            );

            $resumen = [
                'reserva'   => $reserva->id,
                'recurso'   => $reserva->reservable?->name ?? '?',
                'estado'    => $reserva->status,
                'hijas'     => count($hijas),
                'archivos'  => $this->borrarLosArchivos([$reserva->id, ...$hijas]),
                'devuelto'  => $devuelto ? $comprometido : 0,
                'quien'     => $quien?->email ?? 'sistema',
            ];

            $reserva->delete();

            Log::info('fabOS: reserva borrada', $resumen);

            return $resumen;
        });
    }

    /** @param  array<int,int>  $ids */
    private function borrarLosArchivos(array $ids): int
    {
        $disco = Storage::disk('local');
        $borrados = 0;

        $evidencias = Evidencia::where('evidenciable_type', Reservation::class)
            ->whereIn('evidenciable_id', $ids)
            ->get();

        foreach ($evidencias as $evidencia) {
            if (filled($evidencia->file_path) && $disco->exists($evidencia->file_path)) {
                $disco->delete($evidencia->file_path);
                $borrados++;
            }

            $evidencia->delete();
        }

        return $borrados;
    }
}
