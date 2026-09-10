<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Pages\Bandeja;
use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Las solicitudes se deciden en su propia pantalla, y se entra
             * desde aqui: son reservas que todavia no se confirmaron, no otro
             * tema. Solo la ve quien puede decidirlas -aprobar una cuesta
             * horas extras de alguien-, que no es todo el que ve reservas.
             */
            Action::make('solicitudes')
                ->label(fn () => 'Solicitudes por decidir' . (Bandeja::pendientes() ? ' (' . Bandeja::pendientes() . ')' : ''))
                ->icon('heroicon-o-inbox-arrow-down')
                ->color(fn () => Bandeja::pendientes() ? 'warning' : 'gray')
                ->url(fn () => Bandeja::getUrl())
                ->visible(fn () => Bandeja::canAccess()),

            CreateAction::make(),
        ];
    }
}
