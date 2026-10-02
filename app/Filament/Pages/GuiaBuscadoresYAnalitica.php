<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Services\Analitica\Analitica;
use App\Services\Buscadores\MapaDelSitio;
use App\Support\Buscadores;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * La documentación de buscadores, IA y analítica (§20), en el panel.
 *
 * Como «Reglas del sistema»: lo redactado es el porqué; los números se leen
 * al abrir la página, para que la documentación no se quede atrás del sistema.
 */
class GuiaBuscadoresYAnalitica extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.guia-buscadores-y-analitica';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Documentación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Guía de buscadores y analítica';
    }

    public function getTitle(): string
    {
        return 'Buscadores, IA y analítica';
    }

    public function getViewData(): array
    {
        $paginas = app(MapaDelSitio::class)->paginas();
        $hace7 = now(config('fabos.lab.timezone'))->subDays(6)->toDateString();

        $ultimoRastreo = fn (string $familia) => DB::table('analitica_rastreos')->where('familia', $familia)->max('created_at');

        return [
            'porSeccion'   => $paginas->groupBy('seccion')->map->count(),
            'totalPaginas' => $paginas->count(),
            'google'       => Buscadores::verificacionGoogle() !== null,
            'bing'         => Buscadores::verificacionBing() !== null,
            'redes'        => Buscadores::redes(),
            'descripcion'  => Buscadores::descripcion(),
            'activa'       => Buscadores::analiticaActiva(),
            'contarEquipo' => Buscadores::contarEquipo(),
            'visitas7'     => DB::table('analitica_visitas')->where('dia', '>=', $hace7)->count(),
            'desdeCuando'  => DB::table('analitica_visitas')->min('dia'),
            'ultimoBuscador' => $ultimoRastreo('buscador'),
            'ultimaIa'     => $ultimoRastreo('ia'),
            'indexables'   => Buscadores::INDEXABLES,
            'prohibidos'   => Buscadores::PROHIBIDO_RASTREAR,
            'bots'         => Buscadores::RASTREADORES_DE_IA,
            'conversiones' => Analitica::CONVERSIONES,
            'fuentesIa'    => collect(Analitica::FUENTES['ia'])->unique()->values()->all(),
        ];
    }
}
