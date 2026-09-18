<?php

namespace App\Filament\Resources\Matriculas\Pages;

use App\Filament\Resources\Matriculas\MatriculaResource;
use App\Models\UserCategory;
use App\Services\Personas\MatriculaException;
use App\Services\Personas\MatriculaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMatricula extends CreateRecord
{
    protected static string $resource = MatriculaResource::class;

    /**
     * Por el servicio, no creando la fila a pelo: es el que crea la cuenta
     * si no existe, le pone la subcategoría y deja la bienvenida abonada.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $matricula = app(MatriculaService::class)->matricular(
                $data,
                UserCategory::findOrFail($data['user_category_id']),
                auth()->user(),
            );
        } catch (MatriculaException $e) {
            Notification::make()->title('No se pudo matricular')->body($e->getMessage())->danger()->persistent()->send();

            throw ValidationException::withMessages(['data.user_category_id' => $e->getMessage()]);
        }

        return $matricula;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Matriculado. Ya puede entrar con su correo.';
    }
}
