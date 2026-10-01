<?php

namespace App\Filament\Resources\Partidas;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Partidas\Pages\CreatePartida;
use App\Filament\Resources\Partidas\Pages\EditPartida;
use App\Filament\Resources\Partidas\Pages\ListPartidas;
use App\Models\Recorrido\Circuito;
use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Partida;
use App\Models\Reservation;
use App\Models\Space;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Las partidas del recorrido gamificado: cada vez que un grupo lo juega.
 *
 * Se arma antes de que llegue el grupo —o en la puerta, con los nombres que
 * vayan dando—, se inicia para todos a la vez y se sigue en el tablero.
 */
class PartidaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Partida::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $modelLabel = 'Partida';

    protected static ?string $pluralModelLabel = 'Partidas';

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Recorridos';
    }

    public static function getNavigationBadge(): ?string
    {
        $vivas = Partida::where('estado', 'en_curso')->count();

        return $vivas ? (string) $vivas : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La partida')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')
                        ->required()->maxLength(160)
                        ->placeholder('Colegio San José · 10.º'),
                    Select::make('circuito_id')
                        ->label('Circuito')
                        ->options(fn () => Circuito::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id'))
                        ->required()
                        ->disabled(fn (?Partida $record) => $record && $record->estado !== 'preparada'),
                    Select::make('reservation_id')
                        ->label('Reserva del recorrido')
                        ->helperText('Opcional: la reserva del laboratorio a la que corresponde. Si es un evento sin reserva, déjalo vacío.')
                        ->options(fn () => self::reservasDeRecorrido())
                        ->searchable(),
                    TextInput::make('penalizacion_segundos')
                        ->label('Penalización por fallo (segundos)')
                        ->numeric()->minValue(0)->maxValue(3600)->default(30)->required(),
                    Toggle::make('rotar_orden')
                        ->label('Cada equipo empieza en una estación distinta')
                        ->helperText('Para que no se junten todos frente al mismo QR.')
                        ->default(true)
                        ->disabled(fn (?Partida $record) => $record && $record->estado !== 'preparada'),
                ]),

            Section::make('Equipos')
                ->description('Al guardar, cada equipo recibe un código: con él entra su celular y se emparejan sus gafas.')
                ->schema([
                    Repeater::make('equipos')
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('posicion')
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->addActionLabel('Agregar equipo')
                        ->itemLabel(fn (array $state): string => ($state['nombre'] ?? 'Equipo nuevo')
                            . (filled($state['codigo'] ?? null) ? ' · código ' . $state['codigo'] : ''))
                        ->columns(3)
                        ->schema([
                            TextInput::make('nombre')->required()->maxLength(80),
                            ColorPicker::make('color')
                                ->default(fn () => Equipo::COLORES[array_rand(Equipo::COLORES)])
                                ->required(),
                            TextInput::make('codigo')
                                ->label('Código')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('Se genera al guardar'),
                            Repeater::make('integrantes')
                                ->relationship()
                                ->simple(TextInput::make('nombre')->required()->maxLength(80))
                                ->addActionLabel('Agregar integrante')
                                ->defaultItems(0)
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    /** Las reservas de espacios de aquí a dos meses: entre ellas, la del recorrido. */
    private static function reservasDeRecorrido(): array
    {
        $tz = config('fabos.lab.timezone');

        return Reservation::with(['reservable', 'user'])
            ->where('reservable_type', Space::class)
            ->whereNull('parent_reservation_id')
            ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
            ->where('starts_at', '>=', now()->subDays(2))
            ->where('starts_at', '<=', now()->addMonths(2))
            ->orderBy('starts_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Reservation $r) => [$r->id => $r->starts_at->timezone($tz)->format('d/m H:i')
                . ' · ' . ($r->reservable?->name ?? 'Espacio')
                . ' · ' . ($r->user?->name ?? '')])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('nombre')->searchable()->weight('bold'),
                TextColumn::make('circuito.nombre')->label('Circuito'),
                TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Partida::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'en_curso' => 'success', 'terminada' => 'gray', default => 'warning',
                    }),
                TextColumn::make('equipos_count')->counts('equipos')->label('Equipos'),
                TextColumn::make('iniciada_at')->label('Inició')->dateTime('d/m/Y H:i', config('fabos.lab.timezone'))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('tablero')
                    ->label('Tablero')
                    ->icon('heroicon-o-presentation-chart-bar')
                    ->url(fn (Partida $r) => $r->urlDelTablero())
                    ->openUrlInNewTab(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPartidas::route('/'),
            'create' => CreatePartida::route('/crear'),
            'edit'   => EditPartida::route('/{record}/editar'),
        ];
    }
}
