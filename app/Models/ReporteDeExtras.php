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

    /** Las dos firmas del formato: quien lo genera y su jefe inmediato. */
    public const PARTES = ['trabajador', 'coordinador'];

    public const ESTADOS = [
        'generado'   => 'Descargado sin firmas',
        'por_firmar' => 'Esperando firmas',
        'firmado'    => 'Firmado',
        'enviado'    => 'Enviado a nómina',
    ];

    protected $fillable = [
        'user_id', 'coordinador_id', 'corte', 'origen', 'estado', 'minutos', 'diurnas', 'nocturnas',
        'diurnas_festivas', 'nocturnas_festivas', 'datos', 'archivo_path',
        'generado_por', 'anulado_at', 'anulado_por',
        'trabajador_token', 'coordinador_token',
        'enviado_a_nomina_at', 'enviado_a_nomina_a', 'enviado_a_nomina_por',
    ];

    protected $attributes = ['estado' => 'generado'];

    protected function casts(): array
    {
        return [
            'corte'                => 'date',
            'datos'                => 'array',
            'anulado_at'           => UtcDateTime::class,
            'trabajador_firmo_at'  => UtcDateTime::class,
            'coordinador_firmo_at' => UtcDateTime::class,
            'enviado_a_nomina_at'  => UtcDateTime::class,
        ];
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    public function firmo(string $parte): bool
    {
        return $this->{"{$parte}_firmo_at"} !== null;
    }

    public function estaFirmadoPorTodos(): bool
    {
        return $this->firmo('trabajador') && $this->firmo('coordinador');
    }

    /** Si se puede enviar a nómina: con las dos firmas, y otra vez si hace falta. */
    public function puedeEnviarse(): bool
    {
        return ! $this->estaAnulado() && in_array($this->estado, ['firmado', 'enviado'], true);
    }

    /** A qué parte corresponde un enlace de firma, si corresponde a alguna. */
    public function parteDelToken(string $token): ?string
    {
        foreach (self::PARTES as $parte) {
            if (filled($this->{"{$parte}_token"}) && hash_equals($this->{"{$parte}_token"}, $token)) {
                return $parte;
            }
        }

        return null;
    }

    public static function porToken(string $token): ?self
    {
        return static::where('trabajador_token', $token)->orWhere('coordinador_token', $token)->first();
    }

    public function enlaceDeFirma(string $parte): ?string
    {
        $token = $this->{"{$parte}_token"};

        return filled($token) ? route('nomina.firmar', $token) : null;
    }

    public static function nuevoToken(): string
    {
        return \Illuminate\Support\Str::random(48);
    }

    /** Lo que se ve en la lista: «Esperando la firma de Erick Hansen». */
    public function estadoLegible(): string
    {
        if ($this->estaAnulado()) {
            return 'Anulado';
        }

        if ($this->origen === self::POR_FUERA) {
            return 'Reportado por fuera';
        }

        if ($this->estado === 'por_firmar') {
            return ! $this->firmo('trabajador')
                ? 'Falta la firma de ' . ($this->user?->name ?? 'quien lo generó')
                : 'Falta la firma de ' . ($this->coordinador?->name ?? 'quien coordina');
        }

        return self::ESTADOS[$this->estado] ?? $this->estado;
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
