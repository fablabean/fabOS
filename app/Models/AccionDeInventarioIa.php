<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Lo que una persona confirmó de una propuesta de Activo IA.
 *
 * Es el historial: quién, cuándo, sobre qué registro, el valor de antes y el
 * de después. Guarda además lo que propuso la IA tal cual y qué campos
 * corrigió la persona antes de confirmar, que es la medida honesta de cuánto
 * trabajo ahorra.
 *
 * No se edita ni se borra desde la pantalla: un historial que se puede
 * retocar no sirve de historial.
 */
class AccionDeInventarioIa extends Model
{
    protected $table = 'acciones_de_inventario_ia';

    public const ACCIONES = [
        'crear_activo' => 'Registró un activo',
        'crear_insumo' => 'Registró un insumo',
        'trasladar_activo' => 'Trasladó un activo',
        'entrada_insumo' => 'Entrada de insumo',
        'vincular_foto' => 'Reconoció por foto',
        'completar_ficha' => 'Completó la ficha',
    ];

    protected $fillable = [
        'consulta_id', 'user_id', 'accion', 'registro_type', 'registro_id',
        'antes', 'despues', 'propuesta', 'corregidos',
    ];

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'despues' => 'array',
            'propuesta' => 'array',
            'corregidos' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(ConsultaDeInventarioIa::class, 'consulta_id');
    }

    public function registro(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
