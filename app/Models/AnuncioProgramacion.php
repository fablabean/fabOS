<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un horario fijo de un anuncio: ciertos días de la semana, a cierta hora (§3).
 *
 * La hora es la del laboratorio, no la del servidor ni la del equipo que lo
 * reproduce: «a las 17:50» quiere decir las 17:50 en Bogotá.
 */
class AnuncioProgramacion extends Model
{
    protected $table = 'anuncio_programaciones';

    protected $fillable = ['anuncio_id', 'dias', 'hora', 'repeticiones', 'pausa'];

    protected function casts(): array
    {
        return [
            'dias' => 'array',
            'repeticiones' => 'integer',
            'pausa' => 'integer',
        ];
    }

    /** Días ISO, en el orden de la semana del laboratorio. */
    public const DIAS = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom',
    ];

    public function anuncio(): BelongsTo
    {
        return $this->belongsTo(Anuncio::class);
    }

    /** `17:50`, sin segundos aunque la base los guarde. */
    public function horaCorta(): string
    {
        return substr((string) $this->hora, 0, 5);
    }

    /**
     * «Lun a Vie · 17:50 · 2 veces», para la lista.
     *
     * Los días seguidos se juntan: «Lun a Vie» se lee de un vistazo, cinco
     * abreviaturas en fila no.
     */
    public function resumen(): string
    {
        $dias = collect($this->dias)->map(fn ($d) => (int) $d)->unique()->sort()->values();

        $texto = match (true) {
            $dias->count() === 7 => 'Todos los días',
            $dias->count() > 2 && $dias->last() - $dias->first() === $dias->count() - 1
                => self::DIAS[$dias->first()] . ' a ' . self::DIAS[$dias->last()],
            default => $dias->map(fn ($d) => self::DIAS[$d] ?? '?')->implode(', '),
        };

        return $texto . ' · ' . $this->horaCorta()
            . ($this->repeticiones > 1 ? ' · ' . $this->repeticiones . ' veces' : '');
    }
}
