<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
use App\Models\Space;
use App\Services\Inventory\ConteoPorUbicacion;
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
             * Y todas cargadas de una, como el catalogo de activos.
             *
             * La rejilla agrupa lo que hay en la PAGINA: con veinticinco de
             * treinta y ocho, plegada salian cuatro salas y las otras dos
             * aparecian al pasar pagina. Una lista que parece completa y no lo
             * esta es peor que una larga.
             *
             * Se puede porque son unas decenas de muebles y van plegados. Si
             * el arbol llegara a miles, aqui es donde hay que volver.
             */
            ->paginated([50, 100, 'all'])
            ->defaultPaginationPageOption('all')
            /*
             * Y dentro del grupo, cada arbol junto y la madre primero. Una
             * gaveta suelta entre otros muebles no dice de donde sale.
             */
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('coalesce(parent_id, id)')
                ->orderByRaw('parent_id is null desc')
                ->orderBy('name'))
            // Las madres cargadas de una: saber a que nivel esta cada mueble
            // es subir por el arbol, y sin esto seria una consulta por fila.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent.parent.parent'))
            ->columns([
                TextColumn::make('name')
                    ->label('Ubicación')
                    ->searchable()
                    ->weight('medium')
                    /*
                     * Cada nivel, su color y su sangria.
                     *
                     * El color se pide por NOMBRE y no como estilo escrito a
                     * mano, para que el panel lo resuelva tambien en modo
                     * oscuro; un color fijo se ve bien en claro y se pierde en
                     * el otro.
                     *
                     * Y la sangria acompana al color: quien no distingue estos
                     * tonos sigue viendo la jerarquia por la posicion y por la
                     * flecha. El color ayuda, no es lo unico que lo dice.
                     */
                    ->color(fn (Location $record) => match (min($record->nivel(), 3)) {
                        0       => null,
                        1       => 'primary',
                        2       => 'info',
                        default => 'gray',
                    })
                    ->extraAttributes(fn (Location $record) => $record->nivel()
                        ? ['style' => 'padding-left:' . min($record->nivel(), 6) * 1.15 . 'rem']
                        : [])
                    ->formatStateUsing(fn (Location $record, $state) => ($record->parent_id ? '↳ ' : '') . $state),

                /*
                 * Activos e insumos, por separado.
                 *
                 * En una gaveta caben dos cosas que no se mezclan: un
                 * multimetro es un ACTIVO -se ficha, se reserva, tiene hoja de
                 * vida- y un carrete de filamento es un INSUMO, que se gasta.
                 * Sumarlos diria «23» sin decir si son veintitres aparatos o
                 * veintitres carretes, y son dos preguntas distintas.
                 *
                 * Los dos totales cuentan lo que cuelga, y debajo va lo que hay
                 * ahi mismo: es el dato que se pierde al sumar, y el que hace
                 * falta para ir a cogerlo.
                 */
                TextColumn::make('activos')
                    ->label('Activos')
                    ->state(fn (Location $record) => app(ConteoPorUbicacion::class)->activosConLoQueCuelga($record->id))
                    ->description(fn (Location $record) => self::deDondeSalen(
                        app(ConteoPorUbicacion::class)->activosAqui($record->id),
                        app(ConteoPorUbicacion::class)->activosConLoQueCuelga($record->id),
                    ))
                    ->badge()
                    ->color(fn ($state) => $state ? 'gray' : null),

                /*
                 * El numero son REFERENCIAS distintas -«dos materiales»- y
                 * debajo va cuanto hay de cada cosa.
                 *
                 * Por unidad y nunca en un solo numero: en el laboratorio
                 * conviven laminas, kilos, metros y mililitros, y sumar
                 * «35 + 2» daria treinta y siete de nada.
                 *
                 * Donde esta -aqui o en lo que cuelga- se pasa al globo: en
                 * dos lineas no caben las dos cosas, y la cantidad es lo que
                 * se viene a mirar.
                 */
                TextColumn::make('insumos')
                    ->label('Insumos')
                    ->state(fn (Location $record) => app(ConteoPorUbicacion::class)->insumosConLoQueCuelga($record->id))
                    ->description(fn (Location $record) => self::cuantoHay(
                        app(ConteoPorUbicacion::class)->cantidadesConLoQueCuelga($record->id),
                    ))
                    ->tooltip(fn (Location $record) => self::deDondeSalen(
                        app(ConteoPorUbicacion::class)->insumosAqui($record->id),
                        app(ConteoPorUbicacion::class)->insumosConLoQueCuelga($record->id),
                    ))
                    ->badge()
                    ->color(fn ($state) => $state ? 'gray' : null),
                TextColumn::make('children_count')->label('Sub-ubicaciones')->counts('children')->badge()->color('gray'),

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
            ->recordActions([EditAction::make()->iconButton()->tooltip('Editar')])
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

    /**
     * Si el total no esta todo aqui, decir donde esta el resto.
     *
     * Un total a secas esconde algo que si hace falta: si el rack dice veinte,
     * ¿voy al rack o a una gaveta? Cuando coinciden no se dice nada, que
     * repetirlo en cada fila es ruido.
     */
    private static function deDondeSalen(int $aqui, int $total): ?string
    {
        if ($total === 0 || $aqui === $total) {
            return null;
        }

        return $aqui === 0
            ? 'todos en lo que cuelga'
            : $aqui . ' aquí mismo';
    }

    /**
     * «35 laminas», o «30 laminas · 2 kg» cuando hay de varias clases.
     *
     * Cada unidad por su lado: sumarlas daria un numero que no significa nada.
     * Y si hay muchas se corta, que la celda de una tabla no es un inventario.
     *
     * @param  array<string,float>  $porUnidad
     */
    private static function cuantoHay(array $porUnidad): ?string
    {
        if ($porUnidad === []) {
            return null;
        }

        $partes = collect($porUnidad)
            ->map(fn (float $cuanto, string $unidad) => self::sinCerosDeMas($cuanto) . ' ' . $unidad)
            ->values();

        return $partes->count() > 3
            ? $partes->take(2)->implode(' · ') . ' y ' . ($partes->count() - 2) . ' mas'
            : $partes->implode(' · ');
    }

    /** 35,00 se lee peor que 35; 2,50 tiene que seguir diciendo 2,5. */
    private static function sinCerosDeMas(float $cuanto): string
    {
        return rtrim(rtrim(number_format($cuanto, 2, ',', '.'), '0'), ',');
    }
}
