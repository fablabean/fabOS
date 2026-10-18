<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\Booking\EliminarReserva;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    /** Con su número, el mismo de la lista: «la reserva 789». */
    public function getTitle(): string
    {
        return 'Reserva #' . $this->record->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Por el mismo servicio que la lista: devuelve lo comprometido y
            // limpia los archivos. Tres botones, una sola forma de borrar.
            DeleteAction::make()
                ->action(function (Reservation $record, DeleteAction $action) {
                    app(EliminarReserva::class)($record, auth()->user());
                    $action->success();
                })
                ->successRedirectUrl(ReservationResource::getUrl()),
        ];
    }
}
