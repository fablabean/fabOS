<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Pages\Bandeja;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\Widgets\CargaDelEquipo;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    /**
     * Encima de la tabla: como esta repartido el trabajo.
     *
     * La lista dice que reservas hay; no dice quien las atiende ni si alguien
     * va cargado. Saberlo obligaba a filtrar de a una persona, y por eso no se
     * miraba.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            CargaDelEquipo::class,
        ];
    }

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
