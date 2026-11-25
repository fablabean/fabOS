<?php

namespace App\Filament\Resources\Auditorias\Pages;

use App\Filament\Resources\Auditorias\AuditoriaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAuditorias extends ListRecords
{
    protected static string $resource = AuditoriaResource::class;

    public function getSubheading(): ?string
    {
        return 'Las auditorías de calidad del laboratorio, internas y externas: cuándo fueron, qué resultó y en qué van las mejoras.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('presentacion')
                ->label('Presentación INV-200')
                ->icon('heroicon-o-presentation-chart-bar')
                ->color('gray')
                ->url(fn () => route('calidad.caracterizacion'))
                ->openUrlInNewTab(),

            CreateAction::make()->label('Registrar auditoría'),
        ];
    }
}
