<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Services\Analitica\InformeDeAnalitica;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El tablero de la analítica propia (§20): quién llega, de dónde, qué mira y
 * qué termina haciendo. Se calcula al abrirlo, de las tablas crudas.
 */
class Analitica extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.analitica';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 20;

    /** Cuántos días mirar hacia atrás, contando hoy. */
    public int $dias = 30;

    public const PERIODOS = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 365 => 'Último año'];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Analítica del sitio';
    }

    public function getTitle(): string
    {
        return 'Analítica del sitio';
    }

    public function getViewData(): array
    {
        $dias = array_key_exists($this->dias, self::PERIODOS) ? $this->dias : 30;
        $informe = InformeDeAnalitica::ultimosDias($dias);

        return [
            'informe'      => $informe,
            'cifras'       => $informe->cifras(),
            'porDia'       => $informe->porDia(),
            'paginas'      => $informe->paginas(),
            'entradas'     => $informe->entradas(),
            'canales'      => $informe->canales(),
            'fuentes'      => $informe->fuentes(),
            'campanas'     => $informe->campanas(),
            'dispositivos' => $informe->dispositivos(),
            'recorridos'   => $informe->recorridos(),
            'embudos'      => $informe->embudos(),
            'conversiones' => $informe->conversiones(),
            'rastreadores' => $informe->rastreadores(),
        ];
    }
}
