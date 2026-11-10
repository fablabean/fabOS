<?php

namespace App\Filament\Resources\Zonas\Pages;

use App\Filament\Resources\Zonas\ZonaResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditZona extends EditRecord
{
    protected static string $resource = ZonaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver')
                ->label('Ver la zona')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => $this->record->url())
                ->openUrlInNewTab(),
            DeleteAction::make()->modalDescription('Se borran también sus módulos.'),
        ];
    }
}
