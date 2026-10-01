<?php

namespace App\Filament\Resources\Circuitos;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Circuitos\Pages\CreateCircuito;
use App\Filament\Resources\Circuitos\Pages\EditCircuito;
use App\Filament\Resources\Circuitos\Pages\ListCircuitos;
use App\Models\Recorrido\Circuito;
use App\Models\Recorrido\Estacion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Los circuitos del recorrido gamificado: las estaciones, cada una con su
 * pista, su QR y su prueba.
 *
 * El circuito se arma una vez y se juega muchas: cada visita de un colegio es
 * una partida nueva sobre el mismo circuito, con los mismos QR pegados.
 */
class CircuitoResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Circuito::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $modelLabel = 'Circuito';

    protected static ?string $pluralModelLabel = 'Circuitos del recorrido';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Recorridos';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El circuito')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')->required()->maxLength(120),
                    Toggle::make('activo')->default(true)->inline(false)
                        ->helperText('Solo los activos se ofrecen al crear una partida.'),
                    Textarea::make('descripcion')->label('Descripción')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Estaciones')
                ->description('En el orden del recorrido. Cada equipo empieza en una distinta si la partida rota el orden; el QR de cada una se imprime desde la lista de circuitos.')
                ->schema([
                    Repeater::make('estaciones')
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('orden')
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->cloneable()
                        ->minItems(1)
                        ->addActionLabel('Agregar estación')
                        ->itemLabel(fn (array $state): string => $state['nombre'] ?? 'Estación nueva')
                        ->schema(self::estacion()),
                ]),
        ]);
    }

    /** @return array<int,\Filament\Schemas\Components\Component> */
    private static function estacion(): array
    {
        $imagen = fn (string $campo, string $etiqueta) => FileUpload::make($campo)
            ->label($etiqueta)
            ->image()
            ->disk('public')
            ->visibility('public')
            ->directory('recorridos')
            ->maxSize(4096);

        return [
            Grid::make(2)->schema([
                TextInput::make('nombre')
                    ->label('Nombre de la estación')
                    ->helperText('Para ustedes; los equipos no lo ven. Ej. «Láser».')
                    ->required()->maxLength(120),
                TextInput::make('lugar')
                    ->label('Dónde va el QR')
                    ->helperText('Para quien lo pega: «al lado de la cortadora, abajo».')
                    ->maxLength(200),
            ]),

            Grid::make(2)->schema([
                Textarea::make('pista')
                    ->label('La pista (la ve el líder en las gafas)')
                    ->rows(3)->required(),
                $imagen('pista_imagen', 'Imagen de la pista (opcional)'),
            ]),

            Grid::make(2)->schema([
                Textarea::make('pregunta')
                    ->label('La prueba (sale en el celular al escanear el QR)')
                    ->rows(3)->required(),
                $imagen('pregunta_imagen', 'Imagen de la prueba (opcional)'),
            ]),

            Select::make('tipo_respuesta')
                ->label('Cómo se responde')
                ->options(Estacion::TIPOS)
                ->default('texto')
                ->required()
                ->live(),

            TagsInput::make('datos_respuesta.aceptadas')
                ->label('Respuestas que valen')
                ->helperText('Escribe una y pulsa Enter; puedes poner varias. No importan mayúsculas, tildes ni espacios.')
                ->required()
                ->visible(fn (Get $get) => $get('tipo_respuesta') === 'texto'),

            Repeater::make('datos_respuesta.opciones')
                ->label('Opciones')
                ->schema([
                    TextInput::make('texto')->hiddenLabel()->required(),
                    Toggle::make('correcta')->inline(false),
                ])
                ->columns(2)
                ->minItems(2)
                ->defaultItems(3)
                ->addActionLabel('Agregar opción')
                ->visible(fn (Get $get) => $get('tipo_respuesta') === 'opcion'),

            Group::make([
                $imagen('datos_respuesta.imagen', 'Imagen sobre la que se ubica')->required(),
                Grid::make(3)->schema([
                    TextInput::make('datos_respuesta.x')->label('Horizontal (%)')->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('datos_respuesta.y')->label('Vertical (%)')->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('datos_respuesta.radio')->label('Margen (%)')->numeric()->minValue(1)->maxValue(50)->default(8)->required()
                        ->helperText('Qué tan cerca hay que tocar.'),
                ]),
                ViewField::make('marcador')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('filament.recorridos.marcador'),
            ])->visible(fn (Get $get) => $get('tipo_respuesta') === 'ubicar'),

            Repeater::make('datos_respuesta.pares')
                ->label('Parejas')
                ->helperText('En el celular la columna derecha sale mezclada.')
                ->schema([
                    TextInput::make('izquierda')->required(),
                    TextInput::make('derecha')->required(),
                ])
                ->columns(2)
                ->minItems(2)
                ->defaultItems(3)
                ->addActionLabel('Agregar pareja')
                ->visible(fn (Get $get) => $get('tipo_respuesta') === 'enlazar'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->searchable()->weight('bold'),
                TextColumn::make('estaciones_count')->counts('estaciones')->label('Estaciones'),
                TextColumn::make('partidas_count')->counts('partidas')->label('Partidas'),
                IconColumn::make('activo')->boolean(),
            ])
            ->recordActions([
                Action::make('qr')
                    ->label('Imprimir QR')
                    ->icon('heroicon-o-qr-code')
                    ->url(fn (Circuito $r) => route('recorridos.qr', $r))
                    ->openUrlInNewTab(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCircuitos::route('/'),
            'create' => CreateCircuito::route('/crear'),
            'edit'   => EditCircuito::route('/{record}/editar'),
        ];
    }
}
