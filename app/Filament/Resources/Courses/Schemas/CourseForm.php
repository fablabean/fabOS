<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('El curso')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required(),

                        TextInput::make('slug')
                            ->label('Identificador')
                            ->required()
                            ->unique(ignoreRecord: true),

                        Select::make('level')
                            ->label('Nivel')
                            ->options(Course::NIVELES)
                            ->default('bit')
                            ->required()
                            ->helperText('Marca cuánta autonomía llega a dar. tera es Fab Academy.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        TextInput::make('hours')->label('Duración en horas')->numeric(),

                        TextInput::make('price_minor')
                            ->label('Costo')
                            ->numeric()
                            ->default(0)
                            ->prefix(config('fabos.currency.code'))
                            ->helperText('Cero si no tiene costo para la comunidad.')
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / config('fabos.currency.minor_units'))
                            ->dehydrateStateUsing(fn (?string $state) => (int) round(((float) $state) * config('fabos.currency.minor_units'))),
                    ]),

                Section::make('Qué habilita')
                    ->description('Aprobarlo otorga certifab sobre estas familias de riesgo. Sin esto, el curso es solo una charla: no abre ninguna máquina.')
                    ->schema([
                        Select::make('riskFamilies')
                            ->label('Familias de riesgo')
                            ->relationship('riskFamilies', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ]),

                Section::make('Para el catálogo público')
                    ->columns(2)
                    ->schema([
                        Textarea::make('summary')
                            ->label('Resumen')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Una o dos frases. Es lo que se lee en la lista.'),

                        Textarea::make('description')->label('Descripción')->rows(5)->columnSpanFull(),

                        Textarea::make('requirements')
                            ->label('Qué hace falta para entrar')
                            ->rows(2)
                            ->columnSpanFull(),

                        FileUpload::make('photo_path')
                            ->label('Foto')
                            // Disco publico EXPLICITO. El disco por defecto es
                            // `local`, cuya raiz en Laravel 11+ es
                            // storage/app/private: el archivo se guardaba ahi,
                            // la base apuntaba a el, y la pagina lo buscaba en
                            // storage/app/public. Resultado: la foto se subia
                            // «bien» y salia rota, sin ningun error.
                            //
                            // Aqui va explicito porque esta foto SE PUBLICA. Lo
                            // que no se publica —contratos de proyecto,
                            // evidencia de mantenimiento— se queda en el disco
                            // privado a proposito.
                            ->disk('public')
                            ->visibility('public')
                            ->image()
                            ->directory('cursos')
                            // Generoso a proposito: lo pesado se encoge, no se
                            // rechaza. El tope solo evita subidas absurdas.
                            ->maxSize(20480)
                            // El navegador la encoge ANTES de subirla.
                            //
                            // Una foto de telefono son tres o cuatro megas, y el
                            // servidor de la aplicacion tarda entre cinco y nueve
                            // segundos en recibirlos: por el tunel, esa lentitud se
                            // convierte a veces en un 502 y la subida falla sin
                            // explicar por que.
                            //
                            // Reducida en el propio telefono viaja en unos cientos
                            // de kilobytes. El optimizador del servidor sigue
                            // ahi como red de seguridad para lo que llegue grande.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(2000)
                            ->imageResizeTargetHeight(2000)
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(OptimizadorDeImagen::class)
                                    ->guardar($file, 'cursos')
                            )
                            ->columnSpanFull(),

                        Toggle::make('is_active')->label('Activo')->default(true),

                        Toggle::make('is_public')
                            ->label('Visible en el sitio')
                            ->default(true)
                            ->helperText('Un curso puede existir sin salir en la vitrina.'),
                    ]),

                Section::make('La teoría')
                    ->description('Pantallas cortas y en orden. Un manual de veinte páginas no lo lee nadie antes de un examen; seis pantallas de dos minutos, sí.')
                    ->collapsed()
                    ->schema([
                        Repeater::make('lessons')
                            ->label('')
                            ->relationship()
                            ->orderColumn('position')
                            ->addActionLabel('Añadir una pantalla')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state) => $state['title'] ?? null)
                            ->collapsed()
                            ->schema([
                                TextInput::make('title')->label('Título')->required(),
                                Textarea::make('body')->label('Contenido')->rows(8)->required(),
                            ]),
                    ]),

                Section::make('El examen teórico')
                    ->description('De opción múltiple: son las que se corrigen sin una persona delante, y eso permite que alguien lo haga un domingo.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('passing_score')
                            ->label('Mínimo para aprobar')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('%')
                            ->default(80)
                            ->helperText('Por curso y no global: no es lo mismo un primer contacto que lo que habilita a usar una máquina sola.'),

                        Repeater::make('questions')
                            ->label('Preguntas')
                            ->relationship()
                            ->orderColumn('position')
                            ->addActionLabel('Añadir una pregunta')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state) => \Illuminate\Support\Str::limit($state['prompt'] ?? '', 60) ?: null)
                            ->collapsed()
                            ->schema([
                                Textarea::make('prompt')->label('Pregunta')->rows(2)->required(),

                                Repeater::make('options')
                                    ->label('Opciones')
                                    ->simple(TextInput::make('opcion')->required())
                                    ->minItems(2)
                                    ->defaultItems(3)
                                    ->helperText('La primera es la número 0.'),

                                TextInput::make('correct')
                                    ->label('Cuál es la correcta')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->helperText('El número de la opción, empezando por 0.'),

                                Textarea::make('explanation')
                                    ->label('Por qué')
                                    ->rows(2)
                                    ->helperText('Se enseña al corregir. Un examen que solo dice «mal» enseña a adivinar, no a operar la máquina.'),
                            ]),
                    ]),

                Section::make('La evaluación presencial')
                    ->description('La firma una persona, delante de la máquina: una pantalla no puede ver si alguien nivela una cama.')
                    ->schema([
                        Toggle::make('requires_practical')
                            ->label('Este curso exige evaluación presencial')
                            ->helperText('Sin ella, aprobar el examen basta para el certifab. Con ella, alguien tiene que firmar que además sabe hacerlo.'),
                    ]),

            ]);
    }
}
