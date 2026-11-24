<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Pages\Bandeja;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\Widgets\CargaDelEquipo;
use App\Filament\Widgets\SemanaDelLaboratorio;
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
            // Y en el calendario: la carga dice cuánto, la semana dice cuándo.
            SemanaDelLaboratorio::class,
        ];
    }

    private ?int $actividadesDeHoy = null;

    private function actividadesDeHoy(): int
    {
        return $this->actividadesDeHoy ??= Reservation::deHoy()
            ->whereNotIn('status', Reservation::NO_OCURREN)
            ->whereNull('parent_reservation_id')
            ->count();
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
                /*
                 * El número cuenta actividades que siguen en pie, como el
                 * calendario de la semana: sin canceladas ni rechazadas, y una
                 * sala con sus equipos es una sola. Contar filas daba 5 un día
                 * con una sola reserva viva y una sala cancelada con 3 equipos.
                 */
                ->label(fn () => 'Reservas de hoy' . (($n = $this->actividadesDeHoy()) ? ' (' . $n . ')' : ''))
                ->icon('heroicon-o-calendar-days')
                ->color(fn () => $this->actividadesDeHoy() ? 'primary' : 'gray')
                ->action(function () {
                    $this->resetTableFiltersForm();
                    $this->tableFilters['hoy']['isActive'] = true;
                    // Lo que va a pasar: las canceladas y rechazadas se ven
                    // quitando el filtro de estado.
                    $this->tableFilters['status']['values'] = array_values(array_diff(
                        array_keys(Reservation::ESTADOS), Reservation::NO_OCURREN,
                    ));
                    // Limpiar devuelve «Las mías», que viene por defecto; la
                    // pregunta es qué hay hoy en el laboratorio, de todos.
                    $this->tableFilters['mias']['isActive'] = false;
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
