<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un curso de la escalera de formación (§9).
 *
 * Los niveles no son adorno: bit, byte, kilo, mega, giga y tera marcan cuánta
 * autonomía puede llegar a tener alguien. tera es Fab Academy.
 */
class Course extends Model
{
    protected $fillable = [
        'slug', 'name', 'area_id', 'level', 'summary', 'description',
        'requirements', 'hours', 'photo_path', 'price_minor', 'is_active', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public const NIVELES = [
        'bit'  => 'bit · primer contacto',
        'byte' => 'byte · uso básico',
        'kilo' => 'kilo · uso autónomo',
        'mega' => 'mega · proyectos propios',
        'giga' => 'giga · acompañar a otros',
        'tera' => 'tera · Fab Academy',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function editions(): HasMany
    {
        return $this->hasMany(CourseEdition::class);
    }

    /** Qué habilita aprobarlo. Sin esto un curso es solo una charla. */
    public function riskFamilies(): BelongsToMany
    {
        return $this->belongsToMany(RiskFamily::class, 'course_risk_family');
    }

    public function precio(): float
    {
        return $this->price_minor / config('fabos.currency.minor_units');
    }

    /** Ediciones a las que todavía se puede entrar. */
    public function edicionesAbiertas(): HasMany
    {
        return $this->editions()->where('status', 'abierta')->orderBy('starts_on');
    }
}
