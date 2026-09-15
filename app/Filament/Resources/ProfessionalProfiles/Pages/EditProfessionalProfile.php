<?php

namespace App\Filament\Resources\ProfessionalProfiles\Pages;

use App\Filament\Resources\ProfessionalProfiles\ProfessionalProfileResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProfessionalProfile extends EditRecord
{
    protected static string $resource = ProfessionalProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('hoja')
                ->label('Ver la hoja')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn () => route('perfiles.hoja', $this->record))
                ->openUrlInNewTab(),

            DeleteAction::make()
                ->modalDescription('Se va con sus documentos: los archivos se borran del servidor. No se puede deshacer.'),
        ];
    }
}
