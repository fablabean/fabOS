<?php

namespace App\Filament\Resources\Pantallas;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Pantallas\Pages\CreatePantalla;
use App\Filament\Resources\Pantallas\Pages\EditPantalla;
use App\Filament\Resources\Pantallas\Pages\ListPantallas;
use App\Filament\Resources\Pantallas\RelationManagers\ActividadesRelationManager;
use App\Filament\Resources\Pantallas\RelationManagers\NovedadesRelationManager;
use App\Models\Iot\Pantalla;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las pantallas e-paper de los salones (IoT).
 *
 * Cada una está en un salón y muestra su agenda. Aquí se registra, se le
 * genera la clave para el ESP32, se carga la agenda (a mano o desde un CSV o
 * Excel) y se ve cómo va su batería, para saber cuál hay que cargar.
 */
class PantallaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Pantalla::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    protected static ?string $modelLabel = 'Pantalla de salón';

    protected static ?string $pluralModelLabel = 'Pantallas de salones';

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Dispositivos IoT';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La pantalla')
                ->columns(2)
                ->schema([
                    TextInput::make('salon')
                        ->label('Número del salón')
                        ->required()->maxLength(40)
                        ->placeholder('305')
                        ->helperText('Es lo que va arriba en la pantalla, y con lo que se reconoce en el CSV de la agenda.'),
                    TextInput::make('nombre')
                        ->label('Nombre interno')
                        ->required()->maxLength(120)
                        ->placeholder('Pantalla 305 · puerta'),
                    TextInput::make('ubicacion')
                        ->label('Dónde está')
                        ->maxLength(255)
                        ->placeholder('Edificio Fundadores, piso 3'),
                    Toggle::make('activa')
                        ->default(true)->inline(false)
                        ->helperText('Apagada, la pantalla no recibe nada y su QR deja de abrir la página del salón.'),
                    TextInput::make('correos_novedades')
                        ->label('Correos para las novedades')
                        ->helperText('Separados por coma. Les llega lo que se reporte desde el QR. Vacío: el correo general (PANTALLAS_CORREO_NOVEDADES).')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),
            Section::make('Así se ve ahora')
                ->description('Lo mismo que muestra la pantalla del salón la próxima vez que se actualice.')
                ->visibleOn('edit')
                ->schema([View::make('filament.pantallas.vista-previa')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['novedades as novedades_abiertas' => fn ($n) => $n->whereNull('resuelta_at')]))
            ->defaultSort('salon')
            ->columns([
                TextColumn::make('salon')->label('Salón')->weight('bold')->searchable()->sortable()
                    ->description(fn (Pantalla $p) => $p->ubicacion),
                TextColumn::make('bateria')
                    ->label('Batería')
                    ->badge()
                    ->state(fn (Pantalla $p) => match (true) {
                        $p->bateria_pct === null => 'Sin dato',
                        (bool) $p->cargando => $p->bateria_pct . ' % · cargando',
                        $p->bateriaBaja() => $p->bateria_pct . ' % · cargar',
                        default => $p->bateria_pct . ' %',
                    })
                    ->color(fn (Pantalla $p) => match (true) {
                        $p->bateria_pct === null => 'gray',
                        (bool) $p->cargando => 'info',
                        $p->bateriaBaja() => 'danger',
                        $p->bateria_pct < 40 => 'warning',
                        default => 'success',
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('bateria_pct', $direction)),
                TextColumn::make('senal')
                    ->label('Última señal')
                    ->badge()
                    ->state(fn (Pantalla $p) => match (true) {
                        ! $p->visto_at => 'Nunca se conectó',
                        $p->sinSenal() => 'Sin señal',
                        default => 'Al día',
                    })
                    ->color(fn (string $state) => $state === 'Al día' ? 'success' : 'danger')
                    ->description(fn (Pantalla $p) => $p->visto_at
                        ? $p->visto_at->locale('es')->diffForHumans() . ($p->motivo === 'boton' ? ' · con el botón' : '')
                        : null),
                TextColumn::make('ahora')
                    ->label('Ahora')
                    ->state(fn (Pantalla $p) => $p->actividadEn(now())?->titulo ?? 'Libre')
                    ->limit(40),
                TextColumn::make('novedades_abiertas')
                    ->label('Novedades')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),
                TextColumn::make('firmware')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rssi')->label('Wifi (dBm)')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ActividadesRelationManager::class, NovedadesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPantallas::route('/'),
            'create' => CreatePantalla::route('/crear'),
            'edit' => EditPantalla::route('/{record}/editar'),
        ];
    }
}
