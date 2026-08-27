<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\EmbudoDeProyectos;
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

    /**
     * El embudo va arriba.
     *
     * La lista dice que proyectos hay; no dice donde estan atascados. Con las
     * etapas repartidas en una columna, ver que hay cuatro propuestas sin
     * respuesta y una sola cosa en ejecucion obliga a filtrar seis veces, y
     * por eso nadie lo hace.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            EmbudoDeProyectos::class,
        ];
    }
}
