<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Forms\Components\Checkbox;
use App\Filament\Componentes\CampoDeDinero;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CourseForm
{
    public const TIPOS_DE_MATERIAL = [
        'imagen'  => 'Imagen',
        'video'   => 'Video',
        'archivo' => 'Documento o enlace',
    ];

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

                        Select::make('kind')
                            ->label('Tipo')
                            ->options(Course::TIPOS)
                            ->default('curso')
                            ->required()
                            ->helperText('Curso, taller o evento: los tres se inscriben igual y suben por la misma escalera de niveles.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        TextInput::make('hours')->label('Duración en horas')->numeric(),

                        /*
                         * El costo se escribe en la moneda de trabajo del
                         * laboratorio, no en la que este formulario decidiera
                         * por su cuenta: estaba fijo en FabCoins mientras un
                         * servicio de la tienda se tarifaba en pesos, y quien
                         * pasaba de uno a otro tenia que acordarse de en cual
                         * estaba. Escribir 11.200 donde iban 11.200.000 no da
                         * ningun error: da un curso regalado.
                         */
                        CampoDeDinero::selector(['price_minor']),

                        CampoDeDinero::make('price_minor')
                            ->label('Costo')
                            ->helperText('Cero si no tiene costo para la comunidad.'),

                        /*
                         * Lo que se vende fuera se dice tambien en dolares.
                         *
                         * Fab Academy tiene un precio en dolares y lo mira
                         * gente de fuera del pais; verlo solo en pesos obliga a
                         * cada uno a buscar la tasa. La conversion usa la TRM
                         * del dia, asi que la cifra no envejece sola.
                         */
                        Toggle::make('mostrar_usd')
                            ->label('Enseñar también el precio en dólares')
                            ->helperText('En el catálogo público, al lado del precio. Con la TRM del día.')
                            ->columnSpanFull(),
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
                            ->helperText('Sale en el catálogo de /formación, al lado del curso, recortada a lo alto de la tarjeta. Algo de lo que se hace en él: una pieza saliendo de la máquina, alguien trabajando.')
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

                        Toggle::make('by_preenrollment')
                            ->label('Se entra por preinscripción')
                            ->helperText('Para programas que solo abren si se junta gente, como Fab Academy. La edición «planeada» recibe preinscritos desde su propia página, y se abre cuando se decida.')
                            ->columnSpanFull(),
                    ]),

                Section::make('La publicación')
                    ->description('Lo que se ve en la página de cada fecha o grupo, con su formulario de inscripción. Desde la edición puedes abrir la vista previa antes de publicar.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        FileUpload::make('banner_path')
                            ->label('Banner o imagen principal')
                            ->helperText('Ancha, idealmente 1600 × 600. Va detrás del título y arriba de la descripción.')
                            ->disk('public')
                            ->visibility('public')
                            ->image()
                            ->directory('cursos/banners')
                            ->maxSize(20480)
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(2400)
                            ->imageResizeTargetHeight(2400)
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(fn ($file) => app(OptimizadorDeImagen::class)->guardar($file, 'cursos/banners'))
                            ->columnSpanFull(),

                        Textarea::make('objectives')->label('Objetivos')->rows(3)->columnSpanFull(),

                        Textarea::make('recommendations')
                            ->label('Recomendaciones')
                            ->rows(2)
                            ->placeholder('Llega 10 minutos antes. Usa zapato cerrado.')
                            ->columnSpanFull(),

                        Toggle::make('includes_materials')
                            ->label('Incluye materiales')
                            ->live()
                            ->columnSpanFull(),

                        Textarea::make('materials_included')
                            ->label('Qué materiales incluye')
                            ->rows(2)
                            ->visible(fn (Get $get) => (bool) $get('includes_materials')),

                        Textarea::make('materials_to_bring')
                            ->label('Qué debe llevar el participante')
                            ->rows(2)
                            ->placeholder('Su computador con Inkscape instalado.')
                            ->helperText('Va también en el correo de confirmación.'),

                        Repeater::make('gallery')
                            ->label('Imágenes, videos y material informativo')
                            ->addActionLabel('Añadir')
                            ->defaultItems(0)
                            ->collapsed()
                            ->itemLabel(fn (array $state) => ($state['titulo'] ?? null) ?: (self::TIPOS_DE_MATERIAL[$state['tipo'] ?? ''] ?? null))
                            ->columns(2)
                            ->schema([
                                Select::make('tipo')
                                    ->label('Qué es')
                                    ->options(self::TIPOS_DE_MATERIAL)
                                    ->default('imagen')
                                    ->required()
                                    ->live(),

                                TextInput::make('titulo')->label('Título o pie')->maxLength(160),

                                FileUpload::make('archivo')
                                    ->label('Archivo')
                                    ->disk('public')
                                    ->visibility('public')
                                    ->directory('cursos/material')
                                    ->maxSize(20480)
                                    ->visible(fn (Get $get) => in_array($get('tipo'), ['imagen', 'archivo'], true))
                                    ->acceptedFileTypes(fn (Get $get) => $get('tipo') === 'imagen'
                                        ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
                                        : ['application/pdf', 'image/jpeg', 'image/png'])
                                    ->columnSpanFull(),

                                TextInput::make('url')
                                    ->label(fn (Get $get) => $get('tipo') === 'video' ? 'Enlace de YouTube o Vimeo' : 'O un enlace')
                                    ->url()
                                    ->visible(fn (Get $get) => in_array($get('tipo'), ['video', 'archivo'], true))
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),

                        Textarea::make('registration_terms')
                            ->label('Condiciones de inscripción y cancelación')
                            ->rows(3)
                            ->placeholder((string) config('fabos.formacion.condiciones_de_inscripcion'))
                            ->helperText('Se aceptan con una casilla obligatoria al inscribirse. Vacío: el texto general, que ves de ejemplo.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Formulario de inscripción')
                    ->description('Siempre se pregunta nombre y apellidos, correo, programa o dependencia, tipo de participante y la aceptación de las condiciones: no se pueden quitar. Aquí van las preguntas propias de la actividad.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('registrationQuestions')
                            ->label('')
                            ->relationship()
                            ->orderColumn('position')
                            ->addActionLabel('Añadir una pregunta')
                            ->defaultItems(0)
                            ->collapsed()
                            ->itemLabel(fn (array $state) => \Illuminate\Support\Str::limit($state['label'] ?? '', 60)
                                . (! empty($state['required']) ? ' *' : '')
                                . (isset($state['type']) ? ' · ' . (\App\Models\RegistrationQuestion::TIPOS[$state['type']] ?? '') : ''))
                            ->columns(2)
                            ->schema([
                                TextInput::make('label')
                                    ->label('Pregunta o requisito')
                                    ->required()
                                    ->maxLength(300)
                                    ->columnSpanFull(),

                                Select::make('type')
                                    ->label('Tipo')
                                    ->options(\App\Models\RegistrationQuestion::TIPOS)
                                    ->default('texto')
                                    ->required()
                                    ->live(),

                                Toggle::make('required')
                                    ->label('Obligatoria')
                                    ->helperText('Sin responderla no se puede terminar la inscripción. En un archivo: sin subirlo, no hay inscripción.')
                                    ->inline(false),

                                \Filament\Forms\Components\TagsInput::make('options')
                                    ->label('Opciones')
                                    ->placeholder('Escribe una y pulsa Enter')
                                    ->visible(fn (Get $get) => in_array($get('type'), \App\Models\RegistrationQuestion::CON_OPCIONES, true))
                                    ->required(fn (Get $get) => in_array($get('type'), \App\Models\RegistrationQuestion::CON_OPCIONES, true))
                                    ->columnSpanFull(),

                                TextInput::make('help')
                                    ->label('Ayuda')
                                    ->maxLength(300)
                                    ->placeholder(fn (Get $get) => $get('type') === 'archivo' ? 'Tu diseño en SVG o DXF, a escala real.' : null)
                                    ->columnSpanFull(),

                                \Filament\Forms\Components\CheckboxList::make('participant_types')
                                    ->label('Solo para')
                                    ->options(collect(\App\Models\Enrollment::TIPOS_DE_PARTICIPANTE)->map(fn ($t) => $t['nombre'])->all())
                                    ->columns(3)
                                    ->helperText('Ninguno marcado: se le pregunta a todos.')
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Encuesta de satisfacción')
                    ->description('Se envía después de la actividad, solo a quienes registraron asistencia. Los resultados quedan en cada edición.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('surveyQuestions')
                            ->label('')
                            ->relationship()
                            ->orderColumn('position')
                            ->addActionLabel('Añadir una pregunta')
                            ->defaultItems(0)
                            ->collapsed()
                            ->itemLabel(fn (array $state) => \Illuminate\Support\Str::limit($state['label'] ?? '', 60))
                            ->columns(2)
                            ->schema([
                                TextInput::make('label')->label('Pregunta')->required()->maxLength(300)->columnSpanFull(),

                                Select::make('type')
                                    ->label('Tipo')
                                    ->options(\App\Models\SurveyQuestion::TIPOS)
                                    ->default('escala')
                                    ->required()
                                    ->live(),

                                Toggle::make('required')->label('Obligatoria')->default(true)->inline(false),

                                \Filament\Forms\Components\TagsInput::make('options')
                                    ->label('Opciones')
                                    ->placeholder('Escribe una y pulsa Enter')
                                    ->visible(fn (Get $get) => $get('type') === 'seleccion')
                                    ->required(fn (Get $get) => $get('type') === 'seleccion')
                                    ->columnSpanFull(),
                            ]),
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
                                ...self::material('teoria'),
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
                            /*
                             * En la base, las opciones son una lista y la
                             * correcta es un numero. En el formulario, cada
                             * opcion lleva su marca: se señala la buena sobre
                             * la propia respuesta, y la marca viaja con ella
                             * si se reordena. Se traduce al entrar y al salir.
                             */
                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => self::opcionesParaElFormulario($data))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::opcionesParaGuardar($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::opcionesParaGuardar($data))
                            ->schema([
                                Textarea::make('prompt')->label('Pregunta')->rows(2)->required(),
                                ...self::material('examen'),

                                Repeater::make('opciones')
                                    ->label('Opciones')
                                    ->addActionLabel('Añadir una opción')
                                    ->minItems(2)
                                    ->defaultItems(3)
                                    ->columns(6)
                                    ->helperText('Marca la correcta. En el examen salen en un orden distinto cada vez, así que no importa cuál va primero.')
                                    ->schema([
                                        TextInput::make('texto')
                                            ->hiddenLabel()
                                            ->placeholder('Una respuesta')
                                            ->required()
                                            ->columnSpan(5),

                                        /*
                                         * Una sola correcta, por ahora: al
                                         * marcar esta se desmarcan las demas.
                                         * Desde dentro de la fila, `../../`
                                         * es la lista entera de opciones.
                                         */
                                        Checkbox::make('correcta')
                                            ->label('Correcta')
                                            ->inline()
                                            ->live()
                                            ->afterStateUpdated(function (bool $state, Get $get, Set $set, Checkbox $component) {
                                                if (! $state) {
                                                    return;
                                                }

                                                // La ruta va con puntos: `...opciones.<fila>.correcta`.
                                                $partes = explode('.', $component->getStatePath());
                                                $propia = $partes[count($partes) - 2];

                                                foreach (array_keys($get('../../opciones') ?? []) as $fila) {
                                                    if ((string) $fila !== $propia) {
                                                        $set('../../opciones.' . $fila . '.correcta', false);
                                                    }
                                                }
                                            })
                                            ->columnSpan(1),
                                    ])
                                    // Sin correcta no hay examen que corregir.
                                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                        $marcadas = collect($value ?? [])->filter(fn ($o) => ! empty($o['correcta']))->count();

                                        if ($marcadas !== 1) {
                                            $fail('Marca cuál es la respuesta correcta: una, y solo una.');
                                        }
                                    })
                                    ->validationMessages(['min' => 'Una pregunta necesita al menos dos opciones.']),

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

    /**
     * De la base al formulario: la lista y el numero, a filas con su marca.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function opcionesParaElFormulario(array $data): array
    {
        $correcta = (int) ($data['correct'] ?? 0);

        $data['opciones'] = collect($data['options'] ?? [])
            ->values()
            ->map(fn ($texto, $i) => ['texto' => (string) $texto, 'correcta' => $i === $correcta])
            ->all();

        return $data;
    }

    /**
     * Del formulario a la base: las filas, a la lista y al numero de la
     * marcada. El orden es el del formulario; en el examen se baraja igual.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function opcionesParaGuardar(array $data): array
    {
        $filas = collect($data['opciones'] ?? [])->values();

        $data['options'] = $filas->map(fn ($o) => (string) ($o['texto'] ?? ''))->all();
        $data['correct'] = max(0, (int) $filas->search(fn ($o) => ! empty($o['correcta'])));

        unset($data['opciones']);

        return $data;
    }

    /**
     * La foto o el video que acompaña una pantalla o una pregunta.
     *
     * Dos campos: el fichero que se sube y el enlace a YouTube o Vimeo. Los
     * tutoriales de una maquina suelen estar ya en YouTube, y subir el video
     * otra vez es pesado para quien lo sube y para quien lo ve.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function material(string $directorio): array
    {
        return [
            FileUpload::make('media_path')
                ->label('Foto o video')
                // Disco publico explicito: se enseña a quien esta en el curso,
                // y el disco por defecto guarda en privado sin avisar.
                ->disk('public')
                ->visibility('public')
                ->directory('cursos/' . $directorio)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm'])
                ->maxSize(25600)
                ->helperText('Una foto de la máquina, de la pieza, de la pantalla. O un video corto en MP4 o WebM, por debajo de 10 MB. Una foto se encoge en tu navegador antes de subirla.')
                // La foto se encoge en el navegador antes de salir; a un video
                // el recorte no le aplica.
                ->imageResizeMode('contain')
                ->imageResizeTargetWidth(2000)
                ->imageResizeTargetHeight(2000)
                ->imageResizeUpscale(false)
                ->saveUploadedFileUsing(function ($file) use ($directorio) {
                    if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                        return $file->store('cursos/' . $directorio, 'public');
                    }

                    return app(OptimizadorDeImagen::class)->guardar($file, 'cursos/' . $directorio);
                })
                ->columnSpanFull(),

            TextInput::make('video_url')
                ->label('O un video de YouTube o Vimeo')
                ->url()
                ->placeholder('https://www.youtube.com/watch?v=…')
                ->helperText('Pega el enlace tal cual. Se incrusta en la pantalla; si además hay foto, la foto va primero.')
                ->columnSpanFull(),
        ];
    }
}
