<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Tarifa compuesta: tiempo, montaje, supervisión, material (§12). */
class RateCard extends Model
{
    protected $fillable = [
        'slug', 'name', 'rateable_type', 'rateable_id', 'basis', 'unit',
        'price_minor', 'setup_minor', 'supervision_hour_minor', 'minimum_minor',
        'deposit_minor', 'rounding_minutes', 'is_active', 'is_assumed',
        'effective_from', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active'      => 'boolean',
            'is_assumed'     => 'boolean',
            'effective_from' => 'date',
        ];
    }

    public const BASES = [
        'tiempo' => 'Por tiempo de uso',
        'unidad' => 'Por unidad de material',
        'fijo'   => 'Cobro fijo',
    ];

    /** Tarifa por defecto del laboratorio, la que aplica si nada más encaja. */
    public const DEFECTO = 'defecto';

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeVigente($query)
    {
        return $query->where('is_active', true)
            ->whereRaw('(effective_from IS NULL OR effective_from <= CURRENT_DATE)');
    }

    /**
     * Busca la tarifa aplicable a un equipo, de lo más específico a lo general.
     *
     * El orden importa: una máquina puede tener precio propio; si no lo tiene,
     * hereda el de su familia de riesgo (toda la impresión FDM cuesta igual); y
     * si tampoco, cae en la tarifa base del laboratorio. Así se administran
     * decenas de equipos cambiando unos pocos números.
     */
    public static function para(Asset $activo, string $basis = 'tiempo'): ?self
    {
        $candidatos = [
            [Asset::class, $activo->id],
            [RiskFamily::class, $activo->risk_family_id],
            [Area::class, $activo->area_id],
        ];

        foreach ($candidatos as [$tipo, $id]) {
            if (! $id) {
                continue;
            }

            $tarifa = static::vigente()
                ->where('rateable_type', $tipo)
                ->where('rateable_id', $id)
                ->where('basis', $basis)
                ->first();

            if ($tarifa) {
                return $tarifa;
            }
        }

        return static::vigente()->whereNull('rateable_type')->where('basis', $basis)->first();
    }
}
