<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Anuncio;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El parlante del laboratorio: lanza los anuncios a su hora (§3).
 *
 * El servidor no tiene parlantes, y un navegador solo suena mientras tiene
 * una pestaña abierta. Así que lo que suena «solo» suena aquí: esta pantalla
 * se deja abierta en el equipo conectado a los parlantes, y ella lleva el
 * reloj.
 *
 * Tres decisiones que no son detalles:
 *
 *  · **Hay que encenderla con un clic.** Los navegadores no dejan sonar una
 *    página que nadie ha tocado. Mejor un botón que lo diga que un anuncio de
 *    cierre que no suena y nadie sabe por qué.
 *  · **Lo que se pasó, no suena tarde.** Si el equipo estuvo dormido o sin
 *    red, el «cerramos en diez minutos» no se dice a las 18:20. Hay un minuto
 *    y medio de gracia, y lo demás se da por perdido.
 *  · **Cada cosa suena una vez**, aunque se recargue la pantalla: el
 *    navegador recuerda lo que ya lanzó.
 */
class Parlante extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.parlante';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Parlante';
    }

    public function getTitle(): string
    {
        return 'Parlante';
    }

    /**
     * Desde hace dos minutos hasta mañana a esta hora.
     *
     * Los dos minutos de atrás cubren la gracia de lo que acaba de pasar; el
     * día por delante deja ver lo que viene y aguanta una noche sin refrescar.
     * La pantalla se refresca cada pocos minutos, así que un horario nuevo o
     * un audio recién generado entran solos.
     */
    public function eventos(): array
    {
        return Anuncio::agenda(now()->subMinutes(2), now()->addDay());
    }

    /** La lista de YouTube que suena de fondo, si hay una puesta. */
    public function musica(): ?array
    {
        return \App\Support\MusicaDelParlante::paraElReproductor();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('musica')
                ->label('Música')
                ->icon('heroicon-o-musical-note')
                ->color('gray')
                ->modalHeading('Música de fondo')
                ->modalDescription('Una lista de YouTube que suena en esta pantalla mientras está encendida. Se pausa sola cuando habla un anuncio y vuelve al terminar.')
                ->fillForm(fn () => [
                    'url'     => \App\Support\MusicaDelParlante::url(),
                    'volumen' => \App\Support\MusicaDelParlante::volumen(),
                ])
                ->schema([
                    \Filament\Forms\Components\TextInput::make('url')
                        ->label('Enlace de la lista de YouTube')
                        ->placeholder('https://www.youtube.com/playlist?list=PL…')
                        ->maxLength(500)
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! \App\Support\MusicaDelParlante::leer($value)) {
                                $fail('Ese enlace no es de una lista ni de un video de YouTube.');
                            }
                        })
                        ->helperText('Sirve el de YouTube o el de YouTube Music: «Compartir» → «Copiar vínculo». La lista tiene que ser pública o no listada. Vacío: sin música.'),
                    \Filament\Forms\Components\TextInput::make('volumen')
                        ->label('Volumen de la música')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(\App\Support\MusicaDelParlante::VOLUMEN_POR_DEFECTO)
                        ->required()
                        ->helperText('Más bajo que los anuncios: es de fondo. 40 % suele ir bien.'),
                ])
                ->action(function (array $data) {
                    \App\Support\MusicaDelParlante::guardar($data['url'] ?? null, (int) $data['volumen']);

                    \Filament\Notifications\Notification::make()
                        ->title('Música guardada')
                        ->body('Recarga esta pantalla en el equipo de los parlantes para que la tome.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
