<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una lista de candidatos que llegó de golpe (§11).
 *
 * Veinte spin-offs de la incubadora, los ganadores de una convocatoria, los
 * semilleros de una facultad. El laboratorio no puede tomarlos todos: hay que
 * mirarlos uno a uno y decidir cuáles entran.
 */
class CandidateBatch extends Model
{
    protected $fillable = ['name', 'source', 'description', 'received_on', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    public const ESTADOS = [
        'abierto'  => 'Abierto',
        'evaluado' => 'Evaluado',
        'cerrado'  => 'Cerrado',
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'batch_id')->orderBy('position')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Cuántos van, para saber si queda trabajo por hacer. */
    public function pendientes(): int
    {
        return $this->candidates()->where('status', 'pendiente')->count();
    }

    public function aceptados(): int
    {
        return $this->candidates()->where('status', 'aceptado')->count();
    }

    /** Aceptados que todavía no son proyecto: es lo que falta por arrancar. */
    public function sinConvertir(): int
    {
        return $this->candidates()
            ->where('status', 'aceptado')
            ->whereNull('project_id')
            ->count();
    }
}
