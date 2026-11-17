<?php

namespace App\Services\Ia\Inventario;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Space;
use App\Models\Supply;
use App\Models\SupplyCategory;

/**
 * Las listas cerradas del inventario: áreas, espacios, ubicaciones,
 * categorías y unidades.
 *
 * La IA solo puede proponer lo que ya existe en estas listas, por su número.
 * Inventarse un área nueva porque la foto «parece de electrónica» crearía una
 * categoría que nadie decidió; por eso el modelo elige de aquí, y el
 * ejecutor rechaza cualquier número que no esté.
 *
 * Las mismas listas alimentan los desplegables de la pantalla, para que lo que
 * se corrige a mano y lo que propone la IA hablen del mismo catálogo.
 */
class CatalogoDeInventario
{
    /** @return array<int,string> */
    public function areas(): array
    {
        return Area::query()->orderBy('position')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int,string> */
    public function espacios(): array
    {
        return Space::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @var array{rutas:array<int,string>,espacio:array<int,?int>,padres:array<int,?int>}|null */
    private ?array $arbol = null;

    /**
     * Cada ubicación con su camino completo: «Cuarto 3D General › Insumos
     * Armario Resina 3D › Rack 1 Resina › Repisa 2».
     *
     * La columna `path` existe pero nadie la llena, y una lista de veinte
     * «Repisa 1» no deja elegir. El camino se arma subiendo por los padres,
     * todo en memoria: son unas cien filas.
     *
     * @return array<int,string> id => camino, sin el nombre del espacio
     */
    public function rutas(): array
    {
        return $this->arbol()['rutas'];
    }

    /** @return array<int,string> id => «Lab. Impresión 3D Resina › … › Repisa 2» */
    public function ubicaciones(): array
    {
        $espacios = $this->espacios();
        $arbol = $this->arbol();

        return collect($arbol['rutas'])
            ->map(fn ($ruta, $id) => ($espacios[$arbol['espacio'][$id] ?? 0] ?? 'Sin espacio') . ' › ' . $ruta)
            ->sort()
            ->all();
    }

    /**
     * Las ubicaciones agrupadas por espacio, para los desplegables.
     *
     * @return array<string,array<int,string>> espacio => [id => camino]
     */
    public function ubicacionesPorEspacio(): array
    {
        $espacios = $this->espacios();
        $arbol = $this->arbol();

        return collect($arbol['rutas'])
            ->groupBy(fn ($ruta, $id) => $espacios[$arbol['espacio'][$id] ?? 0] ?? 'Sin espacio', preserveKeys: true)
            ->map(fn ($grupo) => $grupo->sort()->all())
            ->sortKeys()
            ->all();
    }

    /**
     * Los muebles que contienen a esta ubicación, del más cercano al más
     * lejano: de «Repisa 2» sube a «Rack 1 Resina», «Insumos Armario…»…
     *
     * @return list<int>
     */
    public function ancestros(int $ubicacionId): array
    {
        $padres = $this->arbol()['padres'];
        $lista = [];
        $actual = $padres[$ubicacionId] ?? null;

        while ($actual && count($lista) < 15) {
            $lista[] = $actual;
            $actual = $padres[$actual] ?? null;
        }

        return $lista;
    }

    /**
     * Si es un sitio de descarte: vencidos, dados de baja, dañados.
     *
     * Ahí está guardada media resina del laboratorio —la que se venció—, y
     * por eso es donde más hay. Pero nada nuevo se manda a ese estante.
     */
    public function esDeDescarte(int $ubicacionId): bool
    {
        return (bool) preg_match('/vencid|caducad|\bbaja\b|descart|dañad|danad|averiad|desecho/u', mb_strtolower($this->rutas()[$ubicacionId] ?? ''));
    }

    /** El espacio al que pertenece una ubicación, subiendo por sus padres. */
    public function espacioDe(?int $ubicacionId): ?int
    {
        return $ubicacionId ? ($this->arbol()['espacio'][$ubicacionId] ?? null) : null;
    }

    /** @return array{rutas:array<int,string>,espacio:array<int,?int>,padres:array<int,?int>} */
    private function arbol(): array
    {
        if ($this->arbol !== null) {
            return $this->arbol;
        }

        $todas = Location::query()->get(['id', 'parent_id', 'name', 'space_id'])->keyBy('id');
        $rutas = [];
        $espacio = [];

        foreach ($todas as $id => $l) {
            $partes = [];
            $nodo = $l;
            $suEspacio = null;
            $vueltas = 0;

            // El tope evita colgarse si alguien dejó un ciclo en el árbol.
            while ($nodo && $vueltas++ < 15) {
                array_unshift($partes, trim((string) $nodo->name));
                $suEspacio ??= $nodo->space_id;
                $nodo = $nodo->parent_id ? ($todas[$nodo->parent_id] ?? null) : null;
            }

            $rutas[$id] = implode(' › ', $partes);
            $espacio[$id] = $suEspacio;
        }

        return $this->arbol = ['rutas' => $rutas, 'espacio' => $espacio, 'padres' => $todas->map(fn ($l) => $l->parent_id)->all()];
    }

    /** @return array<int,string> id => «Material › Madera» */
    public function categorias(): array
    {
        $todas = SupplyCategory::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id']);
        $nombres = $todas->pluck('name', 'id');

        return $todas
            ->mapWithKeys(fn ($c) => [$c->id => trim(($c->parent_id ? trim((string) $nombres[$c->parent_id]) . ' › ' : '') . trim((string) $c->name))])
            ->sort()
            ->all();
    }

    /**
     * Las unidades que ya se usan, para no estrenar «unid.» al lado de «unidad».
     *
     * @return list<string>
     */
    public function unidades(): array
    {
        return Supply::query()->whereNotNull('unit')->distinct()->orderBy('unit')->pluck('unit')
            ->merge(['unidad', 'caja', 'paquete', 'g', 'kg', 'ml', 'l', 'm', 'hoja'])
            ->map(fn ($u) => trim((string) $u))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Todo junto, en texto, para las instrucciones del modelo.
     *
     * Ordenado y sin fechas a propósito: mientras el catálogo no cambie, el
     * texto es idéntico de una consulta a la siguiente y la API lo sirve de
     * su caché, que es más rápido y más barato.
     */
    public function texto(): string
    {
        $lista = function (array $items): string {
            if ($items === []) {
                return "  (no hay ninguno registrado)\n";
            }

            $texto = '';

            foreach ($items as $id => $nombre) {
                $texto .= "  {$id} · {$nombre}\n";
            }

            return $texto;
        };

        $ubicaciones = collect($this->ubicaciones())
            ->map(fn ($ruta, $id) => $ruta . (($e = $this->espacioDe($id)) ? ' (espacio ' . $e . ')' : ''))
            ->all();

        return "ÁREAS (area_id)\n" . $lista($this->areas())
            . "\nESPACIOS — la sala donde se usa (espacio_id)\n" . $lista($this->espacios())
            . "\nUBICACIONES — el mueble donde se guarda (ubicacion_id)\n" . $lista($ubicaciones)
            . "\nCATEGORÍAS DE INSUMO (categoria_id)\n" . $lista($this->categorias())
            . "\nTIPOS DE ACTIVO: " . implode(', ', array_keys(Asset::TIPOS))
            . "\nESTADOS DE ACTIVO: " . implode(', ', array_keys(Asset::ESTADOS))
            . "\nUNIDADES EN USO PARA INSUMOS: " . implode(', ', $this->unidades()) . "\n";
    }
}
