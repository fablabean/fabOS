<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien miró un secreto, tal día (§5, §19).
 *
 * Una bóveda sin registro de lecturas no es una bóveda: es un tablón de
 * contraseñas con una puerta. Esto es lo que permite responder «¿quién tenía
 * esta clave cuando se filtró?», que es la única pregunta que importa el día
 * que hay que hacerla — y para entonces ya no se puede empezar a registrar.
 *
 * Sin `updated_at`: una lectura ocurrió una vez y no se edita.
 */
class CredencialLectura extends Model
{
    protected $table = 'credencial_lecturas';

    public $timestamps = false;

    protected $fillable = ['credencial_id', 'user_id', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class];
    }

    public function credencial(): BelongsTo
    {
        return $this->belongsTo(Credencial::class);
    }

    /** Quién miró. Puede haberse borrado; la lectura se queda igual. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quien(): string
    {
        return $this->user?->name ?? 'alguien que ya no está';
    }
}
