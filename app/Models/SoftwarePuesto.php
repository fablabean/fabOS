<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un puesto de licencia, y a quién está asignado (§19).
 *
 * Responde a dos preguntas que llegan por separado: «¿nos alcanzan los
 * puestos?» al empezar el semestre, y «¿a quién hay que quitarle el acceso?»
 * cuando alguien se va.
 *
 * Por eso se **libera y no se borra**: borrar la fila contesta la primera y
 * deja la segunda sin historia, y además hace imposible saber cuántos puestos
 * se usaron de verdad el semestre pasado.
 */
class SoftwarePuesto extends Model
{
    protected $table = 'software_puestos';

    protected $fillable = [
        'software_id', 'user_id', 'etiqueta', 'asignado_el', 'liberado_el', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'asignado_el' => 'date',
            'liberado_el' => 'date',
        ];
    }

    public function software(): BelongsTo
    {
        return $this->belongsTo(Software::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estaOcupado(): bool
    {
        return $this->liberado_el === null;
    }

    public function scopeOcupados(Builder $q): Builder
    {
        return $q->whereNull('liberado_el');
    }

    /**
     * De quién es el puesto, dicho para quien mira la lista.
     *
     * La persona manda sobre la etiqueta: la etiqueta existe para los puestos
     * que no son de nadie del sistema —una cuenta del área, un equipo
     * compartido—, no para renombrar a alguien que sí está.
     */
    public function deQuienEs(): string
    {
        return $this->user?->name
            ?: ($this->etiqueta ?: 'Sin asignar');
    }
}
