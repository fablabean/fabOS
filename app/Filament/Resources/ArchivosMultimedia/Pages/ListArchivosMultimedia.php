<?php

namespace App\Filament\Resources\ArchivosMultimedia\Pages;

use App\Filament\Resources\ArchivosMultimedia\ArchivoMultimediaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListArchivosMultimedia extends ListRecords
{
    protected static string $resource = ArchivoMultimediaResource::class;

    public function getSubheading(): ?string
    {
        return 'Archivos para usar dentro de las animaciones HTML de las páginas: se suben aquí y se copia su dirección. Un STL se carga, por ejemplo, con el STLLoader de three.js.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Subir archivo')->modalHeading('Subir a la carpeta multimedia')];
    }
}
