<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una canción que alguien puso en la rocola del Parlante (§3). */
class CancionPedida extends Model
{
    protected $table = 'canciones_pedidas';

    protected $fillable = [
        'user_id', 'spotify_id', 'titulo', 'artista', 'portada_url',
        'duracion_ms', 'cobrado_minor', 'estado', 'motivo',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function duracion(): string
    {
        $s = intdiv($this->duracion_ms, 1000);

        return intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    }
}
