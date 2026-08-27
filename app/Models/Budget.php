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

    /**
     * El resumen del año: cuánto hay, en qué va y cuánto queda.
     *
     * Solo cuenta los de **gasto**, y a propósito. Un presupuesto de venta es
     * una meta, no plata asignada: sumar los $10 millones que esperamos
     * facturar al aprobado haría creer que hay diez millones más para gastar
     * que nadie ha girado. Se devuelve al lado, como control interno, para que
     * se vea sin confundirse con lo otro.
     *
     * Tampoco entran los borradores ni los cerrados: un presupuesto que aún se
     * está escribiendo no es plata que se pueda comprometer.
     *
     * @return array{gasto: array, venta: array}
     */
    public static function resumenDelAno(int $ano): array
    {
        $vigentes = static::where('year', $ano)->where('status', 'vigente')->get();

        $gasto = $vigentes->filter(fn (self $p) => ! $p->esDeVenta());
        $venta = $vigentes->filter(fn (self $p) => $p->esDeVenta());

        $aprobado = (int) $gasto->sum('amount');
        $comprometido = (int) $gasto->sum(fn (self $p) => $p->comprometido());
        $ejecutado = (int) $gasto->sum(fn (self $p) => $p->ejecutado());

        $meta = (int) $venta->sum('amount');
        $facturado = (int) $venta->sum(fn (self $p) => $p->ejecutado());

        return [
            'gasto' => [
                'cuantos'      => $gasto->count(),
                'aprobado'     => $aprobado,
                'comprometido' => $comprometido,
                'ejecutado'    => $ejecutado,
                'disponible'   => $aprobado - $comprometido - $ejecutado,
                'usado'        => $aprobado > 0
                    ? round((($comprometido + $ejecutado) / $aprobado) * 100, 1)
                    : 0.0,
                // Lo anotado a mano de antes del sistema, aparte: es lo unico
                // del resumen que nadie puede rastrear hasta una solicitud.
                'arranque'     => (int) $gasto->sum('opening_executed'),
            ],
            'venta' => [
                'cuantos'   => $venta->count(),
                'meta'      => $meta,
                'facturado' => $facturado,
                'falta'     => max(0, $meta - $facturado),
                'avance'    => $meta > 0 ? round(($facturado / $meta) * 100, 1) : 0.0,
            ],
        ];
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
