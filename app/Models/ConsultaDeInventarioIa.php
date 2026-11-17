<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un mensaje que se le escribió a Activo IA, y lo que contestó.
 *
 * No toca el inventario: es la memoria de lo que se preguntó, para poder
 * medir cuánto acierta —qué propuso y qué terminó guardándose— y para saber
 * por qué falló cuando falla.
 */
class ConsultaDeInventarioIa extends Model
{
    protected $table = 'consultas_de_inventario_ia';

    protected $fillable = [
        'user_id', 'conversacion', 'instruccion', 'foto_path', 'respuesta',
        'herramientas', 'propuestas', 'duplicados_detectados', 'modelo',
        'tokens_entrada', 'tokens_salida', 'estado', 'error',
    ];

    protected function casts(): array
    {
        return [
            'herramientas' => 'array',
            'propuestas' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acciones(): HasMany
    {
        return $this->hasMany(AccionDeInventarioIa::class, 'consulta_id');
    }
}
