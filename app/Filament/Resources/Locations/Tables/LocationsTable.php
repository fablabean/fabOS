<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
use App\Models\Space;
use App\Services\Inventory\UbicacionesEnSerie;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Agrupadas por ESPACIO, no por area.
             *
             * Un mueble no pertenece a un area: esta en una sala. Y ni siquiera
             * directamente —`space_id` solo se declara en la raiz del arbol y
             * lo demas lo hereda— asi que el titulo del grupo se resuelve por
             * el modelo, subiendo, y no con un `group by` sobre una columna que
             * no existe.
             */
            ->defaultGroup(
                Group::make('espacio')
                    ->label('Espacio')
                    ->collapsible()
                    ->getTitleFromRecordUsing(fn (Location $record) => $record->espacio()?->name ?? 'Sin espacio asignado')
                    ->getKeyFromRecordUsing(fn (Location $record) => (string) ($record->espacio()?->id ?? 0))
                    /*
                     * Para que cada grupo salga junto hay que ORDENAR por el
                     * espacio efectivo, y eso si toca resolverlo en SQL. Se
                     * mira el propio y el de la madre, que cubre el arbol que
                     * hay. Mas hondo, el titulo del grupo sigue siendo correcto
                     * -lo calcula el modelo- y lo unico que podria pasar es que
                     * un espacio saliera en dos tramos.
                     */
                    /*
                     * Como se acota la consulta a UN grupo, que es lo que pasa
                     * al plegar y desplegar. Sin esto Filament intenta
                     * `where espacio = 13` sobre una columna que no existe y
                     * la pantalla revienta al abrir un grupo.
                     *
                     * Los parametros se llaman `query` y `key` a proposito:
                     * Filament los inyecta por NOMBRE.
                     */
                    ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key) => (int) $key
                        ? $query->enElEspacio((int) $key)
                        : $query->sinEspacio())
                    ->orderQueryUsing(fn (Builder $query) => $query->orderByRaw(
                        'coalesce(locations.space_id, (select p.space_id from locations p where p.id = locations.parent_id)) nulls last',
                    )),
            )
            // Plegados, salvo cuando ya se filtro a un espacio solo: quien
            // pulso la tarjeta ya dijo lo que quiere.
            ->collapsedGroupsByDefault(
                fn (): bool => blank(request()->input('filters.espacio.value')),
            )
            /*
             * Y dentro del grupo, cada arbol junto y la madre primero. Una
             * gaveta suelta entre otros muebles no dice de donde sale.
             */
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('coalesce(parent_id, id)')
                ->orderByRaw('parent_id is null desc')
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Ubicación')
                    ->searchable()
                    ->weight('medium')
                    // Sangrada y con la flecha si cuelga de otra: se ve de un
                    // vistazo que la gaveta va dentro del estante de arriba.
                    ->formatStateUsing(fn (Location $record, $state) => ($record->parent_id ? '↳ ' : '') . $state),

                TextColumn::make('parent.name')->label('Dentro de')->placeholder('raíz')->searchable(),
                TextColumn::make('assets_count')->label('Equipos aquí')->counts('assets')->badge()->color('gray'),
                TextColumn::make('children_count')->label('Sub-ubicaciones')->counts('children')->badge()->color('gray'),

                // El efectivo, no el declarado: lo que importa es donde esta
                // esa gaveta, no si el dato lo puso ella o su estante.
                TextColumn::make('espacio')
                    ->label('Espacio')
                    ->state(fn (\App\Models\Location $record) => $record->espacio()?->name)
                    ->placeholder('sin asignar')
                    ->description(fn (\App\Models\Location $record) => $record->space_id ? null : 'heredado')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->filters([
                /*
                 * Por espacio, con la regla del modelo: lo que lo declara y
                 * todo lo que cuelga. Es a donde llevan las tarjetas de arriba,
                 * y por eso la regla vive en un solo sitio.
                 */
                SelectFilter::make('espacio')
                    ->label('Espacio')
                    ->options(fn () => Space::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->enElEspacio((int) $data['value'])
                        : $query),
            ])
            ->headerActions([self::crearEnSerie()])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /**
     * Dieciseis gavetas de un rack, sin teclearlas una por una.
     *
     * Teclear nombres iguales salvo el numero es trabajo de copiadora, e invita
     * a errores que luego cuestan: una gaveta saltada, dos con el mismo nombre.
     */
    private static function crearEnSerie(): Action
    {
        return Action::make('enSerie')
            ->label('Crear varias')
            ->icon('heroicon-o-squares-plus')
            ->modalHeading('Crear varias ubicaciones de una vez')
            ->modalDescription('Para un rack con gavetas, una mesa con cajones, un mueble con casillas.')
            ->modalSubmitActionLabel('Crear')
            ->schema([
                Select::make('parent_id')
                    ->label('Dentro de')
                    ->options(fn () => Location::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('El mueble que las contiene. Heredan su espacio.'),

                TextInput::make('base')
                    ->label('Nombre')
                    ->placeholder('Gaveta')
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(80),

                TextInput::make('cantidad')
                    ->label('Cuántas')
                    ->numeric()
                    ->default(16)
                    ->minValue(1)
                    ->maxValue(UbicacionesEnSerie::MAXIMO)
                    ->required()
                    ->live(onBlur: true),

                TextInput::make('desde')
                    ->label('Empezando en')
                    ->numeric()
                    ->default(1)
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true),

                Toggle::make('ceros')
                    ->label('Rellenar con ceros')
                    ->default(true)
                    ->live()
                    ->helperText('Sin esto, ordenadas por nombre «Gaveta 10» va antes que «Gaveta 2».'),

                Placeholder::make('vista')
                    ->label('Van a quedar así')
                    ->content(fn ($get) => app(UbicacionesEnSerie::class)->vistaPrevia(
                        (string) $get('base'),
                        (int) $get('cantidad'),
                        (int) $get('desde'),
                        (bool) $get('ceros'),
                    ) ?: '—'),
            ])
            ->action(function (array $data) {
                $r = app(UbicacionesEnSerie::class)->crear(
                    Location::findOrFail($data['parent_id']),
                    (string) $data['base'],
                    (int) $data['cantidad'],
                    (int) $data['desde'],
                    (bool) ($data['ceros'] ?? true),
                );

                if ($r['creadas'] !== []) {
                    Notification::make()
                        ->title(count($r['creadas']) . ' ubicaciones creadas')
                        ->body(implode(' · ', array_slice($r['creadas'], 0, 6))
                            . (count($r['creadas']) > 6 ? ' …' : ''))
                        ->success()
                        ->send();
                }

                if ($r['omitidas'] !== []) {
                    Notification::make()
                        ->title('Algunas ya existían')
                        ->body('No se repitieron: ' . implode(' · ', $r['omitidas'])
                            . '. Dos con el mismo nombre no se podrían distinguir.')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }
}
