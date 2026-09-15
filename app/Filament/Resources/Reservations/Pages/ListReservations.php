<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Pages\Bandeja;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\Widgets\CargaDelEquipo;
use App\Models\Reservation;
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
             * «¿Que hay hoy?», de todo el mundo.
             *
             * Es lo primero que se pregunta al abrir el laboratorio por la
             * manana, y hasta ahora habia que ordenar por fecha y leer hasta
             * donde cambiaba el dia. El filtro existe en la tabla; esto es el
             * atajo, con el numero delante para no tener que entrar a contarlo.
             *
             * Limpia los demas filtros a proposito: la pregunta es que hay hoy
             * en el laboratorio, no que hay hoy de lo que estuviera mirando
             * antes. Quien venia filtrando por una persona se llevaria una
             * respuesta incompleta sin notarlo.
             */
            Action::make('hoy')
                ->label(fn () => 'Reservas de hoy' . (($n = Reservation::deHoy()->count()) ? ' (' . $n . ')' : ''))
                ->icon('heroicon-o-calendar-days')
                ->color(fn () => Reservation::deHoy()->exists() ? 'primary' : 'gray')
                ->action(function () {
                    $this->resetTableFiltersForm();
                    $this->tableFilters['hoy']['isActive'] = true;
                    $this->resetPage();
                }),

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
