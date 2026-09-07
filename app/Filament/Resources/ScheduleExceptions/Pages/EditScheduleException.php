<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Models\ScheduleException;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditScheduleException extends EditRecord
{
    protected static string $resource = ScheduleExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Este bloqueo se queda con su dia; los demas marcados se crean.
     *
     * Editar «cada martes» y marcar tambien el jueves no convierte una fila
     * en dos: este registro sigue siendo el del martes —si sigue marcado— y
     * el jueves nace aparte, con la misma franja y las mismas fechas. Si un
     * dia ya tiene un bloqueo identico, no se duplica. Sin dias marcados, la
     * franja pasa a valer solo en esas fechas.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $dias = collect($this->form->getRawState()['weekdays'] ?? [])
            ->map(fn ($d) => (int) $d)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        unset($data['weekdays']);

        if (! filled($data['starts_time'] ?? null) || $dias->isEmpty()) {
            $record->update($data + ['weekday' => null]);

            return $record;
        }

        $propio = $dias->contains((int) $record->weekday) ? (int) $record->weekday : $dias->first();
        $nuevos = 0;

        DB::transaction(function () use ($record, $data, $dias, $propio, &$nuevos) {
            $record->update($data + ['weekday' => $propio]);

            foreach ($dias->reject(fn (int $d) => $d === $propio) as $dia) {
                if ($this->yaExiste($record, $dia)) {
                    continue;
                }

                ScheduleException::create($data + ['weekday' => $dia]);
                $nuevos++;
            }
        });

        if ($nuevos > 0) {
            Notification::make()
                ->title($nuevos . ($nuevos === 1 ? ' bloqueo más creado' : ' bloqueos más creados'))
                ->body('Con la misma franja y las mismas fechas que este.')
                ->success()
                ->send();
        }

        return $record;
    }

    /** Un bloqueo igual a este, para ese dia: misma persona, franja, fechas y motivo. */
    private function yaExiste(ScheduleException $como, int $dia): bool
    {
        $como->refresh();

        return ScheduleException::query()
            ->whereKeyNot($como->id)
            ->where('weekday', $dia)
            ->where('kind', $como->kind)
            ->where('starts_time', $como->starts_time)
            ->where('ends_time', $como->ends_time)
            ->whereDate('starts_on', $como->starts_on->toDateString())
            ->when(
                $como->ends_on,
                fn ($q) => $q->whereDate('ends_on', $como->ends_on->toDateString()),
                fn ($q) => $q->whereNull('ends_on'),
            )
            ->when(
                $como->user_id,
                fn ($q) => $q->where('user_id', $como->user_id),
                fn ($q) => $q->whereNull('user_id'),
            )
            ->exists();
    }
}
