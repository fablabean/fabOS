<?php

namespace App\Models\Recorrido;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien del equipo. Los visitantes no tienen cuenta: basta el nombre, y el
 * correo si lo dan, para el informe de participantes. Solo se les escribe a
 * quienes lo autorizaron.
 *
 * A un menor de edad no se le guarda correo: escribirle pide la autorización
 * de sus padres (Ley 1581 de 2012), y esa no la tenemos.
 */
class Integrante extends Model
{
    protected $table = 'recorrido_integrantes';

    protected $attributes = ['menor_de_edad' => false];

    protected $fillable = ['equipo_id', 'nombre', 'menor_de_edad', 'correo', 'autoriza_contacto_at', 'user_id'];

    protected $hidden = ['correo'];

    protected function casts(): array
    {
        return ['menor_de_edad' => 'boolean', 'autoriza_contacto_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Integrante $i) {
            $i->correo = filled($i->correo) && ! $i->menor_de_edad ? mb_strtolower(trim($i->correo)) : null;

            // Sin correo no hay a quién escribirle: tampoco autorización que guardar.
            if (! $i->correo) {
                $i->autoriza_contacto_at = null;
            }
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
