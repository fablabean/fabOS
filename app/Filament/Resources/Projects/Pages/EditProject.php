<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\AvisarNovedades;
use App\Filament\Resources\Projects\Actions\PaginaDelProyecto;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AvisarNovedades::make(),

            // El mismo boton que en la lista: quien acaba de cerrar el
            // proyecto en esta pantalla es quien quiere contarlo.
            PaginaDelProyecto::make(),

            DeleteAction::make(),
        ];
    }
}
