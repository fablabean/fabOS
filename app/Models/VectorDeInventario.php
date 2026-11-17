<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * El nombre de un activo o insumo convertido en vector, para buscar «lo que
 * más se le parece» a lo que ve la IA en una foto.
 *
 * `huella_texto` dice de qué texto salió: si cambia el nombre, la marca o el
 * modelo, deja de coincidir y se recalcula.
 */
class VectorDeInventario extends Model
{
    protected $table = 'vectores_de_inventario';

    protected $fillable = ['registro_type', 'registro_id', 'huella_texto', 'vector', 'modelo'];

    protected function casts(): array
    {
        return ['vector' => 'array'];
    }

    public function registro(): MorphTo
    {
        return $this->morphTo();
    }
}
