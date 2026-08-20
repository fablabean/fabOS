<?php

namespace App\Services\Staffing;

use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Booking\BookingException;
use Illuminate\Support\Carbon;

/**
 * Programar jornadas fuera del patrón semanal (§5): un sábado de
 * acompañamiento, un evento, una apertura extraordinaria.
 *
 * Toda asignación pasa por aquí para que el tope de extras se aplique siempre.
 * Si se pudiera crear el registro por otro camino, el control sería decorativo.
 */
class ShiftService
{
    public function __construct(private OvertimeService $extras) {}

    /**
     * @throws BookingException si supera el tope o se cruza con otra jornada
     */
    public function programar(
        User $persona,
        Carbon $desde,
        Carbon $hasta,
        string $motivo,
        ?User $asignadaPor = null,
        bool $cuentaComoExtra = true,
    ): ShiftAssignment {
        if ($rechazo = $this->extras->motivoDeRechazo($persona, $desde, $hasta, $cuentaComoExtra)) {
            throw new BookingException($rechazo);
        }

        if ($this->seCruza($persona, $desde, $hasta)) {
            throw new BookingException(
                $persona->name . ' ya tiene otra jornada programada que se cruza con esa franja.'
            );
        }

        return ShiftAssignment::create([
            'user_id'            => $persona->id,
            'starts_at'          => $desde,
            'ends_at'            => $hasta,
            'reason'             => $motivo,
            'counts_as_overtime' => $cuentaComoExtra,
            'assigned_by'        => $asignadaPor?->id,
        ]);
    }

    /** La persona acepta la jornada, o deja constancia de un conflicto. */
    public function aceptar(ShiftAssignment $jornada): ShiftAssignment
    {
        $jornada->update(['accepted_at' => now()]);

        return $jornada->refresh();
    }

    public function reportarConflicto(ShiftAssignment $jornada, string $nota): ShiftAssignment
    {
        // No es un veto: queda registrado para que la coordinación decida.
        $jornada->update(['conflict_note' => $nota]);

        return $jornada->refresh();
    }

    private function seCruza(User $persona, Carbon $desde, Carbon $hasta): bool
    {
        // A UTC: comparar un Carbon con zona contra la columna pierde el
        // desplazamiento y daría cruces fantasma, o dejaría pasar los reales.
        return ShiftAssignment::query()
            ->where('user_id', $persona->id)
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->exists();
    }
}
