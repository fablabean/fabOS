<?php

namespace App\Filament\Resources\Actas\Pages;

use App\Filament\Resources\Actas\ActaResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditActa extends EditRecord
{
    protected static string $resource = ActaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('formato')
                ->label('Ver el formato')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn () => route('actas.ver', $this->record))
                ->openUrlInNewTab(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return ActaResource::getUrl('index');
    }
}
