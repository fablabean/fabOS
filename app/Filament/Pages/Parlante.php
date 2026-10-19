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

    /** Lo que suena en Spotify y lo que viene, con su hora. */
    public function estadoDeLaRocola(): ?array
    {
        return app(\App\Services\Musica\Rocola::class)->estado();
    }

    /** Lo que se pidió en la rocola en las últimas tres horas. */
    public function pedidas(): \Illuminate\Support\Collection
    {
        return \App\Models\CancionPedida::with('user')->where('estado', 'en_cola')
            ->where('created_at', '>=', now()->subHours(3))->latest('id')->limit(8)->get();
    }

    /** Las listas guardadas de la fuente elegida, para el selector de la pantalla. */
    public function listas(): array
    {
        return \App\Support\MusicaDelParlante::listas();
    }

    /** El índice de la lista guardada que suena ahora, si es una de ellas. */
    public function listaActual(): ?int
    {
        $principal = \App\Support\MusicaDelParlante::principal();

        foreach ($this->listas() as $i => $l) {
            if ($l['enlace'] === $principal) {
                return $i;
            }
        }

        return null;
    }

    /** Cambia la música a otra lista guardada, sin recargar la pantalla. */
    public function ponerLista(int $indice): void
    {
        $cambio = \App\Support\MusicaDelParlante::ponerLista($indice);

        if (! $cambio) {
            \Filament\Notifications\Notification::make()->title('Esa lista no se pudo poner')
                ->body('Revisa su enlace en «Música».')->danger()->send();

            return;
        }

        $this->dispatch('parlante-cambiar-lista', ...$cambio);
    }

    private static function listasGuardadas(string $fuente): \Filament\Forms\Components\Repeater
    {
        return \Filament\Forms\Components\Repeater::make('listas_' . $fuente)
            ->label('Otras listas a mano')
            ->visible(fn ($get) => ($get('fuente') === 'spotify') === ($fuente === 'spotify'))
            ->addActionLabel('Añadir una lista')
            ->defaultItems(0)
            ->columns(2)
            ->collapsible()
            ->itemLabel(fn (array $state) => $state['nombre'] ?? null)
            ->helperText('Se cambian en vivo desde el Parlante, con el selector de «Música de fondo».')
            ->schema([
                \Filament\Forms\Components\TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(60)
                    ->placeholder('Mañana tranquila'),
                \Filament\Forms\Components\TextInput::make('enlace')
                    ->label('Enlace')
                    ->required()
                    ->maxLength(500)
                    ->placeholder($fuente === 'spotify' ? 'https://open.spotify.com/playlist/…' : 'https://www.youtube.com/playlist?list=…')
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) use ($fuente) {
                        $vale = $fuente === 'spotify'
                            ? \App\Support\SpotifyDelParlante::leerLista($value)
                            : \App\Support\MusicaDelParlante::leer($value);

                        if (filled($value) && ! $vale) {
                            $fail($fuente === 'spotify' ? 'Ese enlace no es de una lista de Spotify.' : 'Ese enlace no es de una lista ni de un video de YouTube.');
                        }
                    }),
            ]);
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
                ->modalDescription('Una lista de YouTube o de Spotify que suena en esta pantalla mientras está encendida. Baja sola cuando habla un anuncio y vuelve al terminar.')
                ->fillForm(fn () => [
                    'fuente'  => \App\Support\MusicaDelParlante::fuente(),
                    'spotify_lista'   => \App\Support\SpotifyDelParlante::lista(),
                    'spotify_cliente' => \App\Support\SpotifyDelParlante::cliente(),
                    'url'     => \App\Support\MusicaDelParlante::url(),
                    'listas_spotify' => \App\Support\MusicaDelParlante::listas('spotify'),
                    'listas_youtube' => \App\Support\MusicaDelParlante::listas('youtube'),
                    'volumen' => \App\Support\MusicaDelParlante::volumen(),
                    'aleatoria' => \App\Support\MusicaDelParlante::aleatoria(),
                ])
                ->schema([
                    \Filament\Forms\Components\ToggleButtons::make('fuente')
                        ->label('De dónde sale')
                        ->options(\App\Support\MusicaDelParlante::FUENTES)
                        ->default('youtube')
                        ->inline()
                        ->live()
                        ->required(),

                    // --- Spotify
                    \Filament\Forms\Components\TextInput::make('spotify_lista')
                        ->label('Enlace de la lista de Spotify')
                        ->placeholder('https://open.spotify.com/playlist/…')
                        ->maxLength(500)
                        ->visible(fn ($get) => $get('fuente') === 'spotify')
                        ->required(fn ($get) => $get('fuente') === 'spotify')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! \App\Support\SpotifyDelParlante::leerLista($value)) {
                                $fail('Ese enlace no es de una lista de Spotify.');
                            }
                        })
                        ->helperText('En Spotify: la lista → «…» → «Compartir» → «Copiar enlace de la lista».'),
                    \Filament\Forms\Components\TextInput::make('spotify_cliente')
                        ->label('Client ID de la app de Spotify')
                        ->maxLength(100)
                        ->visible(fn ($get) => $get('fuente') === 'spotify')
                        ->required(fn ($get) => $get('fuente') === 'spotify'),
                    \Filament\Forms\Components\TextInput::make('spotify_secreto')
                        ->label('Client secret')
                        ->password()
                        ->revealable()
                        ->maxLength(100)
                        ->visible(fn ($get) => $get('fuente') === 'spotify')
                        ->placeholder(fn () => \App\Support\SpotifyDelParlante::secreto() ? 'Guardado: déjalo vacío para no cambiarlo' : null)
                        ->required(fn ($get) => $get('fuente') === 'spotify' && ! \App\Support\SpotifyDelParlante::secreto())
                        ->helperText(fn () => new \Illuminate\Support\HtmlString(
                            'De developer.spotify.com → Dashboard → tu app → Settings. En esa misma pantalla, en «Redirect URIs», '
                            . 'registra exactamente: <code>' . e(\App\Support\SpotifyDelParlante::direccionDeVuelta()) . '</code>. '
                            . 'Se guarda cifrado.'
                        )),
                    \Filament\Forms\Components\Placeholder::make('spotify_estado')
                        ->label('Cuenta')
                        ->visible(fn ($get) => $get('fuente') === 'spotify')
                        ->content(fn () => \App\Support\SpotifyDelParlante::conectada()
                            ? 'Conectada: ' . \App\Support\SpotifyDelParlante::cuenta() . '.'
                            : 'Sin conectar. Guarda esto y pulsa «Conectar Spotify», arriba.'),

                    // --- YouTube
                    \Filament\Forms\Components\TextInput::make('url')
                        ->visible(fn ($get) => $get('fuente') !== 'spotify')
                        ->label('Enlace de la lista de YouTube')
                        ->placeholder('https://www.youtube.com/playlist?list=PL…')
                        ->maxLength(500)
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! \App\Support\MusicaDelParlante::leer($value)) {
                                $fail('Ese enlace no es de una lista ni de un video de YouTube.');
                            }
                        })
                        ->helperText('Sirve el de YouTube o el de YouTube Music: «Compartir» → «Copiar vínculo». La lista tiene que ser pública o no listada. Vacío: sin música.'),
                    // Otras listas a mano, con nombre: se cambian desde el
                    // Parlante sin volver aquí ni recargar.
                    self::listasGuardadas('spotify'),
                    self::listasGuardadas('youtube'),

                    \Filament\Forms\Components\TextInput::make('volumen')
                        ->label('Volumen de la música')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(\App\Support\MusicaDelParlante::VOLUMEN_POR_DEFECTO)
                        ->required()
                        ->helperText('Más bajo que los anuncios: es de fondo. 40 % suele ir bien.'),
                    \Filament\Forms\Components\Toggle::make('aleatoria')
                        ->label('Aleatorio')
                        ->default(true)
                        ->helperText('La lista se mezcla y empieza en una canción al azar cada vez que se enciende el parlante.'),
                ])
                ->action(function (array $data) {
                    // La de YouTube solo se toca si su campo estaba a la vista: vaciarlo es quitarla.
                    \App\Support\MusicaDelParlante::guardar(array_key_exists('url', $data) ? $data['url'] : \App\Support\MusicaDelParlante::url(), (int) $data['volumen'], (bool) ($data['aleatoria'] ?? true));
                    \App\Models\Setting::put(\App\Support\MusicaDelParlante::FUENTE, $data['fuente'] ?? 'youtube', 'parlante');

                    if (($data['fuente'] ?? null) === 'spotify') {
                        \App\Support\SpotifyDelParlante::guardar($data['spotify_lista'] ?? null, $data['spotify_cliente'] ?? null, $data['spotify_secreto'] ?? null);
                    }

                    foreach (['spotify', 'youtube'] as $f) {
                        if (array_key_exists('listas_' . $f, $data)) {
                            \App\Support\MusicaDelParlante::guardarListas($f, $data['listas_' . $f] ?? []);
                        }
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('Música guardada')
                        ->body(($data['fuente'] ?? null) === 'spotify' && ! \App\Support\SpotifyDelParlante::conectada()
                            ? 'Ahora pulsa «Conectar Spotify», arriba, e inicia sesión con la cuenta Premium del laboratorio.'
                            : 'Recarga esta pantalla en el equipo de los parlantes para que la tome.')
                        ->success()
                        ->send();
                }),

            // Una vez: iniciar sesión en Spotify con la cuenta del laboratorio.
            \Filament\Actions\Action::make('conectarSpotify')
                ->label(fn () => \App\Support\SpotifyDelParlante::conectada() ? 'Reconectar Spotify' : 'Conectar Spotify')
                ->icon('heroicon-o-link')
                ->color(fn () => \App\Support\SpotifyDelParlante::conectada() ? 'gray' : 'success')
                ->visible(fn () => \App\Support\MusicaDelParlante::fuente() === 'spotify' && \App\Support\SpotifyDelParlante::configurada())
                ->url(fn () => route('parlante.spotify.conectar')),
        ];
    }
}
