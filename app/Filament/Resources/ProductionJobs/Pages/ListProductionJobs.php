<?php

namespace App\Filament\Resources\ProductionJobs\Pages;

use App\Filament\Resources\ProductionJobs\ProductionJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductionJobs extends ListRecords
{
    protected static string $resource = ProductionJobResource::class;

    public function getSubheading(): ?string
    {
        return 'Trabajos que hace el equipo por encargo. Se cotizan antes de producir: '
            . 'nadie gasta material sobre un «hágamelo y después vemos».';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo encargo')];
    }
}
