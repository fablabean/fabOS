<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un reporte de horas extras para nómina: el ADF-404-f4 que alguien generó y
 * descargó (§5).
 *
 * Guarda el archivo tal como salió, para volver a bajarlo sin que cambie, y
 * las jornadas que llevó: una jornada va en un solo reporte. Anularlo
 * devuelve sus jornadas a pendientes y deja el registro.
 */
class ReporteDeExtras extends Model
{
    protected $table = 'reportes_de_extras';

    public const FABOS = 'fabos';
    public const POR_FUERA = 'por_fuera';

    protected $fillable = [
        'user_id', 'corte', 'origen', 'minutos', 'diurnas', 'nocturnas',
        'diurnas_festivas', 'nocturnas_festivas', 'datos', 'archivo_path',
        'generado_por', 'anulado_at', 'anulado_por',
    ];

    protected function casts(): array
    {
        return [
            'corte'      => 'date',
            'datos'      => 'array',
            'anulado_at' => UtcDateTime::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    public function jornadas(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'reporte_id')->orderBy('starts_at');
    }

    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereNull('anulado_at');
    }

    public function estaAnulado(): bool
    {
        return $this->anulado_at !== null;
    }

    public function tieneArchivo(): bool
    {
        return filled($this->archivo_path);
    }

    /** «ADF-404-f4 Michael Pérez corte 2026-11-14.xlsx» */
    public function nombreDelArchivo(): string
    {
        return 'ADF-404-f4 ' . \Illuminate\Support\Str::of($this->user?->name ?? 'colaborador')->ascii()->replaceMatches('/[^A-Za-z0-9 ]/', '')->squish()
            . ' corte ' . $this->corte->toDateString() . ($this->id ? ' #' . $this->id : '') . '.xlsx';
    }
}
