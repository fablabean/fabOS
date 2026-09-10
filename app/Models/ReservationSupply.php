<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material gastado en una reserva, con su precio congelado (§12). */
class ReservationSupply extends Model
{
    protected $fillable = ['reservation_id', 'supply_id', 'origin', 'quantity', 'unit_price_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /**
     * De dónde salió (§8, §12).
     *
     * Tres casos, y cada uno hace algo distinto con el inventario y con el
     * costo:
     *
     *  · **del inventario**: el corriente. Sale de existencias y se cobra.
     *  · **incluido en el beneficio semanal**: sale de existencias igual —el
     *    material es del laboratorio y se gastó— pero no se cobra aparte,
     *    porque ya lo cubren los FabCoins de la semana.
     *  · **lo trajo el cliente**: no sale de existencias, porque nunca fue
     *    nuestro, y no se cobra.
     *
     * Los tres se anotan: la hoja de la producción tiene que decir qué se
     * usó, venga de donde venga.
     */
    public const INVENTARIO = 'inventario';
    public const BENEFICIO  = 'beneficio';
    public const CLIENTE    = 'cliente';

    public const ORIGENES = [
        self::INVENTARIO => 'Del inventario',
        self::BENEFICIO  => 'Incluido en el beneficio semanal',
        self::CLIENTE    => 'Lo trajo el cliente',
    ];

    public function origen(): string
    {
        return self::esOrigenValido($this->origin) ? $this->origin : self::INVENTARIO;
    }

    /** Lo del cliente no es nuestro: es lo único que no toca las existencias. */
    public function descuentaInventario(): bool
    {
        return $this->origen() !== self::CLIENTE;
    }

    /** Solo lo del inventario se cobra: lo demás ya está pagado o no es nuestro. */
    public function seCobra(): bool
    {
        return $this->origen() === self::INVENTARIO;
    }

    public static function esOrigenValido(?string $origen): bool
    {
        return isset(self::ORIGENES[(string) $origen]);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function totalMenor(): int
    {
        return (int) round((float) $this->quantity * $this->unit_price_minor);
    }
}
