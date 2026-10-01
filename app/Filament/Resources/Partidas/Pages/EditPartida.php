<?php

namespace App\Filament\Resources\Partidas\Pages;

use App\Filament\Resources\Partidas\PartidaResource;
use App\Models\Recorrido\Partida;
use App\Services\Recorrido\Juego;
use App\Services\Recorrido\JuegoException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** @property Partida $record */
class EditPartida extends EditRecord
{
    protected static string $resource = PartidaResource::class;

    /**
     * Un equipo que se arma con la partida ya en curso entra a jugar al
     * guardar, con el reloj de todos: el que llegó tarde, llegó tarde.
     */
    protected function afterSave(): void
    {
        if (! $this->record->enCurso()) {
            return;
        }

        foreach ($this->record->equipos()->where('estado', 'esperando')->get() as $equipo) {
            app(Juego::class)->arrancarEquipo($this->record, $equipo);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('iniciar')
                ->label('Iniciar la partida')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->estado === 'preparada')
                ->requiresConfirmation()
                ->modalHeading('¿Iniciar la partida?')
                ->modalDescription('Arranca el reloj para todos los equipos a la vez y cada líder recibe su primera pista.')
                ->action(function (): void {
                    try {
                        app(Juego::class)->iniciar($this->record);
                    } catch (JuegoException $e) {
                        Notification::make()->danger()->title('No se pudo iniciar')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('¡A jugar!')->send();
                    $this->refreshFormData(['estado']);
                }),

            Action::make('terminar')
                ->label('Terminar')
                ->icon('heroicon-o-flag')
                ->color('danger')
                ->visible(fn () => $this->record->enCurso())
                ->requiresConfirmation()
                ->modalDescription('Detiene el reloj de los equipos que no han terminado. Sus tiempos quedan como están.')
                ->action(function (): void {
                    app(Juego::class)->terminar($this->record);
                    Notification::make()->success()->title('Partida terminada')->send();
                }),

            Action::make('equipos')
                ->label('Códigos de los equipos')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn () => route('recorridos.equipos', $this->record))
                ->openUrlInNewTab(),

            Action::make('tablero')
                ->label('Tablero')
                ->icon('heroicon-o-presentation-chart-bar')
                ->color('gray')
                ->url(fn () => $this->record->urlDelTablero())
                ->openUrlInNewTab(),

            DeleteAction::make()->visible(fn () => $this->record->estado !== 'en_curso'),
        ];
    }
}
