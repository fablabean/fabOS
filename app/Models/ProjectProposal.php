<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versión de la propuesta, tal como se mandó (§11).
 *
 * Una propuesta se negocia: se manda, el cliente pide bajar el alcance, se
 * manda otra. Sin versiones, a la tercera nadie sabe qué se ofreció la primera
 * vez, que es justo la pregunta que aparece cuando alguien dice «pero ustedes
 * habían dicho…».
 *
 * Guarda el **contenido**, no una referencia: los entregables de la v1 pueden
 * haberse borrado al redactar la v2, y una versión que apunta a algo que ya no
 * existe no es una versión.
 */
class ProjectProposal extends Model
{
    protected $fillable = [
        'project_id', 'version', 'estimated_value',
        'starts_on', 'due_on', 'message', 'deliverables', 'sent_at', 'sent_by',
    ];

    protected function casts(): array
    {
        return [
            'deliverables' => 'array',
            'starts_on'    => 'date',
            'due_on'       => 'date',
            'sent_at'      => UtcDateTime::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** «v3», que es como se nombra en el asunto del correo. */
    public function etiqueta(): string
    {
        return 'v' . $this->version;
    }
}
