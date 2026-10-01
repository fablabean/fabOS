<?php

namespace App\Models\Recorrido;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alguien del equipo. Basta el nombre: los visitantes no tienen cuenta. */
class Integrante extends Model
{
    protected $table = 'recorrido_integrantes';

    protected $fillable = ['equipo_id', 'nombre', 'user_id'];

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
