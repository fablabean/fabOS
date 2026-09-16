<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Lo que cobra otro por lo mismo (§14).
 *
 * Nuestro precio sale del costo. Esto guarda la otra mitad de la conversación
 * —lo que cobra el mercado— para poder responder «¿está caro?» con una fuente
 * y una fecha en vez de con una impresión.
 *
 * En pesos, que es como cotiza el mercado. Convertirlo a FabCoins al guardarlo
 * escondería el dato original detrás de una tasa que cambia.
 */
class ReferenciaDePrecio extends Model
{
    protected $table = 'referencias_de_precio';

    protected $fillable = [
        'priceable_type', 'priceable_id',
        'fuente', 'url', 'precio_pesos', 'unidad', 'consultado_el', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'precio_pesos' => 'integer',
            'consultado_el' => 'date',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Cuántos meses tiene la referencia.
     *
     * Sirve para lo unico que importa de la fecha: saber cuando dejo de
     * respaldar nada. Un precio consultado hace tres años no defiende una
     * tarifa, la delata.
     */
    public function antiguedadEnMeses(): int
    {
        return (int) $this->consultado_el->diffInMonths(now());
    }

    /** Doce meses: pasado un año, el mercado ya se movio. */
    public function estaVieja(): bool
    {
        return $this->antiguedadEnMeses() >= 12;
    }

    public function enPesos(): string
    {
        return config('fabos.money.symbol').number_format($this->precio_pesos, 0, ',', '.');
    }
}
