<?php

namespace App\Filament\Resources\Actas\Pages;

use App\Filament\Resources\Actas\ActaResource;
use App\Support\ConfiguracionDeActas;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListActas extends ListRecords
{
    protected static string $resource = ActaResource::class;

    public function getSubheading(): ?string
    {
        return 'El formato FRF-207-f7 de Planta Física, llenado con el inventario. Se prepara, lo firman quien entrega y quien recibe, y si el activo sale de la Universidad se envía a Planta Física.';
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * A quién se le envían y quién las autoriza. Aquí y no en un
             * archivo: el día que cambie la persona de Planta Física no
             * debería hacer falta desplegar nada.
             */
            Action::make('configuracion')
                ->label('Configuración')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasAnyRole([\App\Models\User::ROL_ADMINISTRADOR, \App\Models\User::ROL_SUPERADMIN]) ?? false)
                ->modalHeading('Configuración de las actas')
                ->modalDescription('A dónde se envían las que salen de la Universidad, quién las autoriza, y lo que va por defecto en las casillas.')
                ->fillForm(fn () => ConfiguracionDeActas::todo())
                ->schema([
                    TextInput::make('correo_planta')
                        ->label('Correo de Planta Física')
                        ->email()
                        ->required()
                        ->helperText('A este correo va el formato firmado cuando un activo sale de la Universidad.'),
                    TextInput::make('autoriza_nombre')->label('Autoriza (nombre)')->required()->maxLength(160),
                    TextInput::make('autoriza_cargo')->label('Autoriza (cargo)')->required()->maxLength(160),
                    TextInput::make('proceso')->label('Proceso por defecto')->maxLength(160),
                    TextInput::make('sede')->label('Sede por defecto')->maxLength(120),
                ])
                ->action(function (array $data) {
                    ConfiguracionDeActas::guardar($data);

                    Notification::make()->title('Configuración guardada')->success()->send();
                }),

            CreateAction::make()->label('Preparar un acta'),
        ];
    }
}
