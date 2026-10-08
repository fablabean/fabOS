<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Dispositivos IoT → Desarrollo: la API y el montaje de la Raspberry, para
 * quien la va a configurar. Se lee de docs/IOT-API.md, como la de las gafas.
 */
class ApiDeDispositivos extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.api-de-las-gafas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'iot/desarrollo';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Dispositivos IoT';
    }

    public static function getNavigationLabel(): string
    {
        return 'Guía de la Raspberry · API';
    }

    public function getTitle(): string
    {
        return 'API de dispositivos IoT';
    }

    public function getSubheading(): ?string
    {
        return 'Para el equipo que monta la Raspberry Pi: la API, el cableado y el script.';
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $md = @file_get_contents(base_path('docs/IOT-API.md')) ?: '# Sin documentación';
        $md = preg_replace('/\A#\s[^\n]*\n/', '', str_replace("\r\n", "\n", $md));

        // La tabla de endpoints la pinta la página arriba, con botón de copiar.
        $md = preg_replace('/^### Endpoints\n.*?\n\n/ms', '', $md);

        return [
            'html' => Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'base' => url('/api/iot/dispositivo'),
            'endpoints' => [
                ['GET', route('api.iot.estado'), true, 'Saber si el dispositivo debe estar encendido (cada 5 s)', null],
            ],
        ];
    }
}
