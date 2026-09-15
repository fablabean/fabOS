<?php

namespace App\Services\Purchasing;

use App\Models\Area;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la lista de deseos sabe hacer con lo que tiene apuntado (§13).
 *
 * Dos salidas, que son las dos razones por las que existe la lista: **armar un
 * carrito** con lo que sí cabe ahora, y **proponer el presupuesto** del año con
 * lo que costaría todo.
 *
 * Va aparte de `PurchasingService` a propósito. Esa clase documenta un camino
 * —carrito → enviada → aprobada → recibida— y pasar deseos no es un paso de ese
 * camino: es una de las formas en que un carrito **nace**, como `abrirCarrito`
 * o `llenarConLoQueFalta`. Meterlo dentro haría que el servicio de compras
 * supiera de deseos sin que un deseo sepa de compras.
 */
class ListaDeDeseos
{
    public function __construct(private PurchasingService $compras) {}

    /**
     * Arma un carrito con los deseos seleccionados.
     *
     * Nace en **borrador** y sin presupuesto: no compromete nada. Quien lo
     * recibe elige contra qué presupuesto va, corrige precios —el estimado de
     * un deseo es de planeación, no una cotización— y lo envía.
     *
     * Solo pasan los que están en la lista. Los que ya se pidieron se saltan en
     * silencio en vez de duplicarse: quien marca diez filas de un tirón no tiene
     * por qué revisar una por una cuál ya había ido.
     *
     * @param  Collection<int, Wish>  $deseos
     *
     * @throws PurchasingException si ninguno de los seleccionados se puede pedir
     */
    public function pasarACompra(Collection $deseos, User $quien): PurchaseRequest
    {
        $pasan = $deseos->filter(fn (Wish $deseo) => $deseo->estado() === 'abierto')->values();

        if ($pasan->isEmpty()) {
            throw new PurchasingException(
                'Ninguno de los deseos seleccionados está en la lista: o ya se pidieron, o alguien los descartó.'
            );
        }

        return DB::transaction(function () use ($pasan, $quien) {
            $anos = $pasan->pluck('target_year')->unique()->sort()->implode(' y ');

            $carrito = $this->compras->abrirCarrito(
                $quien,
                $this->presupuestoDelRubro($pasan),
                justificacion: 'De la lista de deseos ' . $anos,
            );

            // Si todos son de la misma área, el carrito es de esa área. Si
            // vienen mezclados no se inventa una: el área es de quien gasta.
            $areas = $pasan->pluck('area_id')->unique();

            if ($areas->count() === 1 && $areas->first() !== null) {
                $carrito->update(['area_id' => $areas->first()]);
            }

            foreach ($pasan as $deseo) {
                $linea = $this->compras->agregar(
                    $carrito,
                    $deseo->description,
                    (float) $deseo->quantity,
                    // Nulo cuando nadie lo cotizó: `agregar` cae al último costo
                    // del insumo, y si tampoco lo hay, a cero. Quien arme la
                    // solicitud lo escribe, que es cuando de verdad se averigua.
                    $deseo->unit_price !== null ? (float) $deseo->unit_price : null,
                    $deseo->supply,
                    $deseo->unit,
                    enlace: $deseo->reference_url,
                );

                $deseo->update(['purchase_request_item_id' => $linea->id]);
            }

            return $carrito->refresh();
        });
    }

    /**
     * Contra qué presupuesto nace el carrito.
     *
     * Si todos los deseos van al mismo rubro y hay un presupuesto **vigente de
     * este año** que se llama así, el carrito nace apuntando a él: es media
     * razón de que el deseo lleve rubro. Se elige el del año en curso y no el
     * del año del deseo, porque lo que se compra hoy se paga con la plata de
     * hoy.
     *
     * Con rubros mezclados no se adivina: quien arme la solicitud lo elige,
     * porque una compra solo puede ir contra un presupuesto y repartirla es una
     * decisión suya.
     *
     * @param  Collection<int, Wish>  $deseos
     */
    private function presupuestoDelRubro(Collection $deseos): ?Budget
    {
        $rubros = $deseos->pluck('budget_line')->filter()->unique();

        if ($rubros->count() !== 1) {
            return null;
        }

        return Budget::query()
            ->where('name', $rubros->first())
            ->where('status', 'vigente')
            ->where('kind', '!=', 'venta')
            ->where('year', now(config('fabos.lab.timezone'))->year)
            ->first();
    }

    /**
     * Crea el presupuesto del año con lo que cuesta la lista.
     *
     * Nace en **borrador**, y eso no es un detalle: lo que sale de aquí es una
     * propuesta para conversar con la Universidad, no plata asignada. Darlo por
     * vigente haría que el sistema enseñara como disponible un dinero que nadie
     * ha girado, que es exactamente lo que el módulo entero trata de evitar.
     *
     * Lleva escrito de dónde salió la cifra, igual que el ejecutado de arranque:
     * un monto sin explicación no se defiende ante nadie dentro de seis meses.
     */
    public function presupuestar(int $ano, ?Area $area, string $nombre, int $monto): Budget
    {
        $resumen = Wish::resumenDelAno($ano);

        return Budget::create([
            'name'     => $nombre,
            'kind'     => 'gasto',
            'year'     => $ano,
            'area_id'  => $area?->id,
            'amount'   => $monto,
            'status'   => 'borrador',
            'notes'    => $this->procedencia($resumen),
        ]);
    }

    /**
     * Crea un presupuesto por cada rubro elegido, de una vez.
     *
     * El presupuesto no se pide en una cifra: se pide repartido, que es como la
     * Universidad lo asigna y como después hay que ejecutarlo. Tecleando cinco
     * veces el mismo formulario se acaba con cinco nombres que no coinciden con
     * los del año pasado, y entonces no se pueden comparar los años.
     *
     * El de cada rubro se llama **como el rubro**: ese nombre es el puente entre
     * el deseo de este año y el presupuesto del siguiente, y cambiarlo lo rompe.
     *
     * Los rubros que no existan en la lista del año se saltan en silencio.
     *
     * @param  array<int, string|null>  $rubros  null es «lo que no tiene rubro»
     * @return Collection<int, Budget>
     */
    public function presupuestarPorRubro(int $ano, array $rubros, ?Area $area = null): Collection
    {
        $resumen = Wish::resumenDelAno($ano);

        return DB::transaction(function () use ($ano, $rubros, $area, $resumen) {
            $creados = collect();

            foreach ($resumen['rubros'] as $fila) {
                if (! in_array($fila['rubro'], $rubros, true)) {
                    continue;
                }

                $creados->push(Budget::create([
                    'name'    => $fila['rubro'] ?? 'Sin rubro ' . $ano,
                    'kind'    => 'gasto',
                    'year'    => $ano,
                    'area_id' => $area?->id,
                    'amount'  => $fila['conImpuesto'],
                    'status'  => 'borrador',
                    'notes'   => $this->procedenciaDelRubro($fila, $resumen),
                ]));
            }

            return $creados;
        });
    }

    /** La frase que explica la cifra, para que no haya que reconstruirla. */
    private function procedencia(array $resumen): string
    {
        $frase = sprintf(
            'Precargado desde la lista de deseos de %d: %d deseos por %s, más %d%% de impuesto.',
            $resumen['anio'],
            $resumen['cuantos'],
            $this->pesos($resumen['estimado']),
            round($resumen['tasa'] * 100),
        );

        if ($resumen['sinEstimar'] > 0) {
            $frase .= sprintf(
                ' Quedan %d deseos sin cotizar, que no están en esta cifra.',
                $resumen['sinEstimar'],
            );
        }

        return $frase;
    }

    /** Lo mismo, pero de un solo rubro: es la cifra que hay que defender. */
    private function procedenciaDelRubro(array $fila, array $resumen): string
    {
        $frase = sprintf(
            'Precargado desde la lista de deseos de %d, rubro «%s»: %d deseos por %s, más %d%% de impuesto.',
            $resumen['anio'],
            $fila['rubro'] ?? 'sin rubro',
            $fila['cuantos'],
            $this->pesos($fila['estimado']),
            round($resumen['tasa'] * 100),
        );

        if ($fila['sinEstimar'] > 0) {
            $frase .= sprintf(
                ' Quedan %d deseos sin cotizar, que no están en esta cifra.',
                $fila['sinEstimar'],
            );
        }

        return $frase;
    }

    private function pesos(int $monto): string
    {
        return config('fabos.money.symbol') . number_format($monto, 0, ',', '.');
    }
}
