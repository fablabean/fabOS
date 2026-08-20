<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** Rutina preventiva sobre un equipo o una familia de riesgo (§8). */
class MaintenancePlan extends Model
{
    protected $fillable = [
        'name', 'asset_id', 'risk_family_id',
        'every_days', 'every_usage_minutes', 'checklist', 'is_active', 'instructions',
    ];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'is_active' => 'boolean'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function riskFamily(): BelongsTo
    {
        return $this->belongsTo(RiskFamily::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Equipos que cubre este plan.
     *
     * @return Collection<int,Asset>
     */
    public function equipos(): Collection
    {
        if ($this->asset_id) {
            return collect([$this->asset])->filter();
        }

        return Asset::where('risk_family_id', $this->risk_family_id)
            ->whereNot('status', 'baja')
            ->get();
    }
}
