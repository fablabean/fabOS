<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditAsset extends EditRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // La vía corta a una orden, con el equipo ya elegido. Es la
            // única forma de mandarlo a mantenimiento desde el panel.
            Action::make('orden')
                ->label('Crear orden de trabajo')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->url(fn () => WorkOrderResource::getUrl('create', ['equipo' => $this->record->getKey()]))
                ->visible(fn () => WorkOrderResource::canCreate()),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
