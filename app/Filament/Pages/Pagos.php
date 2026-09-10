<?php

namespace App\Filament\Pages;

use App\Filament\Componentes\ArchivoPrivado;
use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\ProjectPayment;
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
 * Los pagos por QR (§11).
 *
 * El codigo del banco es uno solo para todo el laboratorio: se sube aqui y
 * va con el valor en cada correo de cobro y en la pagina del proyecto. Abajo,
 * lo que espera: comprobantes por validar y pagos pedidos sin respuesta.
 */
class Pagos extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.pagos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 6;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pagos por QR';
    }

    public function getTitle(): string
    {
        return 'Pagos por QR';
    }

    public function mount(): void
    {
        $this->form->fill([
            'qr'            => Settings::qrDePagos(),
            'instrucciones' => Settings::instruccionesDePago(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('El código QR del banco')
                    ->description('Uno solo para todo el laboratorio. Va adjunto en cada correo de cobro y se ve en la página del proyecto junto al valor a pagar. Súbelo como imagen, tal como lo entrega el banco.')
                    ->schema([
                        // En el disco privado: es el mismo archivo que va
                        // adjunto en los correos, y se sirve por su propia
                        // ruta publica para pagar.
                        ArchivoPrivado::previsualizar(FileUpload::make('qr'))
                            ->label('La imagen del QR')
                            ->disk('local')
                            ->directory('pagos')
                            ->visibility('private')
                            ->image()
                            ->imagePreviewHeight('220')
                            ->maxSize(4096)
                            ->helperText('PNG o JPG, hasta 4 MB. Sin QR no se puede pedir un pago.'),
                    ]),

                Section::make('Lo que le decimos a quien paga')
                    ->description('Va en el correo y en la página del proyecto, encima del QR.')
                    ->schema([
                        Textarea::make('instrucciones')
                            ->label('Instrucción')
                            ->rows(3)
                            ->maxLength(600),
                    ]),
            ]);
    }

    public function save(): void
    {
        $estado = $this->form->getState();

        $nuevo = trim((string) ($estado['qr'] ?? ''));
        $anterior = Settings::qrDePagos();

        if ($anterior && $anterior !== $nuevo) {
            Storage::disk('local')->delete($anterior);
        }

        Setting::put(Settings::PAGOS_QR, $nuevo, 'finanzas');
        Setting::put(Settings::PAGOS_INSTRUCCIONES, trim((string) ($estado['instrucciones'] ?? '')), 'finanzas');

        Notification::make()->success()->title('Pagos guardados')
            ->body(Settings::qrDePagos() ? 'El QR va en cada cobro desde ahora.' : 'Falta subir el QR: sin él no se puede pedir un pago.')
            ->send();
    }

    /** Lo que espera algo, en todos los proyectos. */
    public function pendientes()
    {
        return ProjectPayment::query()
            ->whereIn('status', ProjectPayment::ABIERTOS)
            ->with(['project', 'requestedBy'])
            ->orderByRaw("CASE WHEN status = 'enviado' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
