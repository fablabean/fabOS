<?php

namespace App\Filament\Resources\Dispositivos;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Dispositivos\Pages\CreateDispositivo;
use App\Filament\Resources\Dispositivos\Pages\EditDispositivo;
use App\Filament\Resources\Dispositivos\Pages\ListDispositivos;
use App\Filament\Resources\Dispositivos\RelationManagers\TurnosRelationManager;
use App\Models\Iot\Dispositivo;
use App\Services\Iot\Turnos;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Dispositivos IoT: lo que se enciende por turnos desde el portal.
 *
 * Aquí se da de alta el dispositivo y se genera la clave de su Raspberry. El
 * registro que lo enciende se pone en una página, con el bloque «Activar un
 * dispositivo».
 */
class DispositivoResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Dispositivo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $modelLabel = 'Dispositivo';

    protected static ?string $pluralModelLabel = 'Dispositivos';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Dispositivos IoT';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El dispositivo')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')
                        ->required()->maxLength(120)
                        ->placeholder('Consola de juego'),
                    Toggle::make('activo')
                        ->default(true)->inline(false)
                        ->helperText('Apagado, nadie puede activarlo y se queda sin corriente.'),
                    TextInput::make('minutos_turno')
                        ->label('Minutos por turno')
                        ->helperText('Lo que juega quien se registra o activa su turno.')
                        ->numeric()->minValue(1)->maxValue(240)->default(15)->required(),
                    TextInput::make('minutos_por_fabcoin')
                        ->label('Minutos por ' . config('fabos.currency.name'))
                        ->helperText('Lo que compra cada ' . config('fabos.currency.name') . ' al reclamar tiempo.')
                        ->numeric()->minValue(1)->maxValue(240)->default(5)->required(),
                    Textarea::make('descripcion')->label('Notas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                TextColumn::make('nombre')->weight('bold')->searchable(),
                TextColumn::make('conexion')
                    ->label('Conexión')
                    ->badge()
                    ->state(fn (Dispositivo $d) => $d->conectado() ? 'Conectado' : ($d->visto_at ? 'Sin señal' : 'Nunca se conectó'))
                    ->color(fn (string $state) => $state === 'Conectado' ? 'success' : 'danger')
                    ->description(fn (Dispositivo $d) => $d->visto_at ? 'visto ' . $d->visto_at->locale('es')->diffForHumans() : null),
                TextColumn::make('ahora')
                    ->label('Ahora')
                    ->state(function (Dispositivo $d) {
                        $t = app(Turnos::class)->actual($d);

                        return $t ? 'Juega ' . $t->nombre . ' · ' . max(1, (int) ceil(now()->diffInSeconds($t->termina_at) / 60)) . ' min' : 'Apagado';
                    }),
                TextColumn::make('fila')
                    ->label('En fila')
                    ->state(fn (Dispositivo $d) => app(Turnos::class)->fila($d)->count()),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [TurnosRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListDispositivos::route('/'),
            'create' => CreateDispositivo::route('/crear'),
            'edit'   => EditDispositivo::route('/{record}/editar'),
        ];
    }
}
