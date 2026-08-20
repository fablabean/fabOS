<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Área del laboratorio: unidad de certificación, espacio y responsable (§7). */
class Area extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'position'];

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

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
