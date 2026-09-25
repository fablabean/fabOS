<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * El logo del laboratorio (§3).
 *
 * Estaba en un archivo del repositorio, así que cambiarlo exigía un
 * despliegue. Una marca se retoca —llega la versión definitiva, la del
 * aniversario, la del nodo acreditado— y quien la tiene no es quien tiene
 * acceso al servidor.
 *
 * Sale en la barra de todas las páginas y encabeza los documentos que el
 * laboratorio manda: la propuesta en PDF, el acuerdo de servicio, el de
 * alianza. Sin nada subido, sigue valiendo el del archivo de configuración.
 */
class Marca extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.marca';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 8;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Marca';
    }

    public function getTitle(): string
    {
        return 'Marca';
    }

    public function mount(): void
    {
        $this->form->fill([
            'logo'       => Settings::logo(),
            'logo_largo' => Settings::logoLargo(),
            'alto'       => Settings::altoDeLaMarca(),
            'con_texto'  => Settings::marcaConTexto(),
            'barra_color' => Settings::colorDeLaBarra()['fondo'] ?? null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('El logo')
                    ->description('Sale en la barra de todas las páginas, en la pestaña del navegador y encabeza los PDF que el laboratorio manda: la propuesta, el acuerdo de servicio, el de alianza. Sin nada subido se usa el que viene con el sistema.')
                    ->schema([
                        self::casilla('logo_largo', 'Versión larga')
                            ->helperText('La horizontal, la que suele llevar el nombre dentro. Sale en la barra en pantalla de trabajo y encabeza los PDF, que es donde hay ancho para ella.'),

                        self::casilla('logo', 'Versión compacta')
                            ->helperText('El símbolo solo, más o menos cuadrado. Sale en el móvil y en la pestaña del navegador, que es un cuadrado de dieciséis píxeles donde una marca horizontal se vería como una raya.'),

                        TextInput::make('alto')
                            ->label('Alto en la barra')
                            ->helperText('En píxeles. El ancho sale solo, de la proporción de la imagen: es lo que permite que una marca larga y una cuadrada convivan sin que ninguna se aplaste. Lo normal está entre 30 y 50.')
                            ->numeric()
                            ->minValue(16)
                            ->maxValue(120)
                            ->default(Settings::ALTO_POR_DEFECTO)
                            ->required()
                            ->suffix('px'),

                        Toggle::make('con_texto')
                            ->label('Escribir el nombre al lado del logo')
                            ->helperText('Apágalo si tu versión larga ya lleva el nombre dentro: si no, queda escrito dos veces en la misma barra.'),
                    ]),

                Section::make('La barra del menú')
                    ->description('Una marca no es sólo el logo: es el logo sobre algo. Con la barra fija en el color del tema, un logo claro no se puede usar porque desaparece.')
                    ->schema([
                        ColorPicker::make('barra_color')
                            ->label('Color de fondo')
                            ->helperText('Sólo el fondo: lo que se escribe encima —el nombre, los enlaces, el botón de menú del móvil— se calcula a partir de él, claro sobre oscuro y oscuro sobre claro. Déjalo vacío para que mande el tema, que es lo único que sabe responder al modo oscuro del sistema.'),
                    ]),
            ]);
    }

    /**
     * Las dos casillas son iguales salvo el rótulo y la ayuda.
     *
     * Disco publico EXPLICITO: lo ve quien entra sin haber iniciado sesion.
     * Y PNG, JPG o SVG y nada mas: son los tres que el generador de PDF sabe
     * pintar. Un WEBP se veria bien en la web y dejaria el documento en
     * blanco.
     */
    private static function casilla(string $campo, string $rotulo): FileUpload
    {
        return FileUpload::make($campo)
            ->label($rotulo)
            ->disk('public')
            ->visibility('public')
            ->directory('marca')
            ->image()
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
            ->imagePreviewHeight('90')
            ->maxSize(4096);
    }

    public function save(): void
    {
        $estado = $this->form->getState();

        $this->guardarArchivo(Settings::MARCA_LOGO, Settings::logo(), $estado['logo'] ?? null);
        $this->guardarArchivo(Settings::MARCA_LOGO_LARGO, Settings::logoLargo(), $estado['logo_largo'] ?? null);

        Setting::put(Settings::MARCA_ALTO, (int) ($estado['alto'] ?? Settings::ALTO_POR_DEFECTO), 'comunicaciones');
        Setting::put(Settings::MARCA_CON_TEXTO, (bool) ($estado['con_texto'] ?? true), 'comunicaciones');
        Setting::put(Settings::MARCA_BARRA, trim((string) ($estado['barra_color'] ?? '')), 'comunicaciones');

        Notification::make()->success()->title('Guardado')
            ->body($this->queSeUsaAhora())
            ->send();
    }

    /**
     * Guarda una ruta y borra la que reemplaza.
     *
     * El archivo viejo se va: un disco lleno de logos que ya nadie usa se
     * vuelve imposible de limpiar sin adivinar cuál es cuál. Pero sólo si de
     * verdad lo reemplaza y no lo comparte: subir el mismo archivo en las dos
     * casillas es legítimo —una marca que sirve para las dos cosas— y borrarlo
     * al guardar la otra dejaría las dos rotas.
     */
    private function guardarArchivo(string $clave, ?string $anterior, mixed $nuevoValor): void
    {
        $nuevo = trim((string) ($nuevoValor ?? ''));
        $enUso = array_filter([
            $clave === Settings::MARCA_LOGO ? Settings::logoLargo() : Settings::logo(),
        ]);

        if ($anterior && $anterior !== $nuevo && ! in_array($anterior, $enUso, true)) {
            Storage::disk('public')->delete($anterior);
        }

        Setting::put($clave, $nuevo, 'comunicaciones');
    }

    private function queSeUsaAhora(): string
    {
        return match (true) {
            (bool) Settings::logoLargo() && (bool) Settings::logo() =>
                'La larga en la barra y en los PDF, la compacta en el móvil y en la pestaña.',
            (bool) Settings::logoLargo() =>
                'Sólo hay versión larga: se usa en todas partes. Sube una compacta para el móvil y la pestaña.',
            (bool) Settings::logo() =>
                'Sólo hay versión compacta: se usa en todas partes. Sube una larga para la barra y los PDF.',
            default => 'Sin logo propio: se usa el que viene con el sistema.',
        };
    }
}
