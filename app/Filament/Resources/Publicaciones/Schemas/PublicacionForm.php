<?php

namespace App\Filament\Resources\Publicaciones\Schemas;

use App\Models\Contenido;
use App\Models\Publicacion;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PublicacionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Lo que dijo quien aprueba, arriba de todo: es lo primero que
                // hay que leer al volver a abrirla.
                Section::make('Devuelta con comentarios')
                    ->visible(fn (?Publicacion $record) => filled($record?->devuelta_con))
                    ->schema([
                        Placeholder::make('devuelta')
                            ->hiddenLabel()
                            ->content(fn (?Publicacion $record) => $record?->devuelta_con),
                    ]),

                Section::make('Qué')
                    ->columns(3)
                    ->schema([
                        TextInput::make('titulo')
                            ->label('Título interno')
                            ->required()
                            ->maxLength(160)
                            ->placeholder('Anunciar el taller de corte láser')
                            ->helperText('Para reconocerla en la parrilla; no se publica.')
                            ->columnSpanFull(),

                        Select::make('pilar')
                            ->label('De qué habla')
                            ->options(Publicacion::PILARES),

                        Select::make('formato')
                            ->label('Formato')
                            ->options(Publicacion::FORMATOS),

                        Select::make('responsable_id')
                            ->label('Quién la hace')
                            ->relationship('responsable', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn () => auth()->id()),

                        CheckboxList::make('redes')
                            ->label('Dónde sale')
                            // Las del laboratorio, y las que ya tuviera: una red
                            // que se quitó de la lista no puede borrarse sola
                            // de lo que ya se planeó con ella.
                            ->options(fn (?Publicacion $record) => Publicacion::redesDelLaboratorio()
                                + array_intersect_key(Publicacion::redesPosibles(), array_flip($record?->redes ?? [])))
                            ->columns(4)
                            ->columnSpanFull(),

                        DateTimePicker::make('programada_para')
                            ->label('Cuándo sale')
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone'))
                            ->helperText('Vacía, queda como idea sin fecha, en la columna de al lado de la parrilla.'),
                    ]),

                Section::make('El texto')
                    ->schema([
                        Textarea::make('texto')
                            ->label('Texto de la publicación')
                            ->rows(8)
                            ->live(debounce: 600)
                            ->helperText(fn (?string $state) => self::largo($state)),

                        Textarea::make('hashtags')
                            ->label('Etiquetas')
                            ->rows(2)
                            ->placeholder('#FabLab #FabricaciónDigital #Bogotá'),
                    ]),

                Section::make('El material')
                    ->description('Las fotos y videos del banco de contenido que usa, y la pieza ya diseñada.')
                    ->columns(2)
                    ->schema([
                        Select::make('contenidos')
                            ->label('Del banco de contenido')
                            ->relationship(
                                'contenidos',
                                'title',
                                // Solo lo que se puede usar: con autorización y
                                // sin retirar.
                                fn ($query) => $query->whereNull('withdrawn_at')->whereNotNull('rights_accepted_at')->latest('id'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Contenido $c) => '#' . $c->id . ' · ' . $c->comoSeLlama()
                                . ($c->esVideo() ? ' (video)' : ''))
                            ->multiple()
                            ->searchable()
                            ->preload(),

                        FileUpload::make('piezas')
                            ->label('Piezas diseñadas')
                            ->disk('public')
                            ->visibility('public')
                            ->directory('redes')
                            ->multiple()
                            ->reorderable()
                            ->downloadable()
                            ->openable()
                            ->maxSize(51200)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'video/mp4', 'application/pdf'])
                            ->helperText('Lo que se va a subir a la red, tal cual: imagen, carrusel o video. Hasta 50 MB cada una.'),
                    ]),

                Section::make('Notas')
                    ->collapsed()
                    ->schema([
                        Textarea::make('notas')->hiddenLabel()->rows(3),
                    ]),

                Section::make('Ya publicada')
                    ->visible(fn (?Publicacion $record) => $record?->estado === 'publicada')
                    ->columns(2)
                    ->schema([
                        TextInput::make('enlace')->label('Enlace')->url(),
                        DateTimePicker::make('publicada_at')->label('Salió el')->seconds(false)
                            ->timezone(config('fabos.lab.timezone')),
                    ]),
            ]);
    }

    /**
     * Cuánto mide, y dónde no cabe.
     *
     * Instagram corta a los 2.200 caracteres y X a los 280: saberlo mientras
     * se escribe es más barato que descubrirlo al pegar.
     */
    private static function largo(?string $texto): HtmlString|string
    {
        $n = mb_strlen((string) $texto);

        if ($n === 0) {
            return 'Instagram admite hasta 2.200 caracteres; X, 280; LinkedIn, 3.000.';
        }

        $avisos = array_keys(array_filter([
            'X'         => $n > 280,
            'Instagram' => $n > 2200,
            'LinkedIn'  => $n > 3000,
        ]));

        return new HtmlString(e(number_format($n, 0, ',', '.') . ' caracteres')
            . ($avisos ? ' · <strong style="color:#C53030">no cabe en ' . e(implode(', ', $avisos)) . '</strong>' : ''));
    }
}
