<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\User;
use App\Services\Reports\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * El informe de cierre (§17).
 *
 * Se mira en pantalla y se imprime desde el mismo sitio. No hay un proceso que
 * «genere» el informe y lo guarde: se calcula al abrirlo, de los mismos datos
 * con los que opera el laboratorio, y por eso nunca puede quedar desactualizado.
 */
class Informes extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.informes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 2;

    /** Periodo elegido, en formato AAAA-MM-DD. */
    public string $desde = '';
    public string $hasta = '';


    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Documentación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Informe de cierre';
    }

    public function getTitle(): string
    {
        return 'Informe de cierre';
    }

    public function mount(): void
    {
        $tz = config('fabos.lab.timezone');
        [$desde, $hasta] = app(ReportService::class)->mesDe(Carbon::now($tz));

        $this->desde = $desde->format('Y-m-d');
        $this->hasta = $hasta->format('Y-m-d');
    }

    /** Atajo para el caso más común: cerrar el mes pasado. */
    public function mesAnterior(): void
    {
        $tz = config('fabos.lab.timezone');
        [$desde, $hasta] = app(ReportService::class)->mesDe(Carbon::now($tz)->subMonthNoOverflow());

        $this->desde = $desde->format('Y-m-d');
        $this->hasta = $hasta->format('Y-m-d');
    }

    public function getViewData(): array
    {
        $tz = config('fabos.lab.timezone');

        $desde = Carbon::parse($this->desde ?: 'today', $tz)->startOfDay();
        $hasta = Carbon::parse($this->hasta ?: 'today', $tz)->endOfDay();

        // Un rango al revés no es un error del sistema sino un dedazo: se
        // enderezan las fechas en vez de mostrar un informe vacío.
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()];
        }

        return [
            'informe' => app(ReportService::class)->generar($desde, $hasta),
            'enlace'  => route('informes.cierre', [
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
            ]),
        ];
    }
}
