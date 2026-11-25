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

            /*
             * Abrir la presentación al público, y volverla a cerrar.
             *
             * Para el día de la auditoría: el auditor la abre desde su equipo
             * sin cuenta. Pasada la visita se apaga y vuelve a ser del equipo.
             * Los enlaces a las evidencias siguen pidiendo sesión.
             */
            Action::make('publica')
                ->label(fn () => \App\Http\Controllers\CalidadController::presentacionPublica() ? 'Pública: sí' : 'Pública: no')
                ->icon(fn () => \App\Http\Controllers\CalidadController::presentacionPublica() ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color(fn () => \App\Http\Controllers\CalidadController::presentacionPublica() ? 'success' : 'gray')
                ->tooltip('Si la presentación INV-200 se ve sin iniciar sesión')
                ->visible(fn () => auth()->user()?->hasAnyRole([\App\Models\User::ROL_ADMINISTRADOR, \App\Models\User::ROL_SUPERADMIN]) ?? false)
                ->requiresConfirmation()
                ->modalHeading(fn () => \App\Http\Controllers\CalidadController::presentacionPublica()
                    ? 'Cerrar la presentación al público'
                    : 'Abrir la presentación al público')
                ->modalDescription(fn () => \App\Http\Controllers\CalidadController::presentacionPublica()
                    ? 'Volverá a pedir iniciar sesión.'
                    : 'Cualquiera con el enlace ' . route('calidad.caracterizacion') . ' la verá sin iniciar sesión, con las cifras y las auditorías registradas. Los enlaces a las evidencias del panel siguen pidiendo sesión.')
                ->action(function () {
                    $abrir = ! \App\Http\Controllers\CalidadController::presentacionPublica();
                    \App\Models\Setting::put(\App\Http\Controllers\CalidadController::PRESENTACION_PUBLICA, $abrir, 'calidad');

                    \Filament\Notifications\Notification::make()->success()
                        ->title($abrir ? 'La presentación es pública' : 'La presentación vuelve a ser del equipo')
                        ->send();
                }),

            CreateAction::make()->label('Registrar auditoría'),
        ];
    }
}
