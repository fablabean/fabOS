<?php

namespace App\Services\Shop;

use App\Models\ServiceOffering;
use App\Models\Supply;
use App\Services\Money\PricingService;
use Illuminate\Support\Collection;

/**
 * El carrito, en la sesión (§14).
 *
 * Vive en la sesión y no en la base a propósito: **se puede llenar sin tener
 * cuenta**. Alguien entra desde el celular, mira qué hay, junta tres cosas y
 * solo entonces decide si entra o pide una cotización. Obligar a identificarse
 * para poder mirar precios es la forma más rápida de que no mire.
 *
 * El precio se calcula al mostrarlo, no al guardarlo: un carrito abandonado
 * tres semanas no puede prometer el precio de hace tres semanas. Lo que se
 * congela es la venta, cuando se cobra —de eso se encarga `ShopService`—.
 */
class Carrito
{
    private const CLAVE = 'carrito';

    public function __construct(private PricingService $precios) {}

    /**
     * @return array<int,array{tipo:string,id:int,cantidad:float}>
     */
    public function crudo(): array
    {
        return session(self::CLAVE, []);
    }

    public function agregar(string $tipo, int $id, float $cantidad = 1): void
    {
        if (! in_array($tipo, ['insumo', 'servicio'], true) || $cantidad <= 0) {
            return;
        }

        $lineas = $this->crudo();
        $clave = $tipo . ':' . $id;

        $lineas[$clave] = [
            'tipo'     => $tipo,
            'id'       => $id,
            // Se suma a lo que hubiera: volver a pulsar «añadir» sobre algo que
            // ya está dentro significa querer más, no querer lo mismo.
            'cantidad' => round(($lineas[$clave]['cantidad'] ?? 0) + $cantidad, 3),
        ];

        session([self::CLAVE => $lineas]);
    }

    public function fijar(string $tipo, int $id, float $cantidad): void
    {
        $lineas = $this->crudo();
        $clave = $tipo . ':' . $id;

        if ($cantidad <= 0) {
            unset($lineas[$clave]);
        } else {
            $lineas[$clave] = ['tipo' => $tipo, 'id' => $id, 'cantidad' => round($cantidad, 3)];
        }

        session([self::CLAVE => $lineas]);
    }

    public function quitar(string $tipo, int $id): void
    {
        $lineas = $this->crudo();
        unset($lineas[$tipo . ':' . $id]);

        session([self::CLAVE => $lineas]);
    }

    public function vaciar(): void
    {
        session()->forget(self::CLAVE);
    }

    public function estaVacio(): bool
    {
        return $this->lineas()->isEmpty();
    }

    public function cuantos(): int
    {
        return $this->lineas()->count();
    }

    /**
     * Las líneas con su cosa y su precio de hoy.
     *
     * Lo que ya no existe, o dejó de venderse, desaparece del carrito en vez de
     * reventar la página: un carrito que no se puede ni abrir para vaciarlo es
     * peor que uno con una línea de menos.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function lineas(): Collection
    {
        // Los escalones vienen de una vez: preguntarlos linea por linea son
        // tantas consultas como cosas lleve el carrito.
        $insumos = Supply::enLaTienda()
            ->whereIn('id', collect($this->crudo())->where('tipo', 'insumo')->pluck('id'))
            ->with('priceBreaks')
            ->get()
            ->keyBy('id');

        $servicios = ServiceOffering::enLaTienda()
            ->whereIn('id', collect($this->crudo())->where('tipo', 'servicio')->pluck('id'))
            ->with('priceBreaks')
            ->get()
            ->keyBy('id');

        return collect($this->crudo())
            ->map(function (array $linea) use ($insumos, $servicios) {
                // El precio se pregunta CON la cantidad: si no, el carrito
                // enseñaria el precio de una sola y el descuento por veinte
                // solo aparecerian al cobrar, que es cuando ya nadie lo cree.
                $cantidad = (float) $linea['cantidad'];

                if ($linea['tipo'] === 'insumo') {
                    $cosa = $insumos[$linea['id']] ?? null;
                    $precio = $cosa ? $this->precios->precioDe($cosa, $cantidad) : 0;
                } else {
                    $cosa = $servicios[$linea['id']] ?? null;
                    $precio = $cosa ? $this->precios->precioDeServicio($cosa, $cantidad) : 0;
                }

                if (! $cosa || $precio <= 0) {
                    return null;
                }

                return [
                    'tipo'     => $linea['tipo'],
                    'id'       => $linea['id'],
                    'cosa'     => $cosa,
                    'nombre'   => $cosa->name,
                    'unidad'   => $cosa->unit,
                    'cantidad' => $cantidad,
                    'precio'   => $precio,
                    'total'    => (int) round($precio * $linea['cantidad']),
                ];
            })
            ->filter()
            ->values();
    }

    public function totalMenor(): int
    {
        return (int) $this->lineas()->sum('total');
    }

    /** Lo que no tiene existencia suficiente hoy, para decirlo antes de cobrar. */
    public function sinExistencia(): Collection
    {
        return $this->lineas()
            ->filter(fn (array $l) => $l['tipo'] === 'insumo'
                && (float) $l['cosa']->stock < $l['cantidad'])
            ->values();
    }
}
