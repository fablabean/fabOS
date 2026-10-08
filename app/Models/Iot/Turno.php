<?php

namespace App\Models\Iot;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un rato de alguien en un dispositivo, con su hora de inicio y de fin. */
class Turno extends Model
{
    protected $table = 'iot_turnos';

    /** De dónde salió el turno. */
    public const ORIGENES = [
        'registro' => 'Se registró',
        'cuenta'   => 'Ya tenía cuenta',
        'fabcoin'  => 'Pagó con FabCoins',
        'manual'   => 'Encendido a mano',
    ];

    /** Los que se dan una sola vez por persona y dispositivo. */
    public const GRATIS = ['registro', 'cuenta'];

    protected $fillable = [
        'dispositivo_id', 'user_id', 'nombre', 'origen', 'minutos', 'fabcoins',
        'invitado_por_id', 'empieza_at', 'termina_at', 'cancelado_at', 'ip',
    ];

    protected function casts(): array
    {
        return ['empieza_at' => 'datetime', 'termina_at' => 'datetime', 'cancelado_at' => 'datetime'];
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitado_por_id');
    }

    /** Los que cuentan: ni cancelados ni ya terminados. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereNull('cancelado_at')->where('termina_at', '>', now());
    }

    public function enCurso(): bool
    {
        return $this->cancelado_at === null && $this->empieza_at->lte(now()) && $this->termina_at->gt(now());
    }

    public function estado(): string
    {
        return match (true) {
            $this->cancelado_at !== null => 'Cancelado',
            $this->termina_at->lte(now()) => 'Terminó',
            $this->empieza_at->lte(now()) => 'Jugando',
            default => 'En fila',
        };
    }
}
