<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien del equipo de un proyecto (§11).
 *
 * Puede no tener cuenta: un proveedor o el propio cliente son parte del equipo
 * aunque nunca entren al sistema, y tienen que aparecer en el acta.
 */
class ProjectMember extends Model
{
    protected $fillable = [
        'project_id', 'user_id', 'external_name', 'organization', 'role', 'note',
    ];

    public const ROLES = [
        'responsable' => 'Responsable',
        'equipo'      => 'Equipo',
        'proveedor'   => 'Proveedor',
        'cliente'     => 'Cliente',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nombre(): string
    {
        return $this->user?->name ?? $this->external_name ?? 'sin nombre';
    }
}
