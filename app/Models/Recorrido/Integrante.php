<?php

namespace App\Models\Recorrido;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien del equipo. Los visitantes no tienen cuenta: basta el nombre, y el
 * correo si lo dan, para el informe de participantes. Solo se les escribe a
 * quienes lo autorizaron.
 */
class Integrante extends Model
{
    protected $table = 'recorrido_integrantes';

    protected $fillable = ['equipo_id', 'nombre', 'correo', 'autoriza_contacto_at', 'user_id'];

    protected $hidden = ['correo'];

    protected function casts(): array
    {
        return ['autoriza_contacto_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Integrante $i) {
            $i->correo = filled($i->correo) ? mb_strtolower(trim($i->correo)) : null;
        });
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
