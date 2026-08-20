<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una revisión de inventario sobre un activo (§7). */
class AssetCheck extends Model
{
    protected $fillable = ['asset_id', 'location_id', 'user_id', 'result', 'note', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => UtcDateTime::class];
    }

    public const RESULTADOS = [
        'presente' => 'Presente',
        'ausente'  => 'No está',
        'movido'   => 'Está en otro lado',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
