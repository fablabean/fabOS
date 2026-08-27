<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Widgets\ResumenDelAno;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBudgets extends ListRecords
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * El resumen va arriba, no al final.
     *
     * Con seis presupuestos separados, la pregunta que se hace primero es
     * cuanto queda en total; tenerla que sacar sumando seis filas a mano es
     * justo la cuenta que sale mal cuando hay prisa.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ResumenDelAno::class,
        ];
    }
}
