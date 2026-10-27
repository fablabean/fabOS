<?php

namespace App\Models\Recorrido;

use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    /**
     * Simultánea: todos arrancan a la vez, cuando se inicia. Por jornadas:
     * los equipos van llegando en los horarios de la partida, se inscriben y
     * cada uno arranca su propio reloj.
     */
    public const MODOS = [
        'simultanea' => 'Simultánea',
        'jornadas'   => 'Por jornadas',
    ];

    /** Los mismos de la base: sin esto, una recién creada no los trae en memoria. */
    protected $attributes = ['estado' => 'preparada', 'modo' => 'simultanea', 'penalizacion_segundos' => 30, 'rotar_orden' => true];

    protected $fillable = [
        'circuito_id', 'reservation_id', 'nombre', 'codigo', 'estado', 'modo', 'jornadas',
        'penalizacion_segundos', 'rotar_orden', 'iniciada_at', 'terminada_at',
        'reporte_token', 'reporte_enviado_a', 'reporte_enviado_at',
    ];

    protected function casts(): array
    {
        return [
            'rotar_orden'  => 'boolean',
            'jornadas'     => 'array',
            'iniciada_at'  => 'datetime',
            'terminada_at' => 'datetime',
            'reporte_enviado_at' => 'datetime',
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

    public function esPorJornadas(): bool
    {
        return $this->modo === 'jornadas';
    }

    /**
     * Las jornadas, en orden y ya como fechas.
     *
     * @return \Illuminate\Support\Collection<int,array{inicio:Carbon,fin:Carbon}>
     */
    public function horarios(): \Illuminate\Support\Collection
    {
        return collect($this->jornadas ?? [])
            ->filter(fn ($j) => filled($j['inicio'] ?? null) && filled($j['fin'] ?? null))
            ->map(fn ($j) => ['inicio' => Carbon::parse($j['inicio']), 'fin' => Carbon::parse($j['fin'])])
            ->sortBy('inicio')
            ->values();
    }

    /** La jornada en la que cae ese momento, si cae en alguna. */
    public function jornadaDe(?CarbonInterface $momento = null): ?array
    {
        $momento ??= now();

        return $this->horarios()->first(fn ($j) => $momento->gte($j['inicio']) && $momento->lt($j['fin']));
    }

    /** Si ahora mismo los equipos pueden inscribirse y arrancar. */
    public function abiertaAhora(): bool
    {
        return $this->esPorJornadas() && $this->estado !== 'terminada' && $this->jornadaDe() !== null;
    }

    public function proximaJornada(): ?array
    {
        return $this->horarios()->first(fn ($j) => $j['inicio']->isFuture());
    }

    public function urlDeInscripcion(): string
    {
        return route('juego.inscribir', $this->codigo);
    }

    /**
     * El enlace del reporte para quien solicitó el recorrido. Distinto del
     * tablero, que se proyecta: este lleva los nombres de todos. Se crea la
     * primera vez que se pide, y así sirve también para partidas viejas.
     */
    public function urlDelReporte(): string
    {
        if (! $this->reporte_token) {
            $this->forceFill(['reporte_token' => Str::random(40)])->save();
        }

        return route('recorridos.reporte', $this->reporte_token);
    }

    /** Cuándo se jugó, para decirlo en una línea: «viernes 31 de octubre», o varias fechas. */
    public function fechaDeJuego(): string
    {
        $tz = config('fabos.lab.timezone');

        $dias = $this->esPorJornadas()
            ? $this->horarios()->pluck('inicio')
            : collect([$this->iniciada_at ?? $this->created_at]);

        return $dias->filter()
            ->map(fn ($d) => $d->copy()->timezone($tz)->locale('es')->translatedFormat('l j \d\e F \d\e Y'))
            ->unique()
            ->implode(', ');
    }

    public function urlDelTablero(): string
    {
        return route('juego.tablero', $this->codigo);
    }
}
