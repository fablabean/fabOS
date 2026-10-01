<?php

namespace App\Models\Recorrido;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Una vez que se juega un circuito: un grupo, sus equipos, sus tiempos. */
class Partida extends Model
{
    protected $table = 'recorrido_partidas';

    public const ESTADOS = [
        'preparada' => 'Preparada',
        'en_curso'  => 'En curso',
        'terminada' => 'Terminada',
    ];

    /** Los mismos de la base: sin esto, una recién creada no los trae en memoria. */
    protected $attributes = ['estado' => 'preparada', 'penalizacion_segundos' => 30, 'rotar_orden' => true];

    protected $fillable = [
        'circuito_id', 'reservation_id', 'nombre', 'codigo', 'estado',
        'penalizacion_segundos', 'rotar_orden', 'iniciada_at', 'terminada_at',
    ];

    protected function casts(): array
    {
        return [
            'rotar_orden'  => 'boolean',
            'iniciada_at'  => 'datetime',
            'terminada_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Partida $p) {
            if (filled($p->codigo)) {
                return;
            }

            do {
                $p->codigo = Str::lower(Str::random(10));
            } while (static::where('codigo', $p->codigo)->exists());
        });
    }

    public function circuito(): BelongsTo
    {
        return $this->belongsTo(Circuito::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class)->orderBy('posicion')->orderBy('id');
    }

    public function enCurso(): bool
    {
        return $this->estado === 'en_curso';
    }

    public function urlDelTablero(): string
    {
        return route('juego.tablero', $this->codigo);
    }
}
