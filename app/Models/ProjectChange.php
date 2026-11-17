<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio en el historial de un proyecto: de quién a quién pasó, o de qué
 * etapa o estado a cuál. Lo anota el propio proyecto al guardarse, venga el
 * cambio de la ficha, de un servicio o del reparto automático.
 */
class ProjectChange extends Model
{
    public const UPDATED_AT = null;

    public const CAMPOS = [
        'responsable' => 'Responsable',
        'etapa'       => 'Etapa',
        'estado'      => 'Estado',
    ];

    protected $fillable = ['project_id', 'field', 'from_value', 'to_value', 'user_id', 'user_name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lo anota con quien está trabajando ahora, o sin nadie si fue el sistema. */
    public static function anotar(Project $proyecto, string $campo, ?string $antes, ?string $despues): self
    {
        $quien = auth()->user();

        return static::create([
            'project_id' => $proyecto->id,
            'field'      => $campo,
            'from_value' => $antes,
            'to_value'   => $despues,
            'user_id'    => $quien?->id,
            'user_name'  => $quien?->name,
        ]);
    }
}
