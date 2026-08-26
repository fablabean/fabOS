<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // El cronograma de todos a la vez. Es la vista que decide si se
            // acepta el siguiente encargo: por separado todos parecen holgados.
            Action::make('cronograma')
                ->label('Cronograma general')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(fn () => route('proyectos.cronograma'))
                ->openUrlInNewTab(),

            CreateAction::make(),
        ];
    }
}
