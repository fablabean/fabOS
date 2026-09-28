<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Services\Reports\VentasPorQr;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El reporte mensual de lo cobrado por QR (§11).
 *
 * Quién pagó, con qué documento, por qué y cuánto. Se calcula al abrirlo, de
 * los mismos pagos que se validan en cada proyecto, y se baja como planilla.
 */
class ReporteDePagosQr extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.reporte-de-pagos-qr';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?int $navigationSort = 7;

    /** El mes elegido, AAAA-MM. */
    public string $mes = '';

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Reporte de pagos QR';
    }

    public function getTitle(): string
    {
        return 'Reporte de pagos QR';
    }

    public function mount(): void
    {
        $this->mes = Carbon::now(config('fabos.lab.timezone'))->format('Y-m');
    }

    public function mesAnterior(): void
    {
        $this->mes = $this->elMes()->subMonthNoOverflow()->format('Y-m');
    }

    public function descargar(): StreamedResponse
    {
        $csv = app(VentasPorQr::class)->csv(app(VentasPorQr::class)->delMes($this->elMes()));

        return response()->streamDownload(fn () => print($csv), 'pagos-qr-' . $this->elMes()->format('Y-m') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function getViewData(): array
    {
        $filas = app(VentasPorQr::class)->delMes($this->elMes());

        return [
            'filas'      => $filas,
            'nombreMes'  => $this->elMes()->locale('es')->translatedFormat('F \d\e Y'),
            'total'      => $filas->sum('valor'),
            'validado'   => $filas->where('validado', true)->sum('valor'),
            'porValidar' => $filas->where('validado', false)->sum('valor'),
        ];
    }

    private function elMes(): Carbon
    {
        $tz = config('fabos.lab.timezone');

        // Un campo vacío o mal escrito no es un error: se vuelve al mes en curso.
        return preg_match('/^\d{4}-\d{2}$/', $this->mes)
            ? Carbon::createFromFormat('Y-m-d', $this->mes . '-01', $tz)->startOfDay()
            : Carbon::now($tz)->startOfMonth();
    }
}
