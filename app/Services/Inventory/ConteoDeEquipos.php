<?php

namespace App\Services\Inventory;

use App\Models\Asset;
use App\Models\Location;

/**
 * Cuántos equipos hay en cada ubicación, contando lo que cuelga (§7).
 *
 * Un rack con dieciséis gavetas no tiene ningún equipo asignado a él: los
 * tienen las gavetas. Enseñar «Rack: 0» al lado de una gaveta con veinte hace
 * que quien busca un multímetro abra el rack y crea que está vacío.
 *
 * El total sube por el árbol: cada equipo se cuenta en su gaveta, en el
 * estante de esa gaveta y en el armario de ese estante.
 *
 * **Se calcula el árbol entero de una vez.** Preguntar por fila serían dos
 * consultas por ubicación, y son listas que se leen enteras.
 */
class ConteoDeEquipos
{
    /** @var array{directos:array<int,int>,totales:array<int,int>}|null */
    private ?array $memoria = null;

    /** Los que están asignados a esa ubicación y a nada más abajo. */
    public function directos(int $ubicacionId): int
    {
        return $this->mapa()['directos'][$ubicacionId] ?? 0;
    }

    /** Los de ahí y los de todo lo que cuelgue, a cualquier profundidad. */
    public function conLoQueCuelga(int $ubicacionId): int
    {
        return $this->mapa()['totales'][$ubicacionId] ?? 0;
    }

    /** @return array{directos:array<int,int>,totales:array<int,int>} */
    private function mapa(): array
    {
        if ($this->memoria !== null) {
            return $this->memoria;
        }

        // Dos consultas para todo el arbol: de quien cuelga cada ubicacion, y
        // cuantos equipos tiene asignados cada una.
        $madres = Location::query()->pluck('parent_id', 'id')->all();

        $directos = Asset::query()
            ->whereNotNull('location_id')
            ->selectRaw('location_id, count(*) as cuantos')
            ->groupBy('location_id')
            ->pluck('cuantos', 'location_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $totales = [];

        /*
         * Cada equipo se suma a su ubicacion y a todas sus madres, subiendo.
         *
         * Con el mismo tope que el resto del arbol: un ciclo -un estante
         * dentro de si mismo- daria vueltas para siempre sumando lo mismo.
         */
        foreach ($directos as $ubicacionId => $cuantos) {
            $nodo = (int) $ubicacionId;
            $saltos = 0;

            while ($nodo && $saltos++ < 20) {
                $totales[$nodo] = ($totales[$nodo] ?? 0) + $cuantos;
                $nodo = (int) ($madres[$nodo] ?? 0);
            }
        }

        return $this->memoria = ['directos' => $directos, 'totales' => $totales];
    }
}
