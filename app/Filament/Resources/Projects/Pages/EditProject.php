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

            // Convertir en alianza, con texto: en la lista es un icono entre
            // muchos y nadie lo encontraba.
            \Filament\Actions\Action::make('alianza')
                ->label('Convertir en alianza')
                ->icon('heroicon-o-users')
                ->color('info')
                ->visible(fn () => ! $this->getRecord()->esAlianza() && ! $this->getRecord()->estaCerrado())
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Convertir ' . $this->getRecord()->code . ' en alianza')
                ->modalDescription(fn () => 'Deja de ser un encargo con un cliente y pasa a ser un proyecto con partes que aportan. '
                    . $this->getRecord()->quienPide() . ' queda como quien trajo la idea, y ' . config('fabos.lab.name') . ' como parte. '
                    . 'Los aportes y la participación se anotan en la pestaña «Aliados». No se puede deshacer desde aquí.')
                ->modalSubmitActionLabel('Convertir')
                ->action(function () {
                    try {
                        app(\App\Services\Projects\Alianzas::class)->convertir($this->getRecord(), auth()->user());
                    } catch (\App\Services\Projects\ProjectException $e) {
                        \Filament\Notifications\Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    \Filament\Notifications\Notification::make()->title('Ahora es una alianza')->body('Suma las partes en la pestaña «Aliados», aquí abajo.')->success()->send();

                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
                }),

            // El mismo boton que en la lista: quien acaba de cerrar el
            // proyecto en esta pantalla es quien quiere contarlo.
            PaginaDelProyecto::make(),

            DeleteAction::make(),
        ];
    }
}
