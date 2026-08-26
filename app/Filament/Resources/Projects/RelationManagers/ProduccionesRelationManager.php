<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Componentes\CampoDeEvidencia;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Supply;
use App\Services\Projects\ProduccionService;
use App\Services\Projects\ProjectException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Producir con una máquina para el proyecto.
 *
 * Es una reserva, con otro sentido pero el mismo efecto: mientras la pieza se
 * imprime, la impresora **no aparece libre para nadie**. Eso no hay que
 * programarlo aquí —lo garantiza la misma restricción de PostgreSQL que impide
 * que dos reservas choquen—, y es justo la razón de que una producción viva en
 * la tabla de reservas y no en una propia.
 */
class ProduccionesRelationManager extends RelationManager
{
    protected static string $relationship = 'producciones';

    protected static ?string $title = 'Producción';

    protected static ?string $modelLabel = 'producción';

    protected static ?string $pluralModelLabel = 'producciones';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('reservable_id')
                    ->label('Con qué equipo')
                    ->required()
                    ->searchable()
                    ->columnSpanFull()
                    ->options(fn () => Asset::query()
                        ->where('status', '!=', 'baja')
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->helperText('Si no estaba declarado en el proyecto, se añade solo.'),

                DateTimePicker::make('starts_at')
                    ->label('Empieza')
                    ->seconds(false)
                    ->required()
                    ->default(now()),

                DateTimePicker::make('ends_at')
                    ->label('Termina')
                    ->seconds(false)
                    ->required()
                    ->helperText('Una pieza de seis horas ocupa seis horas, aunque nadie esté delante.'),

                TextInput::make('purpose')
                    ->label('Qué se produce')
                    ->columnSpanFull()
                    ->helperText('«Carcasa v3, 4 piezas». Dentro de un mes es lo único que explica por qué la máquina estuvo ocupada.'),
            ]);
    }

    public function table(Table $table): Table
    {
        $horas = fn (Reservation $r) => number_format(
            $r->starts_at->diffInMinutes($r->ends_at) / 60, 1, ',', '.',
        ) . ' h';

        return $table
            ->recordTitleAttribute('purpose')
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('equipo')
                    ->label('Equipo')
                    ->weight('medium')
                    ->state(fn (Reservation $r) => $r->reservable?->name ?? 'Equipo eliminado'),

                TextColumn::make('starts_at')
                    ->label('Empieza')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Termina')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone')),

                TextColumn::make('duracion')->label('Dura')->state($horas)->alignEnd(),

                TextColumn::make('purpose')->label('Qué se produce')->wrap()->placeholder('—'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Reservation::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'completada' => 'success',
                        'en_curso'   => 'info',
                        'cancelada'  => 'danger',
                        default      => 'gray',
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Programar producción')
                    ->modalHeading('Programar producción')
                    ->modalDescription('Mientras dure, el equipo no aparecerá libre para nadie más.')
                    ->using(function (array $data): Reservation {
                        try {
                            return app(ProduccionService::class)->programar(
                                Asset::findOrFail($data['reservable_id']),
                                auth()->user(),
                                \Illuminate\Support\Carbon::parse($data['starts_at'], config('fabos.lab.timezone')),
                                \Illuminate\Support\Carbon::parse($data['ends_at'], config('fabos.lab.timezone')),
                                $this->getOwnerRecord(),
                                $data['purpose'] ?? null,
                            );
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo programar')->body($e->getMessage())->send();

                            throw new \Filament\Support\Exceptions\Halt;
                        }
                    }),
            ])
            ->recordActions([
                // Los archivos definitivos: el .stl y el .gcode que salieron de
                // la maquina. Son lo unico que permite repetir el trabajo
                // dentro de seis meses sin volver a empezar.
                EditAction::make()
                    ->label('Archivos')
                    ->icon('heroicon-o-paper-clip')
                    ->modalHeading('Archivos y evidencia de la producción')
                    ->schema([CampoDeEvidencia::repetidor(
                        'Archivos y evidencia',
                        'El .stl, el .gcode, la foto de la pieza. Es lo que permite repetir el trabajo dentro de seis meses sin volver a empezar.',
                        'producciones',
                    )]),

                Action::make('terminar')
                    ->label('Terminar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Reservation $r) => in_array($r->status, ['confirmada', 'en_curso'], true))
                    ->modalHeading('Cerrar la producción')
                    ->modalDescription('El equipo queda libre. El costo se ajusta a lo que realmente duró, y se le suma el material que de verdad se gastó.')
                    ->modalSubmitActionLabel('Cerrar')
                    ->schema([
                        // El material se anota al cerrar y no al programar: se
                        // consume cuando la maquina corre. Descontarlo por
                        // adelantado dejaria el inventario mintiendo durante
                        // las seis horas que dura la impresion.
                        Repeater::make('materiales')
                            ->label('Material gastado')
                            ->addActionLabel('Añadir material')
                            ->defaultItems(0)
                            ->columns(2)
                            ->helperText('Los gramos de filamento, los mililitros de resina. Sale del inventario y entra al costo.')
                            ->schema([
                                Select::make('supply_id')
                                    ->label('Qué')
                                    ->required()
                                    ->searchable()
                                    ->options(fn () => Supply::where('is_active', true)
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn (Supply $i) => [
                                            $i->id => $i->name . ($i->unit ? " ({$i->unit})" : ''),
                                        ])),

                                TextInput::make('cantidad')
                                    ->label('Cuánto')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.001),
                            ]),
                    ])
                    ->action(function (Reservation $r, array $data) {
                        $materiales = collect($data['materiales'] ?? [])
                            ->filter(fn ($m) => filled($m['supply_id'] ?? null))
                            ->mapWithKeys(fn ($m) => [(int) $m['supply_id'] => (float) $m['cantidad']])
                            ->all();

                        try {
                            app(ProduccionService::class)->terminar($r, null, $materiales);
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo cerrar')->body($e->getMessage())->send();

                            throw new \Filament\Support\Exceptions\Halt;
                        }

                        Notification::make()->success()->title('Producción cerrada')->send();
                    }),

                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Reservation $r) => in_array($r->status, ['confirmada', 'en_curso'], true))
                    ->schema([
                        TextInput::make('motivo')
                            ->label('Qué pasó')
                            ->required()
                            ->helperText('«Se cayó la impresión», «cambió el plan». Sirve para la próxima estimación.'),
                    ])
                    ->action(function (Reservation $r, array $data) {
                        app(ProduccionService::class)->cancelar($r, $data['motivo']);

                        Notification::make()->success()->title('Producción cancelada')->body('El equipo queda libre.')->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
