<?php

namespace App\Filament\Resources\Credenciales\Actions;

use App\Models\Credencial;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Sacar un secreto a la pantalla, dejando constancia (§5, §19).
 *
 * Tres cosas que no son adorno:
 *
 *  · **Queda escrito quien lo miro y cuando.** Una boveda sin registro de
 *    lecturas no es una boveda: es un tablon de contraseñas con una puerta.
 *    El dia que haya que preguntar «¿quien tenia esta clave?» ya no se puede
 *    empezar a registrar.
 *  · **Se pregunta a la politica, no al rol.** La regla —su dueño, y el
 *    superadmin todas— vive en un solo sitio; si se copiara aqui, acabaria
 *    habiendo una lista que enseña de mas y un boton que dice que no.
 *  · **Pide confirmacion.** No para proteger de nada tecnico: para que mirar
 *    una clave sea un gesto deliberado y no un clic de paso. Lo que se anota
 *    en el registro tiene que significar algo.
 */
class RevelarElSecreto
{
    public static function make(string $nombre = 'revelar'): Action
    {
        return Action::make($nombre)
            ->label('Ver la clave')
            ->icon('heroicon-o-key')
            ->color('danger')
            ->visible(fn (Credencial $record) => $record->tieneSecreto()
                && (auth()->user()?->can('revelar', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (Credencial $record) => $record->nombre)
            ->modalDescription('Queda registrado que la miraste tú y cuándo. Ciérrala cuando termines.')
            ->modalSubmitActionLabel('Verla')
            ->modalContent(fn (Credencial $record) => $record->lecturas()->exists()
                ? view('filament.credenciales.antes-de-ver', ['credencial' => $record])
                : null)
            /*
             * El secreto viaja en el aviso y no en una pantalla propia.
             *
             * Una pagina con la clave tiene direccion, y una direccion se deja
             * abierta, se comparte y se queda en el historial del navegador.
             * El aviso se cierra y no deja rastro donde no debe.
             */
            ->action(function (Credencial $record) {
                $secreto = $record->revelarPara(auth()->user(), request()->ip());

                Notification::make()
                    ->title($record->usuario ? $record->usuario : $record->nombre)
                    ->body($secreto)
                    ->persistent()
                    ->warning()
                    ->send();
            });
    }
}
