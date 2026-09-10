<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\ProjectPayment;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    protected string $view = 'filament.pages.pagos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 6;

    public $qr = null;

    public string $instrucciones = '';

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
        $this->instrucciones = Settings::instruccionesDePago();
    }

    public function save(): void
    {
        $this->validate([
            'qr' => ['nullable', 'image', 'max:4096'],
        ], [
            'qr.image' => 'El QR tiene que ser una imagen (PNG o JPG).',
        ]);

        if ($this->qr) {
            $ruta = $this->qr->storeAs('pagos', 'qr-' . now()->format('Ymd-His') . '.' . $this->qr->getClientOriginalExtension(), 'local');

            if ($anterior = Settings::qrDePagos()) {
                Storage::disk('local')->delete($anterior);
            }

            Setting::put(Settings::PAGOS_QR, $ruta, 'finanzas');
            $this->qr = null;
        }

        Setting::put(Settings::PAGOS_INSTRUCCIONES, trim($this->instrucciones), 'finanzas');

        Notification::make()->success()->title('Pagos guardados')
            ->body(Settings::qrDePagos() ? 'El QR va en cada cobro desde ahora.' : 'Falta subir el QR: sin él no se puede pedir un pago.')
            ->send();
    }

    public function qrActual(): ?string
    {
        return Settings::qrDePagos() ? route('pagos.qr') . '?v=' . md5(Settings::qrDePagos()) : null;
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
