<?php

namespace App\Filament\Resources\CandidateBatches\Pages;

use App\Filament\Resources\CandidateBatches\CandidateBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCandidateBatch extends EditRecord
{
    protected static string $resource = CandidateBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Se lleva sus candidatos; los proyectos que ya salieron de aqui
            // se quedan, que ya viven solos.
            DeleteAction::make()
                ->modalDescription('Se van sus candidatos y lo que se anotó al evaluarlos. Los proyectos que ya salieron de este lote se quedan.'),
        ];
    }
}
