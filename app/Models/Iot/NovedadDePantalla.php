<?php

namespace App\Models\Iot;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Algo del salón que alguien reportó desde el QR: un daño, algo que falta. */
class NovedadDePantalla extends Model
{
    protected $table = 'pantalla_novedades';

    protected $fillable = ['pantalla_id', 'actividad_id', 'user_id', 'descripcion', 'foto_path', 'contacto', 'resuelta_at', 'resuelta_por'];

    protected function casts(): array
    {
        return ['resuelta_at' => 'datetime'];
    }

    public function pantalla(): BelongsTo
    {
        return $this->belongsTo(Pantalla::class);
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadDePantalla::class, 'actividad_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por');
    }
}
