<?php

namespace App\Filament\Resources\Software\Pages;

use App\Filament\Resources\Software\SoftwareResource;
use App\Models\Software;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditSoftware extends EditRecord
{
    protected static string $resource = SoftwareResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Renovar es un boton y no editar la fecha a mano.
             *
             * Es el gesto que se hace una y otra vez, y a mano se equivoca:
             * hay que acordarse del ciclo y contar meses. Aqui se empuja desde
             * la fecha que habia -no desde hoy-, que es como funcionan las
             * suscripciones: renovar tarde no corre el aniversario.
             */
            Action::make('renovar')
                ->label('Renovar')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (Software $record) => $record->renueva_el
                    && in_array($record->ciclo, ['mensual', 'anual'], true)
                    && ! $record->estaDeBaja())
                ->requiresConfirmation()
                ->modalHeading('Renovar la licencia')
                ->modalDescription(fn (Software $record) => 'La fecha pasa a '
                    .self::siguiente($record)->translatedFormat('j \d\e F \d\e Y')
                    .'. Se cuenta desde la fecha que tenía, no desde hoy: renovar tarde no corre el aniversario.')
                ->modalSubmitActionLabel('Renovada')
                ->action(function (Software $record) {
                    $record->update(['renueva_el' => self::siguiente($record)]);

                    Notification::make()->success()
                        ->title('Renovada')
                        ->body('Vuelve a avisar '.Software::AVISO_DIAS.' días antes de la nueva fecha.')
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    private static function siguiente(Software $software): Carbon
    {
        return $software->ciclo === 'mensual'
            ? $software->renueva_el->copy()->addMonth()
            : $software->renueva_el->copy()->addYear();
    }
}
