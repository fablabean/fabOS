<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Dispositivos IoT → la guía del firmware de las pantallas e-paper, para
 * quien programa el ESP32. Se lee de docs/PANTALLAS-API.md, como la de la
 * Raspberry.
 */
class ApiDePantallas extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.api-de-las-gafas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?int $navigationSort = 43;

    protected static ?string $slug = 'iot/pantallas-api';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Dispositivos IoT';
    }

    public static function getNavigationLabel(): string
    {
        return 'Guía de las pantallas · API';
    }

    public function getTitle(): string
    {
        return 'API de las pantallas de los salones';
    }

    public function getSubheading(): ?string
    {
        return 'Para quien programa el firmware del ESP32: la API, el formato de la imagen y cómo probar.';
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $md = @file_get_contents(base_path('docs/PANTALLAS-API.md')) ?: '# Sin documentación';
        $md = preg_replace('/\A#\s[^\n]*\n/', '', str_replace("\r\n", "\n", $md));

        // La tabla de endpoints la pinta la página arriba, con botón de copiar.
        $md = preg_replace('/^\| Método \| URL .*?\n\n/ms', '', $md);

        return [
            'html' => Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'base' => url('/api/iot/pantalla'),
            'endpoints' => [
                ['GET', route('api.pantalla.estado'), true, 'Contar la batería, saber si cambió algo y cuándo volver a despertar', null],
                ['GET', route('api.pantalla.imagen'), true, 'Descargar la imagen ya dibujada (400×300, 15 000 bytes)', null],
            ],
        ];
    }
}
