<?php

namespace App\Services\Inventory;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Supply;

/**
 * Qué hay en cada ubicación, contando lo que cuelga (§7).
 *
 * En una gaveta caben dos cosas distintas y no se mezclan: **activos** —una
 * máquina, un multímetro, algo que se ficha y se reserva— e **insumos**, que
 * es material que se gasta. Sumarlos en un solo número diría «23» sin decir si
 * hay veintitrés aparatos o veintitrés carretes de filamento.
 *
 * Y el total sube por el árbol: un rack con dieciséis gavetas no tiene nada
 * asignado a él, lo tienen las gavetas. Enseñar «Rack: 0» al lado de una
 * gaveta con veinte hace que quien busca un multímetro abra el rack y lo crea
 * vacío.
 *
 * **Se calcula el árbol entero de una vez.** Preguntar por fila serían varias
 * consultas por ubicación, y son listas que se leen enteras.
 */
class ConteoPorUbicacion
{
    /** @var array<string,array{directos:array<int,int>,totales:array<int,int>}> */
    private array $memoria = [];

    /** @var array<int,int|null>|null  de quién cuelga cada ubicación */
    private ?array $madres = null;

    /** @var array<int,array<string,float>>|null  cuánto material, por unidad */
    private ?array $cantidadesPorUbicacion = null;

    public function activosAqui(int $ubicacionId): int
    {
        return $this->mapa(Asset::class)['directos'][$ubicacionId] ?? 0;
    }

    public function activosConLoQueCuelga(int $ubicacionId): int
    {
        return $this->mapa(Asset::class)['totales'][$ubicacionId] ?? 0;
    }

    public function insumosAqui(int $ubicacionId): int
    {
        return $this->mapa(Supply::class)['directos'][$ubicacionId] ?? 0;
    }

    public function insumosConLoQueCuelga(int $ubicacionId): int
    {
        return $this->mapa(Supply::class)['totales'][$ubicacionId] ?? 0;
    }

    /**
     * Cuánto material hay, POR UNIDAD, contando lo que cuelga.
     *
     * Nunca en un solo número: en el laboratorio conviven láminas, kilos,
     * metros y mililitros. Sumar «35 + 2» daría treinta y siete de nada.
     *
     * @return array<string,float>  unidad => cantidad
     */
    public function cantidadesConLoQueCuelga(int $ubicacionId): array
    {
        $cantidades = $this->cantidades();
        $totales = [];

        foreach ($cantidades as $ubicacion => $porUnidad) {
            // Sube desde cada ubicación con material hasta sus madres, y solo
            // se queda con lo que pasa por la que se pregunta.
            $nodo = (int) $ubicacion;
            $saltos = 0;

            while ($nodo && $saltos++ < 20) {
                if ($nodo === $ubicacionId) {
                    foreach ($porUnidad as $unidad => $cuanto) {
                        $totales[$unidad] = ($totales[$unidad] ?? 0) + $cuanto;
                    }

                    break;
                }

                $nodo = (int) ($this->madres()[$nodo] ?? 0);
            }
        }

        ksort($totales);

        return $totales;
    }

    /**
     * Cuánto hay en cada ubicación, por unidad. Una consulta para todo.
     *
     * @return array<int,array<string,float>>
     */
    private function cantidades(): array
    {
        if ($this->cantidadesPorUbicacion !== null) {
            return $this->cantidadesPorUbicacion;
        }

        $mapa = [];

        foreach (Supply::query()->whereNotNull('location_id')->get(['location_id', 'unit', 'stock']) as $insumo) {
            $unidad = trim((string) $insumo->unit) ?: 'unidad';
            $mapa[(int) $insumo->location_id][$unidad] =
                ($mapa[(int) $insumo->location_id][$unidad] ?? 0) + (float) $insumo->stock;
        }

        return $this->cantidadesPorUbicacion = $mapa;
    }

    /**
     * @param  class-string<Asset|Supply>  $modelo
     * @return array{directos:array<int,int>,totales:array<int,int>}
     */
    private function mapa(string $modelo): array
    {
        if (isset($this->memoria[$modelo])) {
            return $this->memoria[$modelo];
        }

        $madres = $this->madres();

        $directos = $modelo::query()
            ->whereNotNull('location_id')
            ->selectRaw('location_id, count(*) as cuantos')
            ->groupBy('location_id')
            ->pluck('cuantos', 'location_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $totales = [];

        /*
         * Cada cosa se suma a su ubicacion y a todas sus madres, subiendo.
         *
         * Con el mismo tope que el resto del arbol: un ciclo -un estante
         * dentro de si mismo- daria vueltas sumando lo mismo para siempre.
         */
        foreach ($directos as $ubicacionId => $cuantos) {
            $nodo = (int) $ubicacionId;
            $saltos = 0;

            while ($nodo && $saltos++ < 20) {
                $totales[$nodo] = ($totales[$nodo] ?? 0) + $cuantos;
                $nodo = (int) ($madres[$nodo] ?? 0);
            }
        }

        return $this->memoria[$modelo] = ['directos' => $directos, 'totales' => $totales];
    }

    /**
     * De quién cuelga cada ubicación. Una sola consulta para todo el árbol, y
     * en su propia gaveta: mezclarla con los conteos haría que el tipo de
     * `$memoria` dijera una cosa y guardara otra.
     *
     * @return array<int,int|null>
     */
    private function madres(): array
    {
        return $this->madres ??= Location::query()->pluck('parent_id', 'id')->all();
    }
}
