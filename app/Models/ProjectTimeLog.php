<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horas dedicadas a un proyecto (§11).
 *
 * El costo por hora se congela al registrar. No se guarda el sueldo de nadie:
 * es la tarifa de referencia del laboratorio, y guardarla en la línea evita que
 * subirla el año que viene reescriba el costo de proyectos ya cerrados.
 */
class ProjectTimeLog extends Model
{
    protected $fillable = [
        'project_id', 'user_id', 'external_name', 'worked_on',
        'hours', 'activity', 'hourly_cost',
    ];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'hours'     => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // En «saving» y no en «creating»: la columna es NOT NULL, asi que
        // dejar el campo en blanco al editar reventaba el guardado. En blanco
        // significa «la tarifa de referencia», tanto al registrar como al
        // corregir.
        static::saving(function (self $log) {
            $log->hourly_cost = $log->hourly_cost ?: (int) config('fabos.money.hourly_cost');
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function costo(): int
    {
        return (int) round((float) $this->hours * $this->hourly_cost);
    }

    public function quien(): string
    {
        return $this->user?->name ?? $this->external_name ?? 'sin identificar';
    }
}
