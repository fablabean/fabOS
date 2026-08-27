<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Presupuesto anual del laboratorio (§13).
 *
 * El saldo NO se guarda: se deriva de las solicitudes, igual que en el libro
 * contable. Un campo «disponible» editable a mano es exactamente lo que hace
 * que a mitad de año nadie sepa cuánto queda de verdad.
 */
class Budget extends Model
{
    protected $fillable = [
        'name', 'kind', 'year', 'area_id', 'amount',
        'opening_executed', 'opening_note', 'status', 'notes',
    ];

    public const ESTADOS = [
        'borrador' => 'Borrador',
        'vigente'  => 'Vigente',
        'cerrado'  => 'Cerrado',
    ];

    /**
     * No todo presupuesto es para gastar.
     *
     * El de **gasto** es plata que la Universidad asigna y que se consume. El
     * de **venta** es lo contrario: una meta de ingresos a la que se acerca lo
     * que el laboratorio factura. Es la misma idea leída al revés, y por eso
     * comparte tabla en vez de tener una pantalla propia.
     */
    public const TIPOS = [
        'gasto' => 'De gasto',
        'venta' => 'De venta',
    ];

    public function esDeVenta(): bool
    {
        return $this->kind === 'venta';
    }

    /** Solicitudes que ya reservan plata pero cuya compra no ha llegado. */
    private const COMPROMETEN = ['aprobada', 'en_compra', 'recibida_parcial'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /**
     * Comprometido: aprobado y todavía sin recibir.
     *
     * Se cuenta lo pendiente de cada solicitud, no su total: si de diez rollos
     * llegaron seis, solo cuatro siguen comprometidos.
     */
    public function comprometido(): int
    {
        return $this->requests()
            ->whereIn('status', self::COMPROMETEN)
            ->with('items')
            ->get()
            ->sum(fn (PurchaseRequest $s) => $s->pendienteEnPesos());
    }

    /**
     * Ejecutado: lo que efectivamente se movió.
     *
     * En un presupuesto de **gasto**, lo que llegó de las compras, al precio
     * con el que se pidió. En uno de **venta**, lo que entró por el mostrador.
     *
     * A los dos se les suma el **arranque**: lo que ya se había movido antes de
     * que existiera el sistema. Sin eso, el presupuesto enseñaría como
     * disponible una plata que ya no está.
     */
    public function ejecutado(): int
    {
        return (int) $this->opening_executed + $this->ejecutadoDelSistema();
    }

    /** Lo que el sistema puede demostrar, sin el arranque anotado a mano. */
    public function ejecutadoDelSistema(): int
    {
        if ($this->esDeVenta()) {
            return $this->ventasDelAno();
        }

        return $this->requests()
            ->whereIn('status', ['recibida_parcial', 'recibida'])
            ->with('items')
            ->get()
            ->sum(fn (PurchaseRequest $s) => $s->recibidoEnPesos());
    }

    /**
     * Lo que entró por ventas en el año del presupuesto, en pesos.
     *
     * Las ventas viven en FabCoins —es la moneda con que se cobra dentro— y el
     * presupuesto se habla en pesos con la Universidad. La conversión usa la
     * tasa configurada, y por eso es una **equivalencia**, no un extracto
     * bancario: conviene decirlo donde se muestre.
     */
    public function ventasDelAno(): int
    {
        $menor = Sale::query()
            ->where('status', 'pagada')
            ->whereYear('created_at', $this->year)
            ->when($this->area_id, function ($q) {
                // Un presupuesto de venta partido por area cuenta solo lo que
                // se vendio de insumos de esa area.
                $q->whereHas('items.supply', fn ($i) => $i->where('area_id', $this->area_id));
            })
            ->with('items')
            ->get()
            ->sum(fn (Sale $v) => (int) ($v->total_minor ?: $v->totalMenor()));

        $unidades = (int) config('fabos.currency.minor_units');
        $tasa = (int) config('fabos.currency.peso_rate');

        return (int) round($menor / $unidades * $tasa);
    }

    /**
     * En un presupuesto de gasto, lo que queda por gastar. En uno de venta, lo
     * que falta para llegar a la meta.
     */
    public function disponible(): int
    {
        return $this->amount - $this->comprometido() - $this->ejecutado();
    }

    /** Cuánto se ha usado, para pintar una barra sin dividir por cero. */
    public function porcentajeUsado(): float
    {
        if ($this->amount <= 0) {
            return 0;
        }

        return round((($this->comprometido() + $this->ejecutado()) / $this->amount) * 100, 1);
    }
}
