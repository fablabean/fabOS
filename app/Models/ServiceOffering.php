<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Un servicio con precio cerrado (§14).
 *
 * «Corte láser por hoja de MDF de 3 mm», «impresión 3D hasta 10 cm». No tiene
 * existencia: se hace cuando alguien lo pide. Existe como cosa aparte de la
 * tarifa porque una tarifa es una **regla de cobro** —tantos FabCoins por hora
 * de esta máquina— y esto es una **oferta**: algo que se puede pedir sin saber
 * cuánto tarda la máquina ni qué es una hora de láser.
 */
class ServiceOffering extends Model
{
    protected $fillable = [
        'name', 'slug', 'area_id', 'description', 'unit',
        'price_minor', 'lead_time_days', 'photo_path', 'is_active', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $servicio) {
            $servicio->slug ??= Str::slug($servicio->name) . '-' . Str::lower(Str::random(4));
            $servicio->price_minor ??= 0;
        });
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function scopeEnLaTienda($query)
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    /** Por la ruta con permiso: la foto la sube el laboratorio, pero el disco es el privado. */
    public function fotoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/' . $this->photo_path) : null;
    }

    public function cuandoEstaListo(): ?string
    {
        if (! $this->lead_time_days) {
            return null;
        }

        return $this->lead_time_days === 1
            ? 'listo al día siguiente'
            : 'listo en ' . $this->lead_time_days . ' días';
    }

    /**
     * Los escalones de precio por cantidad, del mas barato al mas caro.
     *
     * Ordenados por cantidad para que quien los lea —la tienda, el carrito—
     * no tenga que ordenarlos otra vez y arriesgarse a ordenarlos distinto.
     */
    public function priceBreaks(): MorphMany
    {
        return $this->morphMany(PriceBreak::class, 'priceable')->orderBy('min_quantity');
    }
}
