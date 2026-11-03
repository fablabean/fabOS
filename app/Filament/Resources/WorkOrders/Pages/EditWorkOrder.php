<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    /** Los videos marcados «A la ficha» quedan en el equipo para la próxima vez. */
    protected function afterSave(): void
    {
        \App\Filament\Componentes\RecursosDeMantenimiento::llevarALaFicha($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
