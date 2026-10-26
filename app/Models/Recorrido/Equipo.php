<?php

namespace App\Models\Recorrido;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un equipo dentro de una partida.
 *
 * Lleva dos llaves: `token`, el enlace que abre su celular, y el código corto
 * con el que se emparejan sus gafas. El código es corto a propósito: se
 * teclea con las gafas puestas.
 */
class Equipo extends Model
{
    protected $table = 'recorrido_equipos';

    /** En qué paso de la etapa va. */
    public const ESTADOS = [
        'esperando'   => 'Esperando el inicio',
        'buscando'    => 'Buscando el lugar',
        'resolviendo' => 'Resolviendo la prueba',
        'secuencia'   => 'Llevando la secuencia al líder',
        'terminado'   => 'Terminó',
    ];

    /** Los cuatro botones del tablero virtual. */
    public const BOTONES = [
        1 => ['Rojo', '#E5484D'],
        2 => ['Azul', '#3E63DD'],
        3 => ['Verde', '#30A46C'],
        4 => ['Amarillo', '#F5C400'],
    ];

    /** Colores para distinguir los equipos en el tablero. */
    public const COLORES = ['#0D6E63', '#3E63DD', '#E5484D', '#F76B15', '#8E4EC6', '#30A46C', '#D6409F', '#A18072'];

    protected $attributes = ['estado' => 'esperando', 'etapa' => 0, 'fallos' => 0, 'penalizacion' => 0, 'color' => '#0D6E63', 'posicion' => 0];

    protected $fillable = [
        'partida_id', 'nombre', 'color', 'posicion', 'codigo', 'token',
        'visor_token_hash', 'visor_visto_at', 'orden', 'etapa', 'estado',
        'secuencia', 'fallos', 'penalizacion', 'iniciado_at', 'terminado_at',
    ];

    protected $hidden = ['token', 'visor_token_hash', 'secuencia'];

    protected function casts(): array
    {
        return [
            'orden'          => 'array',
            'secuencia'      => 'array',
            'visor_visto_at' => 'datetime',
            'iniciado_at'    => 'datetime',
            'terminado_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Equipo $e) {
            // Sin letras que se confunden al leerlas en voz alta: O/0, I/1.
            do {
                $e->codigo = collect(range(1, 6))
                    ->map(fn () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)])
                    ->implode('');
            } while (static::where('codigo', $e->codigo)->exists());

            $e->token ??= Str::random(40);
        });
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(Partida::class);
    }

    public function integrantes(): HasMany
    {
        return $this->hasMany(Integrante::class)->orderBy('id');
    }

    public function avances(): HasMany
    {
        return $this->hasMany(Avance::class)->orderBy('etapa');
    }

    public function avanceActual(): ?Avance
    {
        return $this->avances()->where('etapa', $this->etapa)->first();
    }

    /** Cuántas etapas tiene su recorrido. */
    public function totalDeEtapas(): int
    {
        return count($this->orden ?? []);
    }

    public function estacionActual(): ?Estacion
    {
        $id = ($this->orden ?? [])[$this->etapa - 1] ?? null;

        return $id ? Estacion::find($id) : null;
    }

    public function terminado(): bool
    {
        return $this->estado === 'terminado';
    }

    /** Cuándo arrancó su reloj: el suyo, o el de la partida si es simultánea. */
    public function inicio(): ?Carbon
    {
        return $this->iniciado_at ?? ($this->partida->esPorJornadas() ? null : $this->partida->iniciada_at);
    }

    /**
     * Por jornadas, hasta cuándo puede jugar: el cierre de la jornada en la
     * que arrancó. Quien no terminó queda con lo que alcanzó, y su reloj no
     * sigue corriendo toda la noche hasta la jornada siguiente.
     */
    public function cierre(): ?Carbon
    {
        if (! $this->partida->esPorJornadas() || ! $this->iniciado_at) {
            return null;
        }

        // Si después movieron las jornadas y ya no cae en ninguna, sin tope.
        return $this->partida->jornadaDe($this->iniciado_at)['fin'] ?? null;
    }

    /** Si su jornada ya cerró sin que terminara. */
    public function seLeAcaboLaJornada(): bool
    {
        return ! $this->terminado() && ($this->cierre()?->isPast() ?? false);
    }

    /** Segundos de juego, con la penalización sumada. */
    public function segundos(): ?int
    {
        $inicio = $this->inicio();

        if (! $inicio) {
            return null;
        }

        $fin = $this->terminado_at ?? collect([$this->partida->terminada_at, $this->cierre(), now()])->filter()->min();

        return (int) $inicio->diffInSeconds(max($inicio, $fin)) + $this->penalizacion;
    }

    public function urlDelCelular(): string
    {
        return route('juego.equipo', $this->token);
    }
}
