<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Área del laboratorio: unidad de certificación, espacio y responsable (§7). */
class Area extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'photo_path', 'position'];

    /** URL de la foto del area, o null si todavia no tiene. */
    public function fotoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/' . $this->photo_path) : null;
    }

    /** Quienes pueden certificar en esta área (§5). */
    public function responsibles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'area_responsibles')
            ->withPivot('is_backup')
            ->withTimestamps();
    }

    public function riskFamilies(): HasMany
    {
        return $this->hasMany(RiskFamily::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Los espacios donde vive esta área (§7).
     *
     * Muchos a muchos y no una columna: normalmente un área ocupa un espacio,
     * pero puede repartirse entre varios, y un espacio grande albergar varias.
     * Con una sola columna habría que mentir en esos casos.
     */
    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class)->withTimestamps();
    }
}
