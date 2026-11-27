<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una evaluación respondida de un bootcamp (ver App\Support\EvaluacionAgentic). */
class EvaluacionBootcamp extends Model
{
    protected $table = 'evaluaciones_bootcamp';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respuestas' => 'array', 'aciertos' => 'integer', 'total' => 'integer'];
    }

    /** Sobre 5.0, como se califica en la U. */
    public function nota(): string
    {
        return number_format($this->total ? 5 * $this->aciertos / $this->total : 0, 1, ',', '');
    }
}
