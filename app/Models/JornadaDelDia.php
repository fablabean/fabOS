<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bloque del horario de una persona en una fecha concreta (§5).
 *
 * Si una persona tiene bloques en una fecha, esos son su jornada ese día y
 * reemplazan su horario semanal: «el martes voy remoto», «el jueves de 14:00
 * a 22:00», «de 8 a 12 en el laboratorio y de 14 a 18 desde casa». Los demás
 * días sigue el horario de siempre.
 */
class JornadaDelDia extends Model
{
    protected $table = 'jornadas_del_dia';

    protected $fillable = ['user_id', 'fecha', 'starts_at', 'ends_at', 'modalidad', 'nota', 'forzado', 'creado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'forzado' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function esPresencial(): bool
    {
        return $this->modalidad === WorkSchedule::PRESENCIAL;
    }
}
