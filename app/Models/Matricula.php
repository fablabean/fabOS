<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien matriculado en un programa de Educación Continua (§5, §12).
 *
 * Es lo que explica por qué una persona tiene la categoría que tiene: «está
 * en el bootcamp de IoT hasta noviembre». La categoría decide su tarifa, su
 * dotación y con cuánto saldo nace; la matrícula dice quién lo anotó y hasta
 * cuándo vale.
 */
class Matricula extends Model
{
    protected $table = 'matriculas';

    protected $fillable = [
        'user_id', 'user_category_id', 'program_name', 'starts_on', 'ends_on', 'notes', 'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on'   => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UserCategory::class, 'user_category_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Sigue vigente: sin fecha de fin, o con la fecha por delante.
     *
     * El dia de la fecha ya no cuenta: cerrar una matricula la fecha hoy, y
     * hoy tiene que leerse como terminada.
     */
    public function vigente(): bool
    {
        return $this->ends_on === null || $this->ends_on->toDateString() > self::hoy();
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>', self::hoy()));
    }

    public static function hoy(): string
    {
        return now(config('fabos.lab.timezone'))->toDateString();
    }
}
