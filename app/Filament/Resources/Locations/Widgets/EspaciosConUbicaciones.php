<?php

namespace App\Filament\Resources\Locations\Widgets;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\Space;
use Filament\Widgets\Widget;

/**
 * Los espacios con sus muebles, sobre la lista de ubicaciones (§7).
 *
 * **Por espacio y no por área, a propósito.** Un mueble no pertenece a un
 * área: está en una sala. Agrupar por área dejaría fuera todo lo que está en
 * salas sin área asignada —en el laboratorio real, un cuarto de las
 * ubicaciones— y esa parte acabaría en un cajón que nadie mira.
 *
 * La cuenta incluye lo que cuelga: un estante con dieciséis gavetas son
 * diecisiete muebles en esa sala, no uno.
 */
class EspaciosConUbicaciones extends Widget
{
    protected string $view = 'filament.ubicaciones.espacios';

    /*
     * Sin pereza: es por donde se entra a la pantalla, y un hueco que se
     * rellena después se salta con la vista.
     */
    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    /**
     * @return list<array{nombre:string,cuantas:int,enlace:string}>
     */
    public function getEspacios(): array
    {
        /*
         * Se resuelve en memoria y no con un `group by`: el espacio de una
         * ubicación no es una columna suya, lo hereda de su raíz. Son unas
         * decenas de muebles; preguntarlo por cada uno es barato y correcto,
         * que es mejor orden que al revés.
         */
        $porEspacio = Location::with('parent')
            ->get()
            ->groupBy(fn (Location $u) => $u->espacio()?->id ?? 0)
            ->map->count();

        return Space::query()
            ->whereIn('id', $porEspacio->keys()->filter()->all())
            ->orderBy('name')
            ->get()
            ->map(fn (Space $e) => [
                'nombre'  => $e->name,
                'cuantas' => (int) $porEspacio->get($e->id, 0),
                // `filters`, que es como `ListRecords` publica esa propiedad
                // en la URL. Con `tableFilters` el enlace no filtra nada.
                'enlace'  => LocationResource::getUrl('index', [
                    'filters' => ['espacio' => ['value' => $e->id]],
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * Cuántos muebles no están en ninguna sala.
     *
     * Se enseña aunque sea cero... no: solo cuando los hay. Es un dato que
     * pide arreglo —un mueble sin sala no se encuentra yendo a buscarlo— y
     * enseñar un cero todos los días enseña a no mirarlo.
     */
    public function getHuerfanas(): int
    {
        return Location::with('parent')
            ->get()
            ->filter(fn (Location $u) => $u->espacio() === null)
            ->count();
    }

    public function getEnlaceATodas(): string
    {
        return LocationResource::getUrl('index', ['filters' => ['espacio' => ['value' => null]]]);
    }
}
