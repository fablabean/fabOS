<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Guarda instantes en UTC de verdad.
 *
 * El cast `datetime` de Laravel formatea la fecha SIN la zona horaria, así que
 * una hora de Bogotá (10:00-05:00) llega a PostgreSQL como "10:00" y se
 * interpreta en UTC: la reserva queda cinco horas antes de lo pedido. El error
 * es silencioso —no falla nada— y solo se nota cuando alguien llega y la
 * máquina está ocupada por otra persona.
 *
 * Aquí se convierte explícitamente antes de escribir, y se lee siempre en UTC
 * para que la conversión a hora local sea decisión de la vista.
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // parse conserva la zona de un Carbon y asume la de la app en una
        // cadena; utc() convierte el instante en vez de reinterpretarlo.
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
