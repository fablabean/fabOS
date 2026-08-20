<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Espacio físico o virtual. Ambos se reservan igual (§6). */
class Space extends Model
{
    protected $fillable = [
        'area_id', 'location_id', 'slug', 'name', 'type', 'capacity',
        'is_reservable', 'is_production_space', 'setup_minutes', 'cleanup_minutes',
    ];

    protected function casts(): array
    {
        return [
            'is_reservable'       => 'boolean',
            'is_production_space' => 'boolean',
        ];
    }

    public const TIPOS = ['fisico' => 'Físico', 'virtual' => 'Virtual'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function reservations(): MorphMany
    {
        return $this->morphMany(Reservation::class, 'reservable');
    }
}
