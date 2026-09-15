<?php

namespace App\Filament\Resources\Paginas\Schemas;

use App\Models\Pagina;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * El editor de una página del sitio.
 *
 * La página es una pila de bloques que se arrastran. No es un editor de texto
 * largo con una barra de herramientas llena de botones: lo que se publica aquí
 * es material heterogéneo —un párrafo, ocho fotos, cuatro cifras— y el trabajo
 * de verdad es decidir en qué orden va, no poner una palabra en cursiva.
 */
class PaginaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('La página')
                    ->columns(2)
                    ->schema([
                        TextInput::make('titulo')
                            ->label('Título')
                            ->required()
                            ->maxLength(160)
                            ->live(onBlur: true)
                            /*
                             * El slug se propone al escribir el titulo, y solo
                             * mientras la pagina es nueva.
                             *
                             * Cambiarlo despues rompe el QR que ya se imprimio
                             * y el enlace que alguien pego en un correo. Se
                             * puede cambiar a mano —a veces hace falta—, pero
                             * que no pase solo por corregir una tilde del
                             * titulo.
                             */
                            ->afterStateUpdated(function ($state, $set, $get, ?Pagina $record) {
                                if ($record === null && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            })
                            ->columnSpanFull(),

                        TextInput::make('slug')
                            ->label('Dirección')
                            ->required()
                            ->maxLength(120)
                            ->unique(ignoreRecord: true)
                            ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->validationMessages([
                                'regex' => 'Solo minúsculas, números y guiones: asi-se-escribe.',
                                'unique' => 'Ya hay una página con esa dirección.',
                            ])
                            ->prefix(rtrim(config('app.url'), '/').'/p/')
                            ->helperText('Esto es lo que se dice en voz alta y lo que va en el QR. Una vez publicada, cambiarla rompe los enlaces que ya circulan.'),

                        TextInput::make('rotulo')
                            ->label('Rótulo')
                            ->maxLength(80)
                            ->placeholder('Fabricación digital')
                            ->helperText('La línea pequeña de arriba del título. Suele ser el área, o el tipo de cosa que es.'),

                        Textarea::make('resumen')
                            ->label('Resumen')
                            ->rows(3)
                            ->maxLength(400)
                            ->helperText('Una o dos frases. Se lee debajo del título, y es lo que sale en el buscador y al compartir el enlace.')
                            ->columnSpanFull(),

                        self::imagen('portada_path')
                            ->label('Imagen de portada')
                            ->helperText('Apaisada. Opcional: sin ella la página empieza directamente por el título.')
                            ->columnSpanFull(),
                    ]),

                Section::make('El contenido')
                    ->description('Se arrastran para cambiar el orden. Un bloque vacío no se publica.')
                    ->schema([
                        Builder::make('bloques')
                            ->hiddenLabel()
                            ->addActionLabel('Añadir un bloque')
                            ->collapsible()
                            ->collapsed()
                            ->blockNumbers(false)
                            ->blocks(self::bloques()),
                    ]),

                Section::make('Cuándo se ve')
                    ->columns(3)
                    ->schema([
                        /*
                         * Apagada por defecto, y el aviso dice por que.
                         *
                         * Una pagina sembrada desde un proyecto llega con datos
                         * que nadie ha leido todavia. El interruptor es el
                         * momento en que alguien se hace responsable de lo que
                         * dice.
                         */
                        Toggle::make('is_active')
                            ->label('Publicada')
                            ->helperText('Apagada, la dirección devuelve «no existe». Nadie la ve, ni aunque tenga el enlace.'),

                        DateTimePicker::make('starts_at')
                            ->label('Empieza a verse')
                            ->helperText('Opcional. Se deja escrita hoy y aparece sola el día del anuncio.'),

                        DateTimePicker::make('ends_at')
                            ->label('Deja de verse')
                            ->after('starts_at')
                            ->helperText('Opcional. Lo que anuncia un evento se apaga solo cuando el evento pasa.'),
                    ]),
            ]);
    }

    /**
     * Los bloques disponibles.
     *
     * @return list<Block>
     */
    private static function bloques(): array
    {
        return [
            Block::make('texto')
                ->label(Pagina::BLOQUES['texto'])
                ->icon('heroicon-o-bars-3-bottom-left')
                ->schema([
                    TextInput::make('titulo')
                        ->label('Título de la sección')
                        ->maxLength(120)
                        ->placeholder('De qué se trata'),

                    RichEditor::make('cuerpo')
                        ->label('Texto')
                        ->hiddenLabel()
                        // Corta a proposito: negrita, enlaces, subtitulos y
                        // listas. Con la barra entera, cada persona que escribe
                        // inventa su propia tipografia y el sitio deja de
                        // parecer un sitio.
                        ->toolbarButtons([
                            ['bold', 'italic', 'link'],
                            ['h2', 'h3'],
                            ['bulletList', 'orderedList', 'blockquote'],
                            ['undo', 'redo'],
                        ]),
                ]),

            Block::make('imagen')
                ->label(Pagina::BLOQUES['imagen'])
                ->icon('heroicon-o-photo')
                ->columns(2)
                ->schema([
                    self::imagen('imagen')
                        ->label('Imagen')
                        ->required()
                        ->columnSpanFull(),

                    TextInput::make('pie')
                        ->label('Pie de foto')
                        ->maxLength(200),

                    ToggleButtons::make('ancho')
                        ->label('Tamaño')
                        ->options(['normal' => 'Normal', 'completo' => 'De lado a lado'])
                        ->default('normal')
                        ->inline(),
                ]),

            Block::make('galeria')
                ->label(Pagina::BLOQUES['galeria'])
                ->icon('heroicon-o-squares-2x2')
                ->schema([
                    Repeater::make('imagenes')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir una foto')
                        ->grid(3)
                        ->reorderable()
                        ->schema([
                            self::imagen('imagen')->hiddenLabel()->required(),

                            TextInput::make('pie')
                                ->hiddenLabel()
                                ->placeholder('Pie de foto')
                                ->maxLength(200),

                            /*
                             * De que aporte del banco salio esta copia (§21).
                             *
                             * Lo pone la siembra desde un proyecto, no la
                             * persona. Sirve para una sola cosa, y es
                             * importante: si manana se retira ese aporte
                             * porque sale alguien que no queria aparecer, la
                             * pagina deja de enseñarlo sin que nadie tenga que
                             * acordarse de venir a borrarlo aqui.
                             */
                            Hidden::make('contenido_id'),
                        ]),
                ]),

            Block::make('video')
                ->label(Pagina::BLOQUES['video'])
                ->icon('heroicon-o-play')
                ->columns(2)
                ->schema([
                    // Un fichero propio, no un enlace a YouTube: lo que se
                    // graba en el laboratorio ya esta aqui, y un embebido
                    // mete a un tercero a mirar a quien visita la pagina.
                    FileUpload::make('video')
                        ->label('Video')
                        ->required()
                        ->disk('public')
                        ->visibility('public')
                        ->directory('paginas')
                        ->acceptedFileTypes(['video/mp4', 'video/webm'])
                        /*
                         * 25 MB, como el banner. El tope NO lo decide el gusto.
                         *
                         * Esto nacio en 50 MB «por si acaso», y un video de 49
                         * fallaba sin decir nada: pasaba el validador y se moria
                         * despues, en el tunel, que es donde revientan las
                         * peticiones largas. El formulario solo decia «error
                         * durante la subida».
                         *
                         * La leccion ya estaba escrita para el banco de
                         * contenido y este bloque nacio sin heredarla: mas vale
                         * un «no» del validador, inmediato y con su motivo, que
                         * un limite generoso que se cobra a mitad de camino.
                         */
                        ->maxSize(25600)
                        ->helperText('MP4 o WebM, hasta 25 MB. Un minuto de pantalla grabada cabe de sobra: si tu archivo pesa más, está sin comprimir. Lo que pesa tarda en aparecer en un teléfono, y por encima de ese tamaño la subida se cae por el camino sin poder explicarte por qué.')
                        ->columnSpanFull(),

                    self::imagen('poster')
                        ->label('Imagen mientras carga')
                        ->helperText('Un fotograma del propio video. Es lo único que ve quien tenga el ahorro de datos activado.'),

                    TextInput::make('pie')
                        ->label('Pie')
                        ->maxLength(200),
                ]),

            Block::make('cifras')
                ->label(Pagina::BLOQUES['cifras'])
                ->icon('heroicon-o-hashtag')
                ->schema([
                    Repeater::make('cifras')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir una cifra')
                        ->grid(3)
                        ->columns(1)
                        ->schema([
                            TextInput::make('numero')
                                ->hiddenLabel()
                                ->placeholder('120')
                                ->required()
                                ->maxLength(20),

                            TextInput::make('etiqueta')
                                ->hiddenLabel()
                                ->placeholder('piezas fabricadas')
                                ->required()
                                ->maxLength(60),
                        ]),
                ]),

            Block::make('datos')
                ->label(Pagina::BLOQUES['datos'])
                ->icon('heroicon-o-table-cells')
                ->schema([
                    TextInput::make('titulo')
                        ->label('Título de la ficha')
                        ->maxLength(120)
                        ->placeholder('El proyecto'),

                    Repeater::make('filas')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir un dato')
                        ->columns(2)
                        ->schema([
                            TextInput::make('clave')
                                ->hiddenLabel()
                                ->placeholder('Área')
                                ->required()
                                ->maxLength(60),

                            TextInput::make('valor')
                                ->hiddenLabel()
                                ->placeholder('Fabricación digital')
                                ->required()
                                ->maxLength(200),
                        ]),
                ]),

            Block::make('hitos')
                ->label(Pagina::BLOQUES['hitos'])
                ->icon('heroicon-o-flag')
                ->schema([
                    TextInput::make('titulo')
                        ->label('Título de la sección')
                        ->maxLength(120)
                        ->placeholder('Cómo fue'),

                    Repeater::make('hitos')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir un hito')
                        ->schema([
                            TextInput::make('cuando')
                                ->label('Cuándo')
                                // Texto libre y no una fecha: «marzo de 2026» y
                                // «el segundo semestre» son respuestas validas,
                                // y un selector de dia obliga a inventarse una
                                // precision que no se tiene.
                                ->placeholder('Marzo de 2026')
                                ->maxLength(60),

                            TextInput::make('titulo')
                                ->label('Qué pasó')
                                ->required()
                                ->maxLength(160),

                            Textarea::make('texto')
                                ->label('Detalle')
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

            Block::make('botones')
                ->label(Pagina::BLOQUES['botones'])
                ->icon('heroicon-o-cursor-arrow-rays')
                ->schema([
                    Repeater::make('botones')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir un botón')
                        ->columns(2)
                        ->schema([
                            TextInput::make('texto')
                                ->label('Qué dice')
                                ->required()
                                ->maxLength(40)
                                ->placeholder('Proponer un proyecto'),

                            TextInput::make('url')
                                ->label('A dónde lleva')
                                ->required()
                                ->maxLength(2000)
                                ->placeholder('https://…'),

                            Toggle::make('destacado')
                                ->label('Destacado')
                                ->default(true)
                                ->helperText('El de color. Si hay varios destacados, ninguno lo está.'),
                        ]),
                ]),
        ];
    }

    /**
     * Una imagen del sitio público.
     *
     * Siempre igual en toda la pantalla, y con las dos cosas que ya costaron
     * caro en el banner: disco público **explícito** —el disco por defecto es
     * privado, y la foto saldría rota sin dar error— y encogida en el propio
     * navegador antes de subirla, porque una foto de teléfono son ocho megas y
     * esa subida se cae por el túnel con un 502 que no explica nada.
     */
    private static function imagen(string $nombre): FileUpload
    {
        return FileUpload::make($nombre)
            ->disk('public')
            ->visibility('public')
            ->directory('paginas')
            ->image()
            ->maxSize(20480)
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth('2000')
            ->imageResizeTargetHeight('2000')
            ->imageResizeUpscale(false)
            ->saveUploadedFileUsing(
                fn ($file) => app(OptimizadorDeImagen::class)->guardar($file, 'paginas')
            );
    }
}
