<?php

namespace App\Filament\Resources\Supplies\Pages;

use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\Supply;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSupplies extends ListRecords
{
    protected static string $resource = SupplyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Pestañas, porque aquí conviven dos cosas distintas (§13, §14).
     *
     * En la misma lista están el **alcohol isopropílico** —que se consume
     * fabricando y solo le importa al laboratorio— y la **Alcancía Dona**, que
     * es un producto que se le vende a alguien. Salían mezclados y ordenados
     * por nombre, así que reponer insumos y revisar el catálogo eran la misma
     * pantalla revuelta.
     *
     * Son preguntas distintas y cada una tiene su pestaña:
     *
     *  · **Para fabricar** — lo que se gasta. Es la lista de reposición.
     *  · **Para vender** — lo que el laboratorio produce y despacha.
     *  · **En la tienda** — lo que de verdad está publicado, que no es lo
     *    mismo que ser un producto: un producto sin publicar no lo ve nadie, y
     *    esa diferencia no se notaba en ninguna parte.
     *  · **Bajo mínimos** — con lo que se abre esta pantalla la mitad de las
     *    veces. Era un filtro escondido en el desplegable; escondido no se usa.
     *
     * Con contador, que es lo que convierte una pestaña en información: «hay
     * seis bajo mínimos» se lee sin entrar.
     */
    public function getTabs(): array
    {
        $cuenta = fn (?callable $filtro = null) => Supply::query()
            ->where('is_active', true)
            ->when($filtro, $filtro)
            ->count();

        /*
         * Escrito con `$query` y su tipo, como los demas.
         *
         * Filament resuelve los argumentos de estos cierres por nombre o por
         * tipo. Compartir un cierre entre el contador y `modifyQueryUsing`
         * hacia que llegara nulo y la pestaña reventaba al abrirse; es la
         * misma trampa que ya esta anotada en los filtros de esta tabla.
         */
        $bajoMinimos = fn (Builder $query) => $query
            ->whereNotNull('reorder_point')
            ->whereColumn('stock', '<=', 'reorder_point');

        return [
            'todos' => Tab::make('Todos')
                ->badge($cuenta()),

            'insumos' => Tab::make('Para fabricar')
                ->badge($cuenta(fn (Builder $q) => $q->where('kind', 'insumo')))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', 'insumo')),

            'productos' => Tab::make('Para vender')
                ->badge($cuenta(fn (Builder $q) => $q->where('kind', 'producto')))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', 'producto')),

            /*
             * Publicado no es lo mismo que ser un producto.
             *
             * Un producto sin publicar no lo ve nadie, y hasta ahora esa
             * diferencia no se notaba en ninguna pantalla: habia que abrir la
             * ficha de uno en uno para saberlo.
             */
            'tienda' => Tab::make('En la tienda')
                ->badge($cuenta(fn (Builder $q) => $q->where('is_public', true)))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_public', true)),

            'encargo' => Tab::make('Por encargo')
                ->badge($cuenta(fn (Builder $q) => $q->where('por_encargo', true)))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('por_encargo', true)),

            // En rojo cuando hay algo: es lo unico de esta pantalla que pide
            // que alguien haga algo hoy.
            'minimos' => Tab::make('Bajo mínimos')
                ->badge($cuenta($bajoMinimos))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNotNull('reorder_point')
                    ->whereColumn('stock', '<=', 'reorder_point')),
        ];
    }
}
