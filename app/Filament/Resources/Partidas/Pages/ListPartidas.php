<?php

namespace App\Filament\Resources\Partidas\Pages;

use App\Filament\Resources\Partidas\PartidaResource;
use App\Models\Recorrido\Partida;
use App\Services\Recorrido\InformeDeParticipantes;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartidas extends ListRecords
{
    protected static string $resource = PartidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Todos los que han jugado, de todas las partidas: la lista para escribirles.
            Action::make('participantes')
                ->label('Informe de participantes')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()->streamDownload(
                    fn () => print(app(InformeDeParticipantes::class)->csv(Partida::with('circuito')->orderBy('created_at')->get())),
                    'participantes-recorridos-' . now()->format('Y-m-d') . '.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            CreateAction::make(),
        ];
    }
}
