<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Recorridos → Desarrollo: la API de las gafas, para quien programa la app.
 *
 * Se lee de docs/RECORRIDOS-API.md, el mismo archivo del repositorio: una sola
 * fuente, y lo que se corrige allá se ve aquí con el siguiente despliegue.
 */
class ApiDeLasGafas extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.api-de-las-gafas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'recorridos/desarrollo';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Recorridos';
    }

    public static function getNavigationLabel(): string
    {
        return 'Desarrollo · API';
    }

    public function getTitle(): string
    {
        return 'API de las gafas';
    }

    public function getSubheading(): ?string
    {
        return 'Para el equipo que programa la app de las Meta Quest 3.';
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $md = @file_get_contents(base_path('docs/RECORRIDOS-API.md')) ?: '# Sin documentación';

        // El título ya lo pone la página.
        $md = preg_replace('/\A#\s[^\n]*\n/', '', str_replace("\r\n", "\n", $md));

        // La tabla de endpoints la pinta la página arriba, con botón de copiar.
        $md = preg_replace('/^### Endpoints\n.*?\n\n/ms', '', $md);

        return [
            'html' => Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'base' => url('/api/recorridos/visor'),
            // De las rutas mismas: si un día cambian, esto no queda mintiendo.
            'endpoints' => [
                ['POST', route('api.visor.emparejar'), false, 'Emparejar las gafas con un equipo', ['codigo' => 'K7M2QX']],
                ['GET', route('api.visor.estado'), true, 'Consultar el estado (cada 2 s)', null],
                ['GET', route('api.visor.pistas'), true, 'Las imágenes de las pistas, otra vez (ya vienen al emparejar)', null],
                ['POST', route('api.visor.secuencia'), true, 'Enviar los 4 botones que marcó el líder', ['botones' => [2, 1, 4, 4]]],
                ['POST', route('api.visor.lider'), true, 'Elegir quién lleva las gafas (opcional)', ['integrante_id' => 32]],
            ],
        ];
    }
}
