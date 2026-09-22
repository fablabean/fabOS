<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * El banner de la guía de reservas (§10).
 *
 * La página de reservas pregunta cómo se quiere usar el laboratorio y ofrece
 * cuatro caminos; arriba de todo, una foto puede ayudar a reconocer el suyo
 * sin preguntar —un mapa de los cuatro, una infografía— y un texto corto
 * encima. Se suben aquí. Sin foto, el banner no se pinta y la página sigue
 * igual.
 */
class GuiaDeReservas extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.guia-de-reservas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 7;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Guía de reservas';
    }

    public function getTitle(): string
    {
        return 'Guía de reservas';
    }

    public function mount(): void
    {
        $this->form->fill([
            'imagen' => Settings::imagenDeLaGuia(),
            'texto'  => Settings::textoDeLaGuia(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('El banner de arriba')
                    ->description('Sale en la página de reservas, encima de los cuatro caminos. Una foto o infografía que ayude a reconocer el camino sin preguntar. Sin imagen, no se pinta nada.')
                    ->schema([
                        FileUpload::make('imagen')
                            ->label('Imagen')
                            // Disco publico EXPLICITO: esto se enseña a quien
                            // entra sin sesion.
                            ->disk('public')
                            ->visibility('public')
                            ->directory('guia')
                            ->image()
                            ->imagePreviewHeight('240')
                            ->maxSize(8192)
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(2000)
                            ->imageResizeTargetHeight(2000)
                            ->imageResizeUpscale(false)
                            ->helperText('Ancha, tipo banner: se ve a lo ancho de la página. Hasta 8 MB; se encoge sola.'),

                        Textarea::make('texto')
                            ->label('Texto encima de la imagen')
                            ->rows(2)
                            ->maxLength(200)
                            ->placeholder('¿Asesoría, encargo, tu pieza o un espacio? Mira el mapa, o escríbenos abajo qué necesitas.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $estado = $this->form->getState();

        $nuevo = trim((string) ($estado['imagen'] ?? ''));
        $anterior = Settings::imagenDeLaGuia();

        if ($anterior && $anterior !== $nuevo) {
            Storage::disk('public')->delete($anterior);
        }

        Setting::put(Settings::GUIA_IMAGEN, $nuevo, 'comunicaciones');
        Setting::put(Settings::GUIA_TEXTO, trim((string) ($estado['texto'] ?? '')), 'comunicaciones');

        Notification::make()->success()->title('Guardado')
            ->body(Settings::imagenDeLaGuia() ? 'El banner ya está en la página de reservas.' : 'Sin imagen, la página de reservas sigue sin banner.')
            ->send();
    }
}
