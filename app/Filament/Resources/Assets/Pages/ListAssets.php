<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Widgets\AreasDelCatalogo;
use App\Models\Asset;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    /**
     * Las areas con su foto, encima del catalogo.
     *
     * Ochenta y dos fichas de veinticinco en veinticinco: llegar a las de
     * fresado era pasar paginas o acordarse de un filtro escondido en un
     * desplegable.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AreasDelCatalogo::class,
        ];
    }

    /**
     * Pestañas por tipo, con cuántos hay de cada uno.
     *
     * Activos fijos, herramientas y computadores se administran distinto —los
     * fijos llevan plan preventivo; las herramientas y los computadores se
     * prestan—, y saber cuántos hay de cada uno era contar a mano.
     */
    public function getTabs(): array
    {
        $cuantos = Asset::query()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        $pestanas = ['todos' => Tab::make('Todos')->badge($cuantos->sum())];

        foreach (Asset::TIPOS as $tipo => $nombre) {
            $pestanas[$tipo] = Tab::make(match ($tipo) {
                'fijo'        => 'Activos fijos',
                'herramienta' => 'Herramientas',
                'computador'  => 'Computadores',
                default       => $nombre,
            })
                ->badge($cuantos[$tipo] ?? 0)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', $tipo));
        }

        return $pestanas;
    }

    /**
     * Ordenar por una columna quita la agrupación por área.
     *
     * Agrupada, la tabla ordena dentro de cada área: pedir «por estado» o
     * «por número» daba once listas ordenadas por separado, y no la que se
     * pidió. Al quitar el orden vuelven las áreas.
     */
    public function getTableGrouping(): ?\Filament\Tables\Grouping\Group
    {
        if (filled($this->getTableSortColumn())) {
            return null;
        }

        return parent::getTableGrouping();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
