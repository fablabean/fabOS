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
        'name', 'pauta', 'asset_id', 'risk_family_id',
        'every_days', 'every_usage_minutes', 'starts_on', 'checklist', 'is_active', 'instructions',
    ];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'is_active' => 'boolean', 'starts_on' => 'date'];
    }

    /**
     * Las franjas de la pauta preventiva: cada cuánto, en días.
     *
     * @var array<string, array{0:int,1:string}> clave => [días, nombre]
     */
    public const PAUTA = [
        'mensual'       => [30, 'Cada mes'],
        'bimestral'     => [60, 'Cada 2 meses'],
        'trimestral'    => [90, 'Cada 3 meses'],
        'cuatrimestral' => [120, 'Cada 4 meses'],
        'semestral'     => [180, 'Cada 6 meses (dos veces al año)'],
    ];

    /** Los equipos elegidos a mano para este plan. */
    public function assets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Asset::class)->withTimestamps();
    }

    /**
     * Los puntos de la lista de chequeo, como texto.
     *
     * @return list<string>
     */
    public function puntos(): array
    {
        return self::puntosDe($this->checklist);
    }

    /** @return list<string> */
    public static function puntosDe(mixed $checklist): array
    {
        return collect(is_array($checklist) ? $checklist : [])
            ->map(fn ($p) => is_array($p) ? ($p['punto'] ?? $p['label'] ?? null) : $p)
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->map(fn ($p) => trim($p))
            ->values()
            ->all();
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
        // Elegidos a mano, si los tiene: es lo que arma la pauta.
        if ($this->assets()->exists()) {
            return $this->assets()->whereNot('status', 'baja')->get();
        }

        if ($this->asset_id) {
            return collect([$this->asset])->filter();
        }

        return Asset::where('risk_family_id', $this->risk_family_id)
            ->whereNot('status', 'baja')
            ->get();
    }
}
