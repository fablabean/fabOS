<?php

namespace App\Filament\Resources\Publicaciones\Pages;

use App\Filament\Resources\Publicaciones\PublicacionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePublicacion extends CreateRecord
{
    protected static string $resource = PublicacionResource::class;

    /** Una fecha que llega desde un día de la parrilla. */
    protected function afterFill(): void
    {
        if ($dia = request()->query('dia')) {
            try {
                $this->data['programada_para'] = \Carbon\CarbonImmutable::parse($dia . ' 10:00', config('fabos.lab.timezone'))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // Una fecha mal escrita en la dirección no impide crearla.
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['creada_por'] = auth()->id();
        $data['estado'] = 'idea';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return PublicacionResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
