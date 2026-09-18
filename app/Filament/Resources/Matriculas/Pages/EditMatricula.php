<?php

namespace App\Filament\Resources\Matriculas\Pages;

use App\Filament\Resources\Matriculas\MatriculaResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMatricula extends EditRecord
{
    protected static string $resource = MatriculaResource::class;

    /**
     * Cambiar el programa desde aquí cambia la categoría de la persona, que es
     * lo que decide su tarifa y su bienvenida: la matrícula y la persona no
     * pueden decir cosas distintas.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        if ($record->wasChanged('user_category_id') && $record->vigente()) {
            $record->user?->forceFill(['user_category_id' => $record->user_category_id, 'category_confirmed' => true])->save();
        }

        return $record;
    }
}
