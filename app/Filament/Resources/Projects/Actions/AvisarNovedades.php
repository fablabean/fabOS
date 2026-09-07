<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * El botón «Avisar que hay novedades» (§11).
 *
 * Vive en dos sitios —la cabecera de la ficha y la de la conversación— y es
 * el mismo botón: quien acaba de responder en el hilo lo tiene al lado, y
 * quien cambió una fecha en la ficha también.
 *
 * El texto sale prellenado con lo último que dijo el laboratorio, porque casi
 * siempre es exactamente eso lo que se quiere contar. Se puede cambiar.
 */
class AvisarNovedades
{
    public static function make(): Action
    {
        return Action::make('avisarNovedades')
            ->label('Avisar que hay novedades')
            ->icon('heroicon-o-bell-alert')
            ->color('gray')
            ->visible(fn (Project $record) => ProjectResource::canEdit($record))
            ->modalHeading(fn (Project $record) => 'Avisar a quien pidió ' . $record->code)
            ->modalDescription(function (Project $record) {
                $correo = $record->correoDeLaPropuesta();
                $ultimo = app(ProjectService::class)->ultimoAvisoDeNovedades($record);

                return ($correo ? 'Le escribimos a ' . $correo : 'Este proyecto no tiene correo de contacto')
                    . '. El correo lleva un enlace para ver la propuesta y la conversación sin entrar.'
                    . ($ultimo?->sent_at
                        ? ' Último aviso: ' . $ultimo->sent_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') . '.'
                        : '');
            })
            ->modalSubmitActionLabel('Enviar aviso')
            ->schema([
                Textarea::make('mensaje')
                    ->label('Qué le cuentas')
                    ->rows(5)
                    ->maxLength(2000)
                    ->columnSpanFull()
                    ->default(fn (Project $record) => $record->comments()
                        ->where('side', 'laboratorio')
                        ->latest('id')
                        ->value('body'))
                    ->helperText('Va dentro del correo. Sale con lo último que respondió el laboratorio; cámbialo si quieres.'),
            ])
            ->action(function (Project $record, array $data) {
                try {
                    $aviso = app(ProjectService::class)->avisarNovedades($record, auth()->user(), $data['mensaje'] ?? null);
                } catch (ProjectException $e) {
                    Notification::make()->danger()->title('No se pudo avisar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title('Aviso enviado')
                    ->body('A ' . $aviso->to . '. Con el enlace para ver el proyecto y responder.')
                    ->send();
            });
    }
}
