<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una foto confirmada de un activo o insumo, con su huella.
 *
 * Es lo que permite reconocer el objeto la próxima vez. Va en el disco
 * privado: es una foto de trabajo, no la de la vitrina pública.
 */
class FotoDeInventario extends Model
{
    protected $table = 'fotos_de_inventario';

    protected $fillable = ['registro_type', 'registro_id', 'ruta', 'huella', 'modelo_huella', 'user_id'];

    protected function casts(): array
    {
        return ['huella' => 'array'];
    }

    public function registro(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
