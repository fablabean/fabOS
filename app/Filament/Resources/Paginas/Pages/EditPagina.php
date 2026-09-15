<?php

namespace App\Filament\Resources\Paginas\Pages;

use App\Filament\Resources\Paginas\PaginaResource;
use App\Models\Pagina;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPagina extends EditRecord
{
    protected static string $resource = PaginaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Lo que se edita aqui se ve alli. La pagina es su propia vista
            // previa: quien edita la ve aunque siga apagada.
            Action::make('ver')
                ->label(fn (Pagina $record) => $record->estaVisible() ? 'Ver la página' : 'Ver el borrador')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (Pagina $record) => $record->enlace(), shouldOpenInNewTab: true),

            DeleteAction::make(),
        ];
    }
}
