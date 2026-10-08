<?php

namespace App\Filament\Resources\Dispositivos\Pages;

use App\Filament\Resources\Dispositivos\DispositivoResource;
use App\Models\Iot\Dispositivo;
use App\Services\Iot\Turnos;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** @property Dispositivo $record */
class EditDispositivo extends EditRecord
{
    protected static string $resource = DispositivoResource::class;

    /** La clave recién generada, para enseñarla una vez. */
    public ?string $claveNueva = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clave')
                ->label($this->record->clave_hash ? 'Generar otra clave' : 'Generar la clave')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Clave de la Raspberry')
                ->modalDescription($this->record->clave_hash
                    ? 'La clave actual deja de servir: la Raspberry que la tenga se queda sin conexión hasta que le pongas la nueva.'
                    : 'Se muestra una sola vez. Cópiala a la configuración de la Raspberry.')
                ->action(function (): void {
                    $this->claveNueva = $this->record->generarClave();

                    Notification::make()
                        ->success()
                        ->title('Clave generada: cópiala ahora')
                        ->body($this->claveNueva)
                        ->persistent()
                        ->send();
                }),

            // Para probar sin la Raspberry: el portal hace como si estuviera
            // conectada. Nada se enciende de verdad.
            Action::make('desarrollo')
                ->label(fn () => $this->record->modo_desarrollo ? 'Salir del modo desarrollo' : 'Activar modo desarrollo')
                ->icon('heroicon-o-beaker')
                ->color(fn () => $this->record->modo_desarrollo ? 'warning' : 'gray')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->modo_desarrollo ? '¿Salir del modo desarrollo?' : '¿Activar el modo desarrollo?')
                ->modalDescription(fn () => $this->record->modo_desarrollo
                    ? 'Vuelve a exigir que la Raspberry esté conectada para poder jugar.'
                    : 'El portal deja registrarse, hacer fila y usar FabCoins aunque no haya Raspberry conectada. Sirve para probar; la consola no se enciende de verdad si no hay aparato. Los registros y los FabCoins que se den sí son reales.')
                ->action(function (): void {
                    $this->record->update(['modo_desarrollo' => ! $this->record->modo_desarrollo]);

                    Notification::make()
                        ->success()
                        ->title($this->record->modo_desarrollo ? 'Modo desarrollo activado' : 'Modo desarrollo apagado')
                        ->send();
                }),

            Action::make('encender')
                ->label('Encender')
                ->icon('heroicon-o-play')
                ->color('success')
                ->schema([
                    TextInput::make('minutos')->numeric()->minValue(1)->maxValue(480)->default(15)->required(),
                ])
                ->modalDescription('Entra a la fila como cualquier turno: si hay alguien jugando, empieza cuando termine.')
                ->action(function (array $data): void {
                    app(Turnos::class)->encenderAMano($this->record, (int) $data['minutos'], auth()->user());
                    Notification::make()->success()->title('En la fila')->send();
                }),

            Action::make('apagar')
                ->label('Apagar y vaciar la fila')
                ->icon('heroicon-o-stop')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Corta el turno en curso y cancela los que esperan. A quien pagó con FabCoins no se le devuelven solos.')
                ->action(function (): void {
                    $cuantos = app(Turnos::class)->apagar($this->record);
                    Notification::make()->success()->title('Apagado')->body($cuantos . ' turno(s) cancelado(s).')->send();
                }),

            DeleteAction::make(),
        ];
    }
}
