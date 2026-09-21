<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Models\ScheduleException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateScheduleException extends CreateRecord
{
    protected static string $resource = ScheduleExceptionResource::class;

    /**
     * Un bloqueo por cada dia marcado.
     *
     * La clase es martes y jueves a la misma hora, y el formulario lo recoge
     * de una vez; la tabla guarda una fila por dia, igual que las jornadas,
     * porque cada uno puede cambiar o borrarse por su cuenta despues. Sin
     * dias marcados —o de dias enteros— es un solo registro, como siempre.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $dias = collect($this->form->getRawState()['weekdays'] ?? [])
            ->map(fn ($d) => (int) $d)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        unset($data['weekdays']);

        // Los proyectos van en cada fila: con dias repetidos son varias, y
        // todas son la misma tarde de proyecto.
        $proyectos = ($data['kind'] ?? null) === 'proyecto'
            ? array_map('intval', (array) ($this->form->getRawState()['projects'] ?? []))
            : [];
        unset($data['projects']);

        if (! filled($data['starts_time'] ?? null) || $dias->isEmpty()) {
            return tap(ScheduleException::create($data + ['weekday' => null]), fn ($e) => $e->projects()->sync($proyectos));
        }

        $creados = DB::transaction(fn () => $dias->map(
            fn (int $dia) => tap(ScheduleException::create($data + ['weekday' => $dia]), fn ($e) => $e->projects()->sync($proyectos)),
        ));

        if ($creados->count() > 1) {
            Notification::make()
                ->title($creados->count() . ' bloqueos creados')
                ->body('Uno por cada día marcado, con la misma franja.')
                ->success()
                ->send();
        }

        return $creados->first();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
