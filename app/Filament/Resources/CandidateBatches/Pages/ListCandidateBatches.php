<?php

namespace App\Filament\Resources\CandidateBatches\Pages;

use App\Filament\Resources\CandidateBatches\CandidateBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCandidateBatches extends ListRecords
{
    protected static string $resource = CandidateBatchResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'A veces no llega un proyecto: llega una lista. Entra entera, se evalúa dentro, '
            . 'y lo aceptado se convierte en proyecto sin volver a teclear nada.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo lote')];
    }
}
