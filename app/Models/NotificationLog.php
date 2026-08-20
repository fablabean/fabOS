<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Bitácora de envíos (§15).
 *
 * Guarda también lo que NO se envió y por qué. «¿Le avisaron?» es la pregunta
 * que más se repite cuando algo sale mal, y un registro que solo tuviera los
 * envíos exitosos no la respondería.
 */
class NotificationLog extends Model
{
    protected $fillable = [
        'user_id', 'key', 'channel', 'to', 'subject', 'body',
        'status', 'reason', 'reference_type', 'reference_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => UtcDateTime::class];
    }

    public const ESTADOS = [
        'enviado' => 'Enviado',
        'omitido' => 'Omitido',
        'fallido' => 'Falló',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
