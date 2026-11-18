<?php

namespace App\Models\Recorrido;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una penalización o una recompensa que mandaron las gafas desde un mini juego.
 *
 * Queda con el mini juego que la dio y el identificador que manda la app, para
 * que un reintento por mala señal no la cuente dos veces.
 */
class Evento extends Model
{
    protected $table = 'recorrido_eventos';

    public const PENALIZACION = 'penalizacion';
    public const RECOMPENSA = 'recompensa';

    protected $fillable = ['equipo_id', 'tipo', 'segundos', 'etapa', 'juego', 'evento_id'];

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }
}
