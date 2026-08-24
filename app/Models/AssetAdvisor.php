<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quién asesora sobre un equipo, y quién responde por él (§10). */
class AssetAdvisor extends Model
{
    protected $table = 'asset_advisors';

    protected $fillable = ['user_id', 'asset_id', 'es_responsable'];

    protected function casts(): array
    {
        return ['es_responsable' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
