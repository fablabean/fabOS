<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Support\PaginaFabAcademy as Contenido;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Lo que se enseña en `/fab-academy` (App\Support\PaginaFabAcademy).
 *
 * Por pestañas y en el orden en que se lee la página: quien sube la foto del
 * laboratorio no tiene por qué pasar por las preguntas frecuentes. Lo que se
 * deja vacío vuelve al texto de fábrica; las fotos, los videos, el equipo y
 * los proyectos no tienen texto de fábrica y su bloque no sale hasta que hay
 * algo que enseñar.
 */
class PaginaFabAcademy extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.pagina-fab-academy';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAmericas;

    protected static ?int $navigationSort = 9;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Formación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Página Fab Academy';
    }

    public function getTitle(): string
    {
        return 'Página Fab Academy';
    }

    public function mount(): void
    {
        $this->form->fill(Contenido::contenido());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Tabs::make('secciones')->persistTabInQueryString()->tabs([
                    Tab::make('Portada')->schema([
                        Grid::make(2)->schema([
                            TextInput::make('duracion')->label('Duración')->placeholder('5 meses'),
                            TextInput::make('modalidad')->label('Modalidad')->placeholder('Presencial'),
                        ]),
                        Section::make('Video de la portada')
                            ->description('De 30 a 45 segundos: diseño, impresión 3D, electrónica, corte láser, CNC y proyectos terminados. Sube el archivo o pega un enlace de YouTube o Vimeo; si hay los dos, manda el archivo.')
                            ->schema([
                                self::video('video_portada.archivo'),
                                TextInput::make('video_portada.url')->label('O enlace de YouTube / Vimeo')->url(),
                                TextInput::make('video_portada.rotulo')->label('Texto sobre el video'),
                            ]),
                        Repeater::make('pasos')
                            ->label('Qué es: los cuatro pasos')
                            ->schema([
                                TextInput::make('titulo')->required(),
                                Textarea::make('texto')->rows(2),
                                self::imagen('imagen', 'Foto real del paso'),
                            ])
                            ->grid(2)->reorderable()->maxItems(6)->collapsible()
                            ->itemLabel(fn (array $state) => $state['titulo'] ?? null),
                    ]),

                    Tab::make('Programa')->schema([
                        Repeater::make('semana')
                            ->label('Así se vive una semana')
                            ->schema([
                                TextInput::make('titulo')->required(),
                                TextInput::make('texto'),
                            ])
                            ->reorderable()->collapsible()->maxItems(7)
                            ->itemLabel(fn (array $state) => $state['titulo'] ?? null),
                        Repeater::make('categorias')
                            ->label('Tecnologías y habilidades')
                            ->helperText('Cada categoría es una tarjeta; sus temas se despliegan con «Ver más». Un tema por línea.')
                            ->schema([
                                TextInput::make('nombre')->required(),
                                Textarea::make('temas')->rows(5),
                                self::imagen('imagen', 'Foto de la categoría'),
                            ])
                            ->grid(2)->reorderable()->collapsible()
                            ->itemLabel(fn (array $state) => $state['nombre'] ?? null),
                        Section::make('Dedicación')->schema([
                            TextInput::make('dedicacion.horas')->label('Horas por semana'),
                            Textarea::make('dedicacion.nota')->label('Nota')->rows(2),
                            Repeater::make('dedicacion.reparto')
                                ->label('Cómo se reparte (un ejemplo)')
                                ->schema([
                                    TextInput::make('actividad')->required(),
                                    TextInput::make('horas')->placeholder('5–8 h'),
                                ])
                                ->columns(2)->reorderable(),
                        ]),
                        Section::make('Portafolio')
                            ->description('Una captura de un portafolio real de Fab Academy y el enlace para verlo.')
                            ->schema([
                                Textarea::make('portafolio.texto')->label('Texto')->rows(3),
                                self::imagen('portafolio.imagen', 'Captura del portafolio'),
                                TextInput::make('portafolio.url')->label('Enlace «Ver ejemplo de documentación»')->url(),
                            ]),
                        Section::make('Actividades grupales')->schema([
                            Textarea::make('grupales.texto')->label('Texto')->rows(3),
                            Textarea::make('grupales.ejemplos')->label('Ejemplos (uno por línea)')->rows(3),
                            self::imagen('grupales.imagen', 'Foto de gente trabajando junta'),
                        ]),
                    ]),

                    Tab::make('Proyectos')->schema([
                        Repeater::make('proyectos')
                            ->label('Proyectos finales')
                            ->helperText('De 4 a 6 proyectos reales. El marcado como destacado sale en grande; los demás, en el carrusel. Sin proyectos, el bloque no sale.')
                            ->schema([
                                self::imagen('imagen', 'Foto'),
                                TextInput::make('nombre')->required(),
                                Grid::make(2)->schema([
                                    TextInput::make('estudiante'),
                                    TextInput::make('anio')->label('Año')->maxLength(4),
                                ]),
                                TextInput::make('tecnologias')->label('Tecnologías')->placeholder('Impresión 3D, electrónica, programación'),
                                Textarea::make('resumen')->rows(2),
                                TextInput::make('url')->label('Documentación completa')->url(),
                                Toggle::make('destacado'),
                            ])
                            ->grid(2)->reorderable()->collapsible()->maxItems(10)
                            ->itemLabel(fn (array $state) => $state['nombre'] ?? null),
                    ]),

                    Tab::make('Laboratorio')->schema([
                        Textarea::make('laboratorio.texto')->label('Texto')->rows(2),
                        self::imagen('laboratorio.panoramica', 'Foto panorámica del laboratorio'),
                        Repeater::make('laboratorio.tecnologias')
                            ->label('Tecnologías del laboratorio')
                            ->schema([
                                TextInput::make('nombre')->required(),
                                self::imagen('imagen', 'Foto'),
                            ])
                            ->grid(3)->reorderable()->maxItems(8)
                            ->itemLabel(fn (array $state) => $state['nombre'] ?? null),
                        Section::make('Video del recorrido')
                            ->description('De 45 a 60 segundos por los espacios donde se trabaja.')
                            ->schema([
                                self::video('laboratorio.video_archivo'),
                                TextInput::make('laboratorio.video_url')->label('O enlace de YouTube / Vimeo')->url(),
                            ]),
                        Repeater::make('equipo')
                            ->label('Quién acompaña el proceso')
                            ->helperText('Sin personas, el bloque no sale.')
                            ->schema([
                                self::imagen('foto', 'Foto'),
                                TextInput::make('nombre')->required(),
                                TextInput::make('rol')->placeholder('Instructor local'),
                                TextInput::make('especialidad'),
                                Textarea::make('experiencia')->rows(2),
                            ])
                            ->grid(3)->reorderable()->collapsible()
                            ->itemLabel(fn (array $state) => $state['nombre'] ?? null),
                    ]),

                    Tab::make('Comunidad')->schema([
                        Section::make('Red global')->schema([
                            Textarea::make('comunidad.texto')->label('Texto')->rows(3),
                            self::imagen('comunidad.imagen', 'Mapa o foto de un encuentro'),
                        ]),
                        Section::make('Graduación')->schema([
                            Textarea::make('graduacion.texto')->label('Texto')->rows(3),
                            Textarea::make('graduacion.nota')->label('Lo que no incluye')->rows(2),
                            self::imagen('graduacion.imagen', 'Foto de una graduación'),
                        ]),
                        Textarea::make('obtiene')->label('Lo que te llevas (uno por línea)')->rows(6),
                        Textarea::make('certificacion')
                            ->label('Habilitaciones en el laboratorio (una por línea)')
                            ->helperText('Se suman a las familias de riesgo que el curso habilita.')
                            ->rows(6),
                    ]),

                    Tab::make('Inscripción')->schema([
                        Textarea::make('flujo')
                            ->label('Del interés al inicio (un paso por línea)')
                            ->helperText('Deja claro que la preinscripción aquí no reemplaza el registro oficial en Fab Academy.')
                            ->rows(5),
                        Textarea::make('inversion.incluye')->label('Qué incluye (uno por línea)')->rows(4),
                        Textarea::make('inversion.no_incluye')->label('Qué no incluye (uno por línea)')->rows(3),
                        Textarea::make('inversion.financiacion')->label('Financiación')->rows(2),
                    ]),

                    Tab::make('Preguntas y enlaces')->schema([
                        Repeater::make('faqs')
                            ->label('Preguntas frecuentes')
                            ->schema([
                                TextInput::make('pregunta')->required(),
                                Textarea::make('respuesta')->rows(3)->required(),
                            ])
                            ->reorderable()->collapsible()->collapsed()
                            ->itemLabel(fn (array $state) => $state['pregunta'] ?? null),
                        Repeater::make('enlaces')
                            ->label('Enlaces a Fab Academy oficial')
                            ->schema([
                                TextInput::make('texto')->required(),
                                TextInput::make('url')->url()->required(),
                            ])
                            ->columns(2)->reorderable()
                            ->itemLabel(fn (array $state) => $state['texto'] ?? null),
                    ]),
                ]),
            ]);
    }

    private static function imagen(string $campo, string $rotulo): FileUpload
    {
        return FileUpload::make($campo)
            ->label($rotulo)
            ->disk('public')
            ->visibility('public')
            ->directory(Contenido::CARPETA)
            ->image()
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth('1920')
            ->maxSize(8192)
            ->imagePreviewHeight('110');
    }

    private static function video(string $campo): FileUpload
    {
        return FileUpload::make($campo)
            ->label('Archivo de video (MP4)')
            ->disk('public')
            ->visibility('public')
            ->directory(Contenido::CARPETA)
            ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime'])
            ->maxSize(60 * 1024);
    }

    public function save(): void
    {
        Setting::put(Contenido::CLAVE, $this->form->getState(), 'formacion');

        Notification::make()->title('Página guardada')->success()->send();
    }

    public function restablecer(): void
    {
        Setting::put(Contenido::CLAVE, null, 'formacion');
        $this->form->fill(Contenido::porDefecto());

        Notification::make()->title('Textos de fábrica restablecidos')->success()->send();
    }
}
