<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Services\Contenido\DriveDelLaboratorio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * La carpeta de Drive del laboratorio, dentro del backoffice (§21).
 *
 * El equipo sube fotos y videos directo a Drive —muchos a la vez, pesados,
 * sin pasar por el túnel— y aquí se ven con sus miniaturas, carpeta por
 * carpeta. Nada se descarga al servidor: las miniaturas las sirve Google.
 */
class CarpetaDeDrive extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.carpeta-de-drive';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?int $navigationSort = 3;

    /**
     * Las carpetas por las que se ha entrado, desde la raíz.
     *
     * @var list<array{id:string,nombre:string}>
     */
    public array $ruta = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Carpeta de Drive';
    }

    public function getTitle(): string
    {
        return 'Carpeta de Drive del laboratorio';
    }

    public function getSubheading(): ?string
    {
        return 'Fotos y videos del laboratorio que viven en Google Drive. Súbelos allá —muchos a la vez, del tamaño que sean— y aquí se ven sin ocupar el servidor.';
    }

    private function drive(): DriveDelLaboratorio
    {
        return app(DriveDelLaboratorio::class);
    }

    /** La carpeta que se está mirando. */
    public function actual(): ?string
    {
        return end($this->ruta)['id'] ?? $this->drive()->carpeta();
    }

    /**
     * Lo que hay en la carpeta actual, o el error dicho en palabras.
     *
     * @return array{archivos:list<array<string,mixed>>,error:?string}
     */
    public function contenido(): array
    {
        if (! $this->drive()->configurada()) {
            return ['archivos' => [], 'error' => null];
        }

        try {
            return ['archivos' => $this->drive()->listar($this->actual()), 'error' => null];
        } catch (\RuntimeException $e) {
            return ['archivos' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Entrar a una subcarpeta. Solo a una que esté en el listado de la actual:
     * la cuenta de servicio puede ver otras carpetas que se le compartan, y
     * no se entra a ellas por pedirlas.
     */
    public function entrar(string $id): void
    {
        $carpeta = collect($this->contenido()['archivos'])->first(fn ($f) => $f['esCarpeta'] && $f['id'] === $id);

        if ($carpeta) {
            $this->ruta[] = ['id' => $carpeta['id'], 'nombre' => $carpeta['nombre']];
        }
    }

    /** Volver a una carpeta de la ruta; -1 es la raíz. */
    public function volverA(int $indice): void
    {
        $this->ruta = $indice < 0 ? [] : array_slice($this->ruta, 0, $indice + 1);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('subir')
                ->label('Subir a Drive')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(fn () => $this->drive()->enlaceDeLaCarpeta($this->actual()), shouldOpenInNewTab: true)
                ->visible(fn () => $this->drive()->configurada()),

            Action::make('actualizar')
                ->label('Actualizar')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->drive()->configurada())
                ->action(function () {
                    $this->drive()->olvidar($this->actual());
                    Notification::make()->success()->title('Listado actualizado')->send();
                }),

            Action::make('configurar')
                ->label('Configurar')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => static::permite('editar'))
                ->modalHeading('Conectar la carpeta de Drive')
                ->modalDescription('La carpeta raíz y la llave de la cuenta de servicio de Google que la lee. La llave se guarda cifrada.')
                ->schema([
                    TextInput::make('enlace')
                        ->label('Enlace de la carpeta')
                        ->placeholder('https://drive.google.com/drive/folders/…')
                        ->default(fn () => $this->drive()->enlaceDeLaCarpeta())
                        ->required(),
                    TextInput::make('llave_api')
                        ->label('O una llave de API de Google')
                        ->password()
                        ->revealable()
                        ->placeholder('AIza…')
                        ->helperText('Con llave de API la carpeta tiene que estar compartida como «Cualquier persona con el enlace puede ver». Restríngela en Google Cloud a la API de Drive y a la IP del servidor.'),
                    FileUpload::make('llave')
                        ->label('Llave JSON de la cuenta de servicio')
                        ->helperText(fn () => match (true) {
                            $this->drive()->conLlaveDeApi() => 'Ahora se entra con una llave de API. Sube un JSON solo si quieres pasar a una cuenta de servicio (carpeta privada).',
                            (bool) $this->drive()->correoDeLaCuenta() => 'Ya hay una: ' . $this->drive()->correoDeLaCuenta() . '. Sube otra solo si quieres cambiarla.',
                            default => 'El archivo .json de una cuenta de servicio, si la carpeta es privada. Déjalo vacío si usas llave de API.',
                        })
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->disk('local')
                        ->directory('tmp-drive')
                        ->maxSize(64),
                ])
                ->action(function (array $data) {
                    $llave = null;

                    if (filled($data['llave'] ?? null)) {
                        $llave = Storage::disk('local')->get($data['llave']);
                        // La llave no se queda en el disco: se guarda cifrada.
                        Storage::disk('local')->delete($data['llave']);
                    }

                    try {
                        $this->drive()->configurar($data['enlace'], filled($data['llave_api'] ?? null) ? $data['llave_api'] : $llave);
                    } catch (\RuntimeException $e) {
                        Notification::make()->danger()->title('No se pudo guardar')->body($e->getMessage())->send();

                        return;
                    }

                    $this->ruta = [];
                    Notification::make()->success()->title('Carpeta conectada')->send();
                }),
        ];
    }
}
