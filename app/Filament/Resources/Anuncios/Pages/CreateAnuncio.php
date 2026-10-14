<?php

namespace App\Filament\Resources\Anuncios\Pages;

use App\Filament\Resources\Anuncios\AnuncioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnuncio extends CreateRecord
{
    protected static string $resource = AnuncioResource::class;

    protected function getRedirectUrl(): string
    {
        return AnuncioResource::getUrl();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Anuncio guardado. El audio se está generando y sale en la portada cuando esté listo.';
    }
}
