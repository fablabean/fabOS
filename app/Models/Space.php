<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Espacio físico o virtual. Ambos se reservan igual (§6). */
class Space extends Model
{
    protected $fillable = [
        'slug', 'name', 'type', 'capacity',
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

    /**
     * Las áreas que ocupan este espacio.
     *
     * Normalmente una sola —el taller de láser es el área de láser— pero un
     * área puede repartirse entre varios espacios, y un espacio grande albergar
     * varias. Modelarlo con una sola columna obligaba a mentir en esos casos.
     */
    public function areas(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Area::class)->withTimestamps();
    }

    /** Los muebles que hay dentro: racks, mesas, gabinetes (§7). */
    public function locations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Location::class);
    }
}
