<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Los beneficios para estudiantes de Educación Continua, por escrito (§5, §12).
 *
 * Es el documento que se le envía a Educación Continua: qué recibe cada
 * estudiante de bootcamp, curso y diplomado, y cómo se activa. Sale de la
 * configuración vigente, así que se descarga siempre con las cifras del día.
 */
class BeneficiosEducacionContinua extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.beneficios-educacion-continua';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Documentación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Beneficios de Educación Continua';
    }

    public function getTitle(): string
    {
        return 'Beneficios para estudiantes de Educación Continua';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('beneficios.educacion-continua', ['pdf' => 1])),

            Action::make('abrir')
                ->label('Abrir en otra pestaña')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(route('beneficios.educacion-continua'))
                ->openUrlInNewTab(),

            Action::make('matriculas')
                ->label('Matrículas')
                ->icon('heroicon-o-academic-cap')
                ->color('gray')
                ->url(fn () => \App\Filament\Resources\Matriculas\MatriculaResource::getUrl('index'))
                ->visible(fn () => \App\Filament\Resources\Matriculas\MatriculaResource::canAccess()),
        ];
    }
}
