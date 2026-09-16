<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

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
        'ilustracion_path', 'ilustracion_prompt', 'ilustracion_generada_el',
    ];

    protected function casts(): array
    {
        return [
            // Sin este cast llega como TEXTO y la ficha revienta al
            // pintarla: el error no sale hasta que hay una ilustracion
            // que enseñar, que es mucho despues de escribir el campo.
            'ilustracion_generada_el' => UtcDateTime::class,
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $servicio) {
            $servicio->slug ??= Str::slug($servicio->name).'-'.Str::lower(Str::random(4));
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
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }

    /**
     * La imagen que se enseña, y si es una ilustracion.
     *
     * La foto real manda siempre. La ilustracion generada solo aparece cuando
     * no hay foto, y sale marcada: una imagen inventada de algo que alguien va
     * a comprar no es una foto, y presentarla como tal promete un acabado que
     * nadie ha fabricado.
     *
     * @return array{url: string, esIlustracion: bool}|null
     */
    public function imagen(): ?array
    {
        if ($this->photo_path) {
            return ['url' => asset('storage/'.$this->photo_path), 'esIlustracion' => false];
        }

        if ($this->ilustracion_path) {
            return ['url' => asset('storage/'.$this->ilustracion_path), 'esIlustracion' => true];
        }

        return null;
    }

    public function tieneIlustracion(): bool
    {
        return filled($this->ilustracion_path) && blank($this->photo_path);
    }

    public function cuandoEstaListo(): ?string
    {
        if (! $this->lead_time_days) {
            return null;
        }

        return $this->lead_time_days === 1
            ? 'listo al día siguiente'
            : 'listo en '.$this->lead_time_days.' días';
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

    /**
     * Lo que cobra el mercado por esto mismo.
     *
     * Nuestro precio sale del costo; esto guarda con que se comparo y
     * cuando, para poder responder «¿esta caro?» con una fuente.
     */
    public function referenciasDePrecio(): MorphMany
    {
        return $this->morphMany(ReferenciaDePrecio::class, 'priceable')
            ->orderByDesc('consultado_el');
    }
}
