<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Services\Projects\SoportesDeSolicitud;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Qué archivos se pueden adjuntar a una solicitud de proyecto (§11).
 *
 * Cuántos, cuánto pesa cada uno y de qué tipo, para la solicitud, las
 * respuestas en la propuesta y el pedido de cotización de la tienda. Antes
 * eran constantes del código: agregar un formato que alguien necesitaba
 * —un .3mf, un .f3d— era pedir un despliegue.
 */
class ArchivosDeSolicitud extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.archivos-de-solicitud';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static ?int $navigationSort = 30;

    /** @var array<string,mixed> */
    public ?array $datos = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Proyectos';
    }

    public static function getNavigationLabel(): string
    {
        return 'Archivos de las solicitudes';
    }

    public function getTitle(): string
    {
        return 'Archivos que se pueden adjuntar';
    }

    public function getSubheading(): ?string
    {
        return 'Para la solicitud de proyecto en el sitio, las respuestas en la propuesta y el pedido de cotización de la tienda.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'maximo'    => SoportesDeSolicitud::maximo(),
            'tamano_mb' => intdiv(SoportesDeSolicitud::tamanoKb(), 1024),
            'tipos'     => SoportesDeSolicitud::tipos(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('Cuántos y cuánto')
                    ->columns(2)
                    ->schema([
                        TextInput::make('maximo')
                            ->label('Archivos por envío')
                            ->numeric()->integer()->minValue(1)->maxValue(20)->required(),
                        TextInput::make('tamano_mb')
                            ->label('Peso máximo de cada archivo')
                            ->numeric()->integer()->minValue(1)->maxValue(SoportesDeSolicitud::TAMANO_TECHO_MB)->required()
                            ->suffix('MB')
                            ->helperText('Hasta ' . SoportesDeSolicitud::TAMANO_TECHO_MB . ' MB, que es lo que recibe el servidor. Por encima de 25 MB las subidas se pueden caer en el túnel; para archivos muy pesados, mejor un enlace de Drive.'),
                    ]),

                Section::make('De qué tipo')
                    ->schema([
                        TagsInput::make('tipos')
                            ->label('Extensiones aceptadas')
                            ->placeholder('Escribe una, por ejemplo f3d, y Enter')
                            ->splitKeys([',', ' ', 'Tab'])
                            ->required()
                            ->helperText('Sin el punto. Nunca se aceptan las que un navegador o un servidor pueden ejecutar (php, html, js, exe…), aunque se escriban aquí. Los archivos se guardan en privado y se entregan como descarga.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $datos = $this->form->getState();

        $tipos = array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_strtolower(ltrim(trim((string) $t), '.')),
            (array) ($datos['tipos'] ?? []),
        ))));

        $prohibidos = array_values(array_intersect($tipos, SoportesDeSolicitud::PROHIBIDOS));
        $tipos = array_values(array_diff($tipos, SoportesDeSolicitud::PROHIBIDOS));

        if ($tipos === []) {
            Notification::make()->danger()->title('Hace falta al menos una extensión')->send();

            return;
        }

        Setting::put(SoportesDeSolicitud::AJUSTE_MAXIMO, (int) $datos['maximo'], 'proyectos');
        Setting::put(SoportesDeSolicitud::AJUSTE_TAMANO_MB, (int) $datos['tamano_mb'], 'proyectos');
        Setting::put(SoportesDeSolicitud::AJUSTE_TIPOS, $tipos, 'proyectos');

        $this->mount();

        $aviso = Notification::make()->success()->title('Guardado');

        if ($prohibidos) {
            $aviso->warning()->body('Se quitaron por seguridad: ' . implode(', ', $prohibidos) . '.');
        }

        $aviso->send();
    }
}
