<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Árbol físico: sede › piso › sala › estante › gaveta (§7). */
class Location extends Model
{
    protected $fillable = ['parent_id', 'name', 'path', 'qr_token', 'space_id',];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** El espacio donde está este mueble (§7). */
    public function space(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
