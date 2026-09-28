<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una pregunta de la encuesta de después (§9). */
class SurveyQuestion extends Model
{
    protected $fillable = ['course_id', 'position', 'type', 'label', 'options', 'required'];

    protected function casts(): array
    {
        return [
            'options'  => 'array',
            'required' => 'boolean',
        ];
    }

    public const TIPOS = [
        'escala'    => 'Escala de 1 a 5',
        'seleccion' => 'Selección (una opción)',
        'si_no'     => 'Sí o no',
        'texto'     => 'Respuesta abierta',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return list<string> */
    public function opciones(): array
    {
        if ($this->type === 'si_no') {
            return ['Sí', 'No'];
        }

        return collect((array) $this->options)
            ->map(fn ($o) => is_array($o) ? trim((string) ($o['texto'] ?? '')) : trim((string) $o))
            ->filter()
            ->values()
            ->all();
    }
}
