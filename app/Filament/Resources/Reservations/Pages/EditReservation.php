<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\Booking\EliminarReserva;
use Filament\Actions\DeleteAction;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\TraspasoDeAtencion;
use Filament\Notifications\Notification;
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

    /**
     * Si cambió quién atiende una asesoría, se pasa como en «Reasignar»:
     * después de guardar lo demás, para que la ficha no pise su motivo.
     */
    protected function afterSave(): void
    {
        $nuevo = $this->data['atiende'] ?? null;

        if (! $this->record->esAtencionPersonal() || ! $nuevo || (int) $nuevo === (int) $this->record->reservable_id) {
            return;
        }

        try {
            app(TraspasoDeAtencion::class)->reasignar($this->record, User::findOrFail($nuevo), auth()->user());
        } catch (BookingException $e) {
            $this->data['atiende'] = $this->record->reservable_id;
            Notification::make()->danger()->persistent()
                ->title('No se pudo cambiar quién atiende')
                ->body($e->getMessage() . ' Lo demás sí quedó guardado.')
                ->send();

            return;
        }

        $this->record->refresh();
        Notification::make()->success()->title('Ahora la atiende ' . User::find($nuevo)?->name)->send();
    }
}
