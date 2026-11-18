<?php

namespace App\Filament\Resources\Pantallas\Pages;

use App\Filament\Resources\Pantallas\PantallaResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPantalla extends EditRecord
{
    protected static string $resource = PantallaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clave')
                ->label(fn () => $this->record->clave_hash ? 'Generar otra clave' : 'Generar la clave')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Clave de la pantalla')
                ->modalDescription(fn () => $this->record->clave_hash
                    ? 'La clave actual deja de servir: la pantalla que la tenga se queda sin actualizar hasta que le pongas la nueva en su página de configuración.'
                    : 'Se muestra una sola vez. Cópiala en la página de configuración del display (su wifi), junto con la dirección del servidor.')
                ->action(function (): void {
                    Notification::make()
                        ->success()
                        ->title('Clave generada: cópiala ahora')
                        ->body($this->record->generarClave())
                        ->persistent()
                        ->send();
                }),
            Action::make('pagina')
                ->label('Página del salón (QR)')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn () => $this->record->urlPublica())
                ->openUrlInNewTab(),
            DeleteAction::make(),
        ];
    }
}
