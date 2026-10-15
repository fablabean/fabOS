<?php

namespace App\Support;

use App\Models\User;

/**
 * Si lo que reserva esta persona espera el visto bueno de la coordinación.
 *
 * Lo decide su categoría («Sus reservas pasan por aprobación», marcada para
 * Externo). Y solo cuando reserva **por su cuenta**: si la reserva la hace
 * alguien del equipo desde el panel a su nombre, ese alguien ya decidió, y
 * pedirle que se apruebe a sí mismo es un paso de más.
 */
class ReservaConAprobacion
{
    public const MOTIVO = 'La pidió una persona externa: la confirma la coordinación.';

    public static function para(User $quien): bool
    {
        return (bool) $quien->category?->requires_approval
            && auth()->id() === $quien->id;
    }
}
