<?php

namespace App\Filament\Resources\Assets\Widgets;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Area;
use Filament\Widgets\Widget;

/**
 * Las áreas con su foto, sobre el catálogo de equipos (§7).
 *
 * Ochenta y dos fichas de veinticinco en veinticinco: para llegar a las de
 * fresado hay que pasar páginas o acordarse de que existe un filtro en un
 * desplegable. Con las áreas delante, y con su foto, se elige de un vistazo —
 * «impresión 3D» se reconoce por la máquina, no por el rótulo—.
 *
 * Agrupar la tabla por área no bastaba: la rejilla agrupa lo que hay en la
 * PÁGINA, así que con las veinticinco primeras fichas solo salen tres o cuatro
 * áreas y el resto aparece al pasar página. Estas tarjetas están siempre
 * completas porque no dependen de la paginación.
 */
class AreasDelCatalogo extends Widget
{
    protected string $view = 'filament.activos.areas';

    /*
     * Sin pereza: es por donde se entra a la pantalla, y un hueco que se
     * rellena después se salta con la vista.
     */
    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    /**
     * @return list<array{nombre:string,foto:?string,cuantos:int,enlace:string}>
     */
    public function getAreas(): array
    {
        return Area::query()
            // Lo dado de baja no se cuenta: la tarjeta diria doce y la tabla
            // ensenaria nueve.
            ->withCount('assets')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->filter(fn (Area $a) => $a->assets_count > 0)
            ->map(fn (Area $a) => [
                'nombre'  => $a->name,
                'foto'    => $a->fotoUrl(),
                'cuantos' => $a->assets_count,
                'enlace'  => AssetResource::getUrl('index', [
                    'tableFilters' => ['area' => ['value' => $a->id]],
                ]),
            ])
            ->values()
            ->all();
    }

    /** Para volver al catálogo entero sin tener que buscar cómo se quita el filtro. */
    public function getEnlaceATodos(): string
    {
        return AssetResource::getUrl('index', ['tableFilters' => ['area' => ['value' => null]]]);
    }
}
