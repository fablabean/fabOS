<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Subgrupo de riesgo dentro de un área (§7). Es lo que realmente se certifica:
 * FDM y resina no son lo mismo, ni una lijadora y una sierra de banco.
 */
class RiskFamily extends Model
{
    protected $fillable = [
        'area_id', 'slug', 'name', 'required_course_level',
        'requires_companion', 'safety_notes',
    ];

    protected function casts(): array
    {
        return ['requires_companion' => 'boolean'];
    }

    public const NIVELES = [
        'bit' => 'bit', 'byte' => 'byte', 'kilo' => 'kilo',
        'mega' => 'mega', 'giga' => 'giga', 'tera' => 'tera',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function certifabs(): HasMany
    {
        return $this->hasMany(Certifab::class);
    }
}
