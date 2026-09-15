<?php

namespace App\Filament\Resources\Paginas\Pages;

use App\Filament\Resources\Paginas\PaginaResource;
use App\Models\Pagina;
use Filament\Resources\Pages\CreateRecord;

class CreatePagina extends CreateRecord
{
    protected static string $resource = PaginaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Quien la escribio. No es auditoria -de eso ya hay-: es a quien se le
        // pregunta cuando la pagina dice algo que hay que corregir.
        $data['created_by'] ??= auth()->id();

        // Red por si el slug llego vacio o repetido pese a la validacion: dos
        // personas creando a la vez pasan la comprobacion de unicidad y chocan
        // al guardar. Antes que un error 500, una direccion numerada.
        $data['slug'] = Pagina::slugLibre($data['slug'] ?: $data['titulo']);

        return $data;
    }
}
