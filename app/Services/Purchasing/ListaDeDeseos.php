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

    /** La frase que explica la cifra, para que no haya que reconstruirla. */
    private function procedencia(array $resumen): string
    {
        $pesos = fn (int $monto) => config('fabos.money.symbol') . number_format($monto, 0, ',', '.');

        $frase = sprintf(
            'Precargado desde la lista de deseos de %d: %d deseos por %s, más %d%% de impuesto.',
            $resumen['anio'],
            $resumen['cuantos'],
            $pesos($resumen['estimado']),
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
}
