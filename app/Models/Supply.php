<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Insumo: lo que se consume y se repone (§7, §13).
 *
 * Distinto de un activo. Un activo es una unidad identificable con placa y QR;
 * un insumo es una cantidad. No tiene sentido ponerle placa a un rollo de
 * filamento, pero sí saber cuánto queda y cuándo pedir más.
 */
class Supply extends Model
{
    protected $fillable = [
        'area_id', 'category_id', 'location_id', 'name', 'kind', 'sku', 'unit',
        'largo_cm', 'ancho_cm', 'description',
        'photo_path', 'public_description',
        'stock', 'reorder_point', 'max_stock', 'last_cost', 'is_active', 'is_public',
        'por_encargo', 'dias_por_encargo',
        'ilustracion_path', 'ilustracion_prompt', 'ilustracion_generada_el',
    ];

    protected function casts(): array
    {
        return [
            // Sin este cast llega como TEXTO y la ficha revienta al
            // pintarla: el error no sale hasta que hay una ilustracion
            // que enseñar, que es mucho despues de escribir el campo.
            'ilustracion_generada_el' => UtcDateTime::class,
            'stock' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'max_stock' => 'decimal:3',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Insumo o producto terminado.
     *
     * Comparten tabla porque comparten lo que importa: se cuentan, se
     * descuentan y se reponen. Lo que cambia es quién los compra —uno se lo
     * lleva quien va a fabricar; el otro, quien no— y eso solo afecta a cómo se
     * agrupan en la tienda.
     */
    public const TIPOS = [
        'insumo' => 'Insumo',
        'producto' => 'Producto terminado',
    ];

    public function esProducto(): bool
    {
        return $this->kind === 'producto';
    }

    /*
     |--------------------------------------------------------------------------
     | Lo que viene en lámina
     |--------------------------------------------------------------------------
     | El MDF se compra en hojas de 120×90 y se gasta en trozos de 30×40. Con
     | las medidas guardadas, quien cierra una producción escribe el pedazo que
     | cortó y no tiene que hacer la regla de tres —ni acabar anotando una
     | lámina entera, que descuenta de más y cobra de más—.
     */

    public function seMideEnLamina(): bool
    {
        return (float) $this->largo_cm > 0 && (float) $this->ancho_cm > 0;
    }

    /** Centímetros cuadrados de una lámina entera. */
    public function areaDeLaLamina(): ?float
    {
        return $this->seMideEnLamina()
            ? (float) $this->largo_cm * (float) $this->ancho_cm
            : null;
    }

    /** «120 × 90 cm», para decirlo en pantalla. */
    public function formato(): ?string
    {
        if (! $this->seMideEnLamina()) {
            return null;
        }

        $numero = fn (float $v) => rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');

        return $numero((float) $this->largo_cm) . ' × ' . $numero((float) $this->ancho_cm) . ' cm';
    }

    /**
     * Qué fracción de lámina es un trozo de tantos por tantos.
     *
     * Cuatro decimales: un recorte pequeño en una hoja grande da números muy
     * por debajo de la centésima, y redondear antes de tiempo lo dejaría en
     * cero, que es justo lo que se venía haciendo a mano.
     */
    public function laminasDeUnTrozo(float $largo, float $ancho): ?float
    {
        $area = $this->areaDeLaLamina();

        if (! $area || $largo <= 0 || $ancho <= 0) {
            return null;
        }

        return round($largo * $ancho / $area, 4);
    }

    /**
     * Si se puede pedir aunque no haya existencia.
     *
     * Un fablab no tiene cien llaveros en un cajon: los hace cuando se los
     * piden. Sin esto, todo lo que el laboratorio SABE fabricar queda
     * invisible hasta que alguien produce un lote por si acaso.
     */
    public function seFabricaPorEncargo(): bool
    {
        return (bool) $this->por_encargo;
    }

    /** Si hoy se puede ofrecer: o hay, o se hace. */
    public function sePuedePedir(): bool
    {
        return (float) $this->stock > 0 || $this->seFabricaPorEncargo();
    }

    /** El plazo, dicho para quien compra. Nulo si nadie lo ha medido. */
    public function cuandoEstaListo(): ?string
    {
        if ((float) $this->stock > 0) {
            return null;
        }

        if (! $this->dias_por_encargo) {
            return 'por encargo';
        }

        return $this->dias_por_encargo === 1
            ? 'por encargo · listo mañana'
            : 'por encargo · listo en '.$this->dias_por_encargo.' días';
    }

    /** Lo que se puede mirar y comprar sin ser del laboratorio. */
    public function scopeEnLaTienda($query)
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    /**
     * Lo que hoy se puede ofrecer: hay existencia, o se fabrica por encargo.
     *
     * La regla de «solo lo que hay» sigue valiendo para los insumos —o hay
     * lamina de MDF o no la hay—; lo que se fabrica se ofrece con su plazo por
     * delante, que es la diferencia entre «se agoto» y «te lo tenemos el
     * jueves».
     */
    public function scopeOfrecible($query)
    {
        return $query->where(fn ($q) => $q->where('stock', '>', 0)->orWhere('por_encargo', true));
    }

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupplyCategory::class, 'category_id');
    }

    /**
     * Cuánto habría que pedir para volver al máximo.
     *
     * El punto de reposición dice **cuándo** comprar; el máximo dice **cuánto**.
     * Sin el segundo, quien repone sabe que hay que comprar pero no cuánto, y
     * acaba comprando lo que le parece: de más y ocupa bodega, o de menos y en
     * dos semanas vuelve a faltar.
     */
    public function cuantoPedir(): ?float
    {
        if (! $this->max_stock) {
            return null;
        }

        return max(0, round((float) $this->max_stock - (float) $this->stock, 3));
    }

    public function estaBajoMinimo(): bool
    {
        return $this->reorder_point !== null
            && (float) $this->stock <= (float) $this->reorder_point;
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SupplyMovement::class);
    }

    /** Bajo mínimos: es lo que dispara la siguiente compra. */
    public function bajoMinimos(): bool
    {
        return $this->reorder_point !== null
            && (float) $this->stock <= (float) $this->reorder_point;
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
