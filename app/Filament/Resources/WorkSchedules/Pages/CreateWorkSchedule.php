<?php

namespace App\Filament\Resources\WorkSchedules\Pages;

use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use App\Models\WorkSchedule;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateWorkSchedule extends CreateRecord
{
    protected static string $resource = WorkScheduleResource::class;

    /** Días que ya tenían jornada solapada y no se volvieron a crear. */
    private array $omitidos = [];

    /**
     * Una jornada por cada día marcado.
     *
     * El formulario recoge varios días porque un horario casi siempre se repite
     * de lunes a viernes; la tabla guarda una fila por día porque cada día puede
     * cambiar. Aquí se traduce lo uno en lo otro.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $dias = collect($data['weekdays'] ?? [])->map(fn ($d) => (int) $d)->sort()->values();
        unset($data['weekdays']);

        $creadas = collect();

        DB::transaction(function () use ($dias, $data, &$creadas) {
            foreach ($dias as $dia) {
                // Dos jornadas del mismo día con vigencias que se pisan no son
                // un duplicado inofensivo: las horas se cuentan dos veces y la
                // cobertura del laboratorio sale mal sin que nada lo avise.
                if ($this->yaHayJornada($data, $dia)) {
                    $this->omitidos[] = WorkSchedule::DIAS[$dia];

                    continue;
                }

                $creadas->push(WorkSchedule::create($data + ['weekday' => $dia]));
            }
        });

        if ($this->omitidos !== []) {
            Notification::make()
                ->title('Algunos días ya tenían jornada')
                ->body('No se tocaron: ' . implode(', ', $this->omitidos)
                    . '. Si querías cambiarlas, edítalas o cierra la vigencia de la anterior.')
                ->warning()
                ->persistent()
                ->send();
        }

        if ($creadas->isEmpty()) {
            // Devolver algo hay que devolver, y Filament redirige al registro.
            // Sin ninguna creada, se vuelve a la primera existente para que la
            // persona vea lo que ya hay en vez de una pantalla vacía.
            return WorkSchedule::where('user_id', $data['user_id'])
                ->where('weekday', $dias->first())
                ->latest('id')
                ->firstOrFail();
        }

        if ($creadas->count() > 1) {
            Notification::make()
                ->title($creadas->count() . ' jornadas creadas')
                ->success()
                ->send();
        }

        return $creadas->first();
    }

    private function yaHayJornada(array $data, int $dia): bool
    {
        $desde = $data['effective_from'];
        $hasta = $data['effective_until'] ?? null;

        return WorkSchedule::query()
            ->where('user_id', $data['user_id'])
            ->where('weekday', $dia)
            // Se solapan si cada una empieza antes de que acabe la otra.
            ->where(fn ($q) => $q->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('effective_from', '<=', $hasta))
            ->exists();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
