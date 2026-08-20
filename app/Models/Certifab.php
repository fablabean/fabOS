<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Habilitación para operar un equipo o una familia de riesgo (§10). */
class Certifab extends Model
{
    protected $fillable = [
        'public_code', 'user_id', 'risk_family_id', 'asset_id', 'level',
        'max_autonomous_minutes', 'granted_by', 'granted_via',
        'granted_at', 'expires_at', 'revoked_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => UtcDateTime::class,
            'expires_at' => UtcDateTime::class,
            'revoked_at' => UtcDateTime::class,
        ];
    }

    /** Escalera de formación. El orden importa: define cuánta autonomía da. */
    public const NIVELES = ['bit', 'byte', 'kilo', 'mega', 'giga', 'tera'];

    /**
     * Autonomía por nivel, en minutos (§10). Quien llega a tera puede reservar
     * bloques largos sin visto bueno; el resto necesita check por encima de su
     * umbral. Nulo significa "usar el valor del activo".
     */
    public const AUTONOMIA_POR_NIVEL = [
        'tera' => 720,
    ];

    /** Se genera solo al crear: nunca debe quedar un certifab sin verificar. */
    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->public_code ??= \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10));
        });
    }

    /** Estado legible, tal como lo ve quien verifica. */
    public function estado(): string
    {
        return match (true) {
            $this->revoked_at !== null                          => 'revocado',
            $this->expires_at && $this->expires_at->isPast()     => 'vencido',
            default                                             => 'vigente',
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function riskFamily(): BelongsTo
    {
        return $this->belongsTo(RiskFamily::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** Vigente: ni revocado ni vencido. */
    public function scopeVigente(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn (Builder $s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isVigente(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Minutos que este certifab permite reservar sin aprobación. */
    public function autonomia(Asset $asset): int
    {
        return $this->max_autonomous_minutes
            ?? self::AUTONOMIA_POR_NIVEL[$this->level]
            ?? $asset->autonomous_minutes;
    }
}
