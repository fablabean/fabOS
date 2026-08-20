<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que cada persona eligió dejar de recibir (§15). */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'key', 'email'];

    protected function casts(): array
    {
        return ['email' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
