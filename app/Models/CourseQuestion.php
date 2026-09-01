<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pregunta del examen teorico (§9).
 *
 * De opcion multiple a proposito: son las que se corrigen sin una persona
 * delante, y eso es lo que permite que alguien haga el examen un domingo.
 */
class CourseQuestion extends Model
{
    protected $fillable = ['course_id', 'position', 'prompt', 'options', 'correct', 'explanation'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function esCorrecta(mixed $respuesta): bool
    {
        return $respuesta !== null && (int) $respuesta === $this->correct;
    }
}
