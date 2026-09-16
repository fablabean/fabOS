<?php

namespace App\Filament\Resources\Credenciales\Pages;

use App\Filament\Resources\Credenciales\CredencialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCredencial extends CreateRecord
{
    protected static string $resource = CredencialResource::class;

    /**
     * Quien la guarda es su dueño, salvo que diga otra cosa.
     *
     * Es el valor por defecto correcto: quien acaba de escribir la clave la
     * tiene de todos modos. Se puede cambiar en el formulario para cuando se
     * guarda en nombre de quien de verdad responde por ese servicio.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['owner_id'] = $data['owner_id'] ?? auth()->id();

        return $data;
    }
}
