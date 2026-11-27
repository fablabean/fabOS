<?php

namespace App\Filament\Resources\Galeria3d\Pages;

use App\Filament\Resources\Galeria3d\Galeria3dResource;
use App\Support\Galeria3d;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditModelo3d extends EditRecord
{
    protected static string $resource = Galeria3dResource::class;

    public function getTitle(): string
    {
        return $this->record->nombre;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver')
                ->label('Ver en la galería')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn () => $this->record->publicado)
                ->url(fn () => route('galeria.show', $this->record->slug), shouldOpenInNewTab: true),

            Action::make('reemplazar')
                ->label('Reemplazar el archivo 3D')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->url(fn () => Galeria3dResource::getUrl('create', ['modelo' => $this->record->id])),

            DeleteAction::make()
                ->modalDescription('Se borran el modelo y sus archivos. Si estaba publicado, desaparece de la galería y su enlace deja de funcionar.'),
        ];
    }

    protected function afterSave(): void
    {
        Galeria3d::olvidar();
    }
}
