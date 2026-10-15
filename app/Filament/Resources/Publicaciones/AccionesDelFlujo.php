<?php

namespace App\Filament\Resources\Publicaciones;

use App\Models\Publicacion;
use App\Services\Redes\FlujoDePublicacion;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Los pasos de una publicación como botones (§21).
 *
 * Los mismos en la lista y en la ficha. Cada uno aparece solo cuando toca, y
 * si falta algo para darlo, lo dice en vez de fallar callado.
 */
class AccionesDelFlujo
{
    /** @return list<Action> */
    public static function todas(): array
    {
        return [
            self::paso('a_diseno', 'Pasar a diseño', 'heroicon-o-paint-brush', 'info',
                fn (Publicacion $p) => $p->estado === 'idea',
                fn (Publicacion $p) => self::flujo()->aDiseno($p)),

            self::paso('a_aprobacion', 'Enviar a aprobación', 'heroicon-o-paper-airplane', 'warning',
                fn (Publicacion $p) => in_array($p->estado, ['idea', 'en_diseno'], true),
                fn (Publicacion $p) => self::flujo()->aAprobacion($p)),

            self::paso('aprobar', 'Aprobar', 'heroicon-o-check-badge', 'success',
                fn (Publicacion $p) => $p->estado === 'por_aprobar' && FlujoDePublicacion::puedeAprobar(auth()->user()),
                fn (Publicacion $p) => self::flujo()->aprobar($p, auth()->user()))
                ->requiresConfirmation()
                ->modalDescription('Queda anotado quién la aprobó y cuándo. Si después cambian el texto o la pieza, vuelve a aprobación.'),

            self::paso('devolver', 'Devolver', 'heroicon-o-arrow-uturn-left', 'danger',
                fn (Publicacion $p) => $p->estado === 'por_aprobar' && FlujoDePublicacion::puedeAprobar(auth()->user()),
                fn (Publicacion $p, array $data) => self::flujo()->devolver($p, auth()->user(), (string) ($data['motivo'] ?? '')))
                ->schema([
                    Textarea::make('motivo')
                        ->label('Qué hay que cambiar')
                        ->required()
                        ->rows(3),
                ]),

            self::paso('programar', 'Marcar programada', 'heroicon-o-clock', 'primary',
                fn (Publicacion $p) => $p->estado === 'aprobada',
                fn (Publicacion $p, array $data) => self::flujo()->programar(
                    tap($p)->forceFill(['programada_para' => $data['programada_para'] ?? $p->programada_para]),
                ))
                ->fillForm(fn (Publicacion $p) => ['programada_para' => $p->programada_para])
                ->schema([
                    DateTimePicker::make('programada_para')
                        ->label('Sale el')
                        ->seconds(false)
                        ->timezone(config('fabos.lab.timezone'))
                        ->required()
                        ->helperText('Ya quedó programada en la red o en Meta Business Suite.'),
                ]),

            self::paso('publicar', 'Marcar publicada', 'heroicon-o-check-circle', 'success',
                fn (Publicacion $p) => in_array($p->estado, ['aprobada', 'programada'], true),
                fn (Publicacion $p, array $data) => self::flujo()->publicar($p, (string) ($data['enlace'] ?? '')))
                ->schema([
                    TextInput::make('enlace')
                        ->label('Enlace de la publicación')
                        ->url()
                        ->required()
                        ->placeholder('https://www.instagram.com/p/…')
                        ->helperText('Es la evidencia de que salió.'),
                ]),

            self::paso('descartar', 'Descartar', 'heroicon-o-archive-box-x-mark', 'gray',
                fn (Publicacion $p) => ! in_array($p->estado, Publicacion::CERRADOS, true),
                fn (Publicacion $p) => self::flujo()->descartar($p))
                ->requiresConfirmation(),

            self::paso('recuperar', 'Recuperar', 'heroicon-o-arrow-path', 'gray',
                fn (Publicacion $p) => $p->estado === 'descartada',
                fn (Publicacion $p) => self::flujo()->recuperar($p)),
        ];
    }

    private static function paso(string $nombre, string $rotulo, string $icono, string $color, \Closure $visible, \Closure $hacer): Action
    {
        return Action::make($nombre)
            ->label($rotulo)
            ->icon($icono)
            ->color($color)
            ->visible(fn (Publicacion $record) => $visible($record) && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (Publicacion $record, array $data = []) use ($hacer, $rotulo) {
                try {
                    $p = $hacer($record, $data);
                } catch (\DomainException $e) {
                    Notification::make()->title('Todavía no')->body($e->getMessage())->warning()->send();

                    return;
                }

                Notification::make()->title('Ahora está ' . mb_strtolower($p->nombreDelEstado()))->success()->send();
            });
    }

    private static function flujo(): FlujoDePublicacion
    {
        return app(FlujoDePublicacion::class);
    }
}
