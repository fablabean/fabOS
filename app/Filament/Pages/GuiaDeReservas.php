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
            'instrucciones' => Settings::instruccionesDeLaGuia(),
            // Con las de fábrica dentro si nunca se han tocado: se ven, se
            // editan y se borran, en vez de tener que adivinar que existen.
            'advertencias'  => Settings::advertenciasDeLaGuia(),
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

                Section::make('Cómo debe responder')
                    ->description('La guía acierta casi siempre y se equivoca en lo que no puede saber leyendo el catálogo. Aquí se corrige, sin desplegar nada. Lee «Lo que nos preguntan», abajo: ahí se ve qué está clasificando mal.')
                    ->schema([
                        Textarea::make('instrucciones')
                            ->label('Reglas de este laboratorio')
                            ->rows(8)
                            ->maxLength(4000)
                            ->placeholder(
                                "Una por renglón, como se las dirías a alguien nuevo en el mostrador:\n\n"
                                . "· «Impresión 3D para un proyecto» no es un encargo: casi siempre es alguien empezando. Mándalo a asesoría.\n"
                                . "· La cortadora láser grande está de baja hasta noviembre; no la recomiendes.\n"
                                . "· En semana de exámenes no se presta el taller para clases."
                            )
                            ->helperText('Se añaden a sus instrucciones y mandan sobre las de fábrica. No puede salirse de los cinco caminos ni ponerse a conversar: eso está fijo. Al guardar, lo ya contestado se vuelve a evaluar con las reglas nuevas.'),
                    ]),

                Section::make('Lo que siempre se advierte')
                    ->description('Esto NO lo decide la IA: se pega a la respuesta siempre que recomiende ese camino. Es para los malentendidos que cuestan un viaje al laboratorio, que no pueden depender de que el modelo se acuerde. Deja uno en blanco y no se dice nada.')
                    ->schema(
                        collect(\App\Services\Ia\GuiaDeReservas::CAMINOS)
                            ->map(fn (array $camino, string $clave) => Textarea::make("advertencias.{$clave}")
                                ->label($camino['titulo'])
                                ->rows(2)
                                ->maxLength(400))
                            ->values()
                            ->all()
                    ),
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
        Setting::put(Settings::GUIA_INSTRUCCIONES, trim((string) ($estado['instrucciones'] ?? '')), 'comunicaciones');

        // Se guarda el arreglo entero, incluso vacío: vacío significa «no
        // advertir nada», que es distinto de «nunca se ha tocado esto».
        Setting::put(Settings::GUIA_ADVERTENCIAS, array_map(
            fn ($texto) => trim((string) $texto),
            (array) ($estado['advertencias'] ?? []),
        ), 'comunicaciones');

        Notification::make()->success()->title('Guardado')
            ->body(Settings::instruccionesDeLaGuia() !== ''
                ? 'La guía responde con tus reglas desde la próxima pregunta, también en lo que ya se había preguntado.'
                : 'Sin reglas propias: la guía usa sólo las de fábrica.')
            ->send();
    }
}
