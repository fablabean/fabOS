<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Http\Controllers\BuscadoresController;
use App\Models\Setting;
use App\Support\Buscadores;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Lo que se ajusta de buscadores, IA y analítica (§20). Lo que explica por
 * qué está en Documentación → Buscadores y analítica.
 */
class BuscadoresYAnalitica extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.buscadores-y-analitica';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static ?int $navigationSort = 40;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Configuración';
    }

    public static function getNavigationLabel(): string
    {
        return 'Buscadores y analítica';
    }

    public function getTitle(): string
    {
        return 'Buscadores y analítica';
    }

    public function mount(): void
    {
        $this->form->fill([
            'descripcion'   => Setting::get(Buscadores::DESCRIPCION, ''),
            'redes'         => Buscadores::redes(),
            'google'        => Setting::get(Buscadores::GOOGLE, ''),
            'bing'          => Setting::get(Buscadores::BING, ''),
            'texto_para_ia' => Buscadores::textoParaIa(),
            'analitica'     => Buscadores::analiticaActiva(),
            'contar_equipo' => Buscadores::contarEquipo(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('Cómo se presenta el laboratorio')
                    ->description('Va en la descripción de las páginas que no tienen una propia, en los datos estructurados y al principio del llms.txt.')
                    ->schema([
                        Textarea::make('descripcion')
                            ->label('Descripción del laboratorio')
                            ->rows(3)
                            ->maxLength(300)
                            ->placeholder(Buscadores::descripcion())
                            ->helperText('Una o dos frases, con lo que alguien escribiría en un buscador: «fabricación digital», «corte láser», «Bogotá». Vacío: el texto de ejemplo.'),

                        TagsInput::make('redes')
                            ->label('Redes y sitios oficiales')
                            ->placeholder('https://www.instagram.com/… y Enter')
                            ->helperText('Instagram, LinkedIn, YouTube, la página del laboratorio en la Universidad, la de la red Fab. Le dicen a Google y a los asistentes que esas cuentas son del mismo laboratorio.'),

                        Textarea::make('texto_para_ia')
                            ->label('Lo que queremos que un asistente de IA sepa')
                            ->rows(5)
                            ->maxLength(3000)
                            ->placeholder("Horario de atención: lunes a viernes de 8:00 a 17:00.\nEl laboratorio está abierto a estudiantes, profesores y público externo.")
                            ->helperText('Va tal cual en el llms.txt, después del resumen. Datos que se preguntan y no están en ninguna página: horarios, a quién atiende, cómo llegar. En Markdown.'),
                    ]),

                Section::make('Verificación en Google y Bing')
                    ->description('Para ver en Google Search Console y Bing Webmaster Tools cómo aparece el sitio. Pega el código que dan al elegir «etiqueta HTML»; puedes pegar la etiqueta entera.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('google')->label('Google Search Console')->placeholder('<meta name="google-site-verification" content="…">'),
                        TextInput::make('bing')->label('Bing Webmaster Tools')->placeholder('<meta name="msvalidate.01" content="…">'),
                    ]),

                Section::make('Analítica')
                    ->columns(2)
                    ->schema([
                        Toggle::make('analitica')
                            ->label('Medir las visitas del sitio')
                            ->helperText('Sin cookies y sin datos personales. Apagada, el script no se pone en las páginas.'),
                        Toggle::make('contar_equipo')
                            ->label('Contar también al equipo')
                            ->helperText('Apagado, lo normal: las visitas del equipo con sesión iniciada no se cuentan, para no inflar las cifras mientras se revisa el sitio.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();

        Setting::put(Buscadores::DESCRIPCION, trim((string) ($d['descripcion'] ?? '')), 'buscadores');
        Setting::put(Buscadores::REDES, array_values(array_filter((array) ($d['redes'] ?? []), fn ($r) => filter_var(trim((string) $r), FILTER_VALIDATE_URL))), 'buscadores');
        Setting::put(Buscadores::GOOGLE, trim((string) ($d['google'] ?? '')), 'buscadores');
        Setting::put(Buscadores::BING, trim((string) ($d['bing'] ?? '')), 'buscadores');
        Setting::put(Buscadores::TEXTO_PARA_IA, trim((string) ($d['texto_para_ia'] ?? '')), 'buscadores');
        Setting::put(Buscadores::ANALITICA_ACTIVA, (bool) ($d['analitica'] ?? true), 'analitica');
        Setting::put(Buscadores::CONTAR_EQUIPO, (bool) ($d['contar_equipo'] ?? false), 'analitica');

        BuscadoresController::olvidar();

        Notification::make()->success()->title('Guardado')->body('El sitemap, el robots.txt y el llms.txt ya salen con lo nuevo.')->send();
    }
}
