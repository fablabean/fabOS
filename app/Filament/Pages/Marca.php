<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
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
        $this->form->fill(['logo' => Settings::logo()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('El logo')
                    ->description('Sale en la barra de todas las páginas y encabeza los PDF que el laboratorio manda: la propuesta, el acuerdo de servicio, el de alianza. Sin nada subido se usa el que viene con el sistema.')
                    ->schema([
                        FileUpload::make('logo')
                            ->label('Logo')
                            // Disco publico EXPLICITO: lo ve quien entra sin
                            // haber iniciado sesion.
                            ->disk('public')
                            ->visibility('public')
                            ->directory('marca')
                            ->image()
                            // PNG, JPG o SVG, y nada mas: son los tres que el
                            // generador de PDF sabe pintar. Un WEBP se veria
                            // bien en la web y dejaria el documento en blanco.
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
                            ->imagePreviewHeight('120')
                            ->maxSize(4096)
                            ->helperText('PNG con fondo transparente, o SVG. Se ve pequeño —alto de una línea en la barra, un par de centímetros en el PDF—, así que un logo con letra fina se pierde: mejor la versión compacta.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $estado = $this->form->getState();

        $nuevo = trim((string) ($estado['logo'] ?? ''));
        $anterior = Settings::logo();

        // El archivo viejo se va: un disco lleno de logos que ya nadie usa se
        // vuelve imposible de limpiar sin adivinar cuál es cuál.
        if ($anterior && $anterior !== $nuevo) {
            Storage::disk('public')->delete($anterior);
        }

        Setting::put(Settings::MARCA_LOGO, $nuevo, 'comunicaciones');

        Notification::make()->success()->title('Guardado')
            ->body(Settings::logo()
                ? 'Ya está en la barra y en los PDF que se generen desde ahora.'
                : 'Sin logo propio: se usa el que viene con el sistema.')
            ->send();
    }
}
