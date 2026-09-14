<?php

namespace App\Filament\Resources\Wishes\Pages;

use App\Filament\Resources\Wishes\WishResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWish extends CreateRecord
{
    protected static string $resource = WishResource::class;

    public function getSubheading(): ?string
    {
        return 'Apunta lo que hace falta. No compromete nada: lo que se pide y lo que se paga se decide después.';
    }

    /** Quién lo apuntó no se escribe a mano. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requested_by'] = auth()->id();

        return $data;
    }
}
