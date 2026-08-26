<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que se dijo sobre la propuesta (§11).
 *
 * Una propuesta que solo se puede aceptar o ignorar obliga a salir del sistema
 * para decir «casi, pero cambia la fecha», y esa frase acaba en un chat donde
 * nadie la vuelve a encontrar. Aquí queda junto al proyecto, que es donde se
 * lee cuando hay que recordar qué se acordó.
 *
 * Quien comenta puede no tener cuenta: se responde por el enlace del correo, y
 * en ese caso lo que identifica es el nombre que quedó en el contacto.
 */
class ProjectComment extends Model
{
    protected $fillable = ['project_id', 'user_id', 'author_name', 'side', 'body'];

    public const LADOS = [
        'cliente'     => 'Quien pidió',
        'laboratorio' => 'El laboratorio',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quien(): string
    {
        return $this->user?->name
            ?: $this->author_name
            ?: (self::LADOS[$this->side] ?? 'Alguien');
    }
}
