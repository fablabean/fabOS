<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una respuesta, publicada solo cuando una persona la aprueba (§20).
 *
 * `origen` se conserva aunque alguien haya corregido el texto: quien lee tiene
 * derecho a saber que hubo una máquina en el origen.
 */
class Answer extends Model
{
    protected $fillable = [
        'question_id', 'user_id', 'body', 'origen',
        'publicada', 'publicada_at', 'aprobada_por',
    ];

    public const PERSONA = 'persona';
    public const IA      = 'ia';

    protected function casts(): array
    {
        return [
            'publicada'    => 'boolean',
            'publicada_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }

    public function vieneDeIa(): bool
    {
        return $this->origen === self::IA;
    }
}
