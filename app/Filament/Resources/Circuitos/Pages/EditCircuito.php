<?php

namespace App\Filament\Resources\Circuitos\Pages;

use App\Filament\Resources\Circuitos\CircuitoResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCircuito extends EditRecord
{
    protected static string $resource = CircuitoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make("qr")
                ->label("Imprimir QR")
                ->icon("heroicon-o-qr-code")
                ->color("gray")
                ->url(fn () => route("recorridos.qr", $this->record))
                ->openUrlInNewTab(),
            // Con partidas jugadas no se borra: perdería sus tiempos.
            DeleteAction::make()->visible(fn () => ! $this->record->partidas()->exists()),
        ];
    }
}
