<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un deseo del laboratorio (§13).
 *
 * Lo que hace falta y todavía no se ha pedido. No compromete plata, no exige
 * presupuesto y puede no tener precio: es el paso anterior al carrito, y el
 * insumo con el que se propone el presupuesto del año siguiente.
 *
 * **No hay listas, hay deseos sueltos.** La lista es el filtro —año destino más
 * área—, y un deseo que no alcanzó se pasa al año siguiente cambiándole el año
 * en vez de copiarlo a otra parte.
 */
class Wish extends Model
{
    protected $fillable = [
        'target_year', 'area_id', 'supply_id', 'description', 'justification',
        'unit', 'quantity', 'unit_price', 'priority', 'reference_url',
        'requested_by', 'purchase_request_item_id',
        'discarded_at', 'discarded_reason', 'notes',
    ];

    public const PRIORIDADES = [
        'alta'  => 'Alta',
        'media' => 'Media',
        'baja'  => 'Baja',
    ];

    /**
     * Los cuatro estados en los que puede estar un deseo.
     *
     * Ninguno se guarda: tres salen del enlace con la línea de compra y el
     * cuarto de la fecha en que alguien decidió que no. Ver `estado()`.
     */
    public const ESTADOS = [
        'abierto'      => 'En la lista',
        'en_solicitud' => 'En solicitud',
        'comprado'     => 'Comprado',
        'descartado'   => 'Descartado',
    ];

    /** Solicitudes que ya no van a traer nada: el deseo vuelve a la lista. */
    private const MUERTAS = ['rechazada', 'cancelada'];

    protected function casts(): array
    {
        return [
            'quantity'     => 'decimal:3',
            'discarded_at' => UtcDateTime::class,
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** La línea de compra en la que terminó, si alguien ya lo pidió. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    /** En qué solicitud terminó. Lo que se enseña en pantalla es su código. */
    public function solicitud(): ?PurchaseRequest
    {
        return $this->item?->request;
    }

    // ------------------------------------------------------------ la cuenta

    /**
     * Cantidad por estimado, en pesos enteros. **Nulo** si nadie lo ha cotizado.
     *
     * Nulo y no cero, a propósito: un cero suma bien y miente. Quien mira el
     * resumen del año vería una cifra completa que deja fuera justo lo que
     * todavía no se ha averiguado, y el año siguiente se pediría de menos sin
     * que nadie supiera por qué.
     *
     * Sin impuesto: el IVA se dice por solicitud —no todo lo lleva— y se aplica
     * una sola vez, en el resumen del año, donde se enseñan las dos cifras.
     */
    public function estimado(): ?int
    {
        if ($this->unit_price === null) {
            return null;
        }

        return (int) round((float) $this->quantity * (int) $this->unit_price);
    }

    /**
     * En qué punto está este deseo.
     *
     * Se **deriva**, nunca se guarda. Si la solicitud se cancela o alguien quita
     * la línea, el deseo vuelve solo a la lista: no hay nada que acordarse de
     * actualizar, y por eso no puede quedarse mintiendo.
     *
     * El orden importa: lo ya recibido sigue comprado aunque después se cancele
     * el resto de la solicitud. Llegó, y volver a desearlo sería pedirlo dos
     * veces.
     */
    public function estado(): string
    {
        if ($this->discarded_at) {
            return 'descartado';
        }

        if (! $this->item) {
            return 'abierto';
        }

        if ((float) $this->item->received_quantity >= (float) $this->item->quantity) {
            return 'comprado';
        }

        if (in_array($this->item->request?->status, self::MUERTAS, true)) {
            return 'abierto';
        }

        return 'en_solicitud';
    }

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->estado()];
    }

    // ------------------------------------------------------------ consultas

    public function scopeDelAno(Builder $query, int $ano): Builder
    {
        return $query->where('target_year', $ano);
    }

    /** Los que nadie ha descartado. */
    public function scopeVivos(Builder $query): Builder
    {
        return $query->whereNull('discarded_at');
    }

    /**
     * Los deseos en un estado, en SQL.
     *
     * Las cuatro consultas viven juntas y al lado de `estado()` porque son la
     * misma regla dicha dos veces: si se separan, el filtro de la pantalla
     * acaba diciendo una cosa y el badge de la fila otra. Hay una prueba que
     * compara las dos lecturas registro a registro.
     */
    public function scopeEnEstado(Builder $query, string $estado): Builder
    {
        $recibidoEntero = fn (Builder $i) => $i->whereColumn('received_quantity', '>=', 'quantity');
        $viva = fn (Builder $s) => $s->whereNotIn('status', self::MUERTAS);

        return match ($estado) {
            'descartado' => $query->whereNotNull('discarded_at'),

            'comprado' => $query->vivos()->whereHas('item', $recibidoEntero),

            'en_solicitud' => $query->vivos()
                ->whereHas('item', fn (Builder $i) => $i
                    ->whereColumn('received_quantity', '<', 'quantity')
                    ->whereHas('request', $viva)),

            // Sin línea, o con una línea que ya no va a traer nada.
            'abierto' => $query->vivos()->where(fn (Builder $q) => $q
                ->whereNull('purchase_request_item_id')
                ->orWhereHas('item', fn (Builder $i) => $i
                    ->whereColumn('received_quantity', '<', 'quantity')
                    ->whereHas('request', fn (Builder $s) => $s->whereIn('status', self::MUERTAS)))),

            default => $query,
        };
    }

    /**
     * Lo que todavía hay que comprar: abierto y en solicitud juntos.
     *
     * Es lo que se presupuesta. Lo ya comprado no se vuelve a pedir y lo
     * descartado se decidió que no.
     */
    public function scopePorComprar(Builder $query): Builder
    {
        return $query->vivos()
            ->whereDoesntHave('item', fn (Builder $i) => $i->whereColumn('received_quantity', '>=', 'quantity'));
    }

    // ------------------------------------------------------- el año que viene

    /**
     * Lo que costaría la lista de un año, por área.
     *
     * Es la cifra con la que se va a conversar con la Universidad, así que se
     * enseña de las dos formas: el estimado a secas y con el impuesto del
     * laboratorio encima, porque compras trabaja con el valor con IVA y el
     * subtotal solo hace creer que alcanza para más de lo que alcanza.
     *
     * Los deseos **sin cotizar se cuentan aparte y no se esconden**: no entran
     * en la suma —no se sabe cuánto valen— pero se dicen, para que nadie tome
     * el total por completo cuando no lo está.
     *
     * @return array{anio:int, tasa:float, areas:list<array{area:?string, cuantos:int, estimado:int, sinEstimar:int}>, cuantos:int, estimado:int, conImpuesto:int, sinEstimar:int}
     */
    public static function resumenDelAno(int $ano): array
    {
        $deseos = static::query()->porComprar()->delAno($ano)->with('area')->get();

        $areas = $deseos
            ->groupBy(fn (self $d) => $d->area?->name ?? '')
            ->map(fn ($grupo, $nombre) => [
                'area'       => $nombre === '' ? null : $nombre,
                'cuantos'    => $grupo->count(),
                'estimado'   => (int) $grupo->sum(fn (self $d) => $d->estimado() ?? 0),
                'sinEstimar' => $grupo->filter(fn (self $d) => $d->estimado() === null)->count(),
            ])
            // Lo de «todo el laboratorio» al final: primero las áreas, que es
            // como se reparte la plata.
            ->sortBy(fn (array $fila) => [$fila['area'] === null ? 1 : 0, $fila['area']])
            ->values()
            ->all();

        $estimado = (int) $deseos->sum(fn (self $d) => $d->estimado() ?? 0);
        $tasa = (float) config('fabos.money.tax_rate');

        return [
            'anio'        => $ano,
            'tasa'        => $tasa,
            'areas'       => $areas,
            'cuantos'     => $deseos->count(),
            'estimado'    => $estimado,
            'conImpuesto' => (int) round($estimado * (1 + $tasa)),
            'sinEstimar'  => $deseos->filter(fn (self $d) => $d->estimado() === null)->count(),
        ];
    }

    /**
     * El año del que se habla cuando nadie ha dicho cuál.
     *
     * El que viene, porque para eso se hace la lista; pero si ya hay deseos
     * apuntados más allá —o solo para este año—, manda lo que haya, para no
     * abrir la pantalla en un año vacío teniendo cosas escritas al lado.
     */
    public static function anoPorDefecto(): int
    {
        $proximo = (int) now(config('fabos.lab.timezone'))->year + 1;

        if (static::query()->porComprar()->delAno($proximo)->exists()) {
            return $proximo;
        }

        return (int) (static::query()->porComprar()->max('target_year') ?? $proximo);
    }
}
