<?php

namespace App\Filament\Resources\Supplies\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Qué es')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required(),

                        Select::make('kind')
                            ->label('Qué es')
                            ->options(\App\Models\Supply::TIPOS)
                            ->default('insumo')
                            ->required()
                            ->helperText('Comparten inventario: los dos se cuentan y se reponen. Cambia dónde salen en la tienda.'),

                        TextInput::make('sku')
                            ->label('Código interno')
                            ->unique(ignoreRecord: true)
                            ->helperText('Opcional. Sirve para buscarlo rápido en mostrador.'),

                        TextInput::make('unit')
                            ->label('Unidad')
                            ->required()
                            ->default('unidad')
                            ->placeholder('g, ml, kg, hoja, m, unidad'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        Select::make('category_id')
                            ->label('Categoría')
                            ->options(fn () => \App\Models\SupplyCategory::paraElegir())
                            ->searchable()
                            ->preload()
                            ->placeholder('Sin clasificar')
                            // Se puede crear al vuelo: obligar a salir de esta
                            // pantalla para crear «MDF» hace que se elija
                            // «Varios» y ahi acabe todo.
                            ->createOptionForm([
                                TextInput::make('name')->label('Nombre')->required(),
                                Select::make('parent_id')
                                    ->label('Dentro de')
                                    ->options(fn () => \App\Models\SupplyCategory::paraElegir())
                                    ->searchable()
                                    ->placeholder('Es una categoría de primer nivel'),
                            ])
                            ->createOptionUsing(fn (array $data) => \App\Models\SupplyCategory::create($data)->id)
                            ->helperText('«Madera › MDF». Se anidan a cualquier profundidad.'),

                        Select::make('location_id')
                            ->label('Dónde está')
                            ->relationship('location', 'name')
                            ->searchable(),

                        Toggle::make('is_active')->label('Activo')->default(true),

                        Textarea::make('description')->label('Descripción')->columnSpanFull(),
                    ]),

                Section::make('En la tienda')
                    ->description('Lo que se enseña a quien entra sin ser del laboratorio. No todo lo que hay se vende: la acetona y las brocas no.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_public')
                            ->label('Se ve en la tienda')
                            ->helperText('Necesita además tener precio: sin él no aparece, aunque esté marcado.'),

                        /*
                         * El precio de venta, donde se decide vender.
                         *
                         * No es una columna del insumo: escribe la **tarifa**,
                         * que es lo que ya leen el carrito, la venta de
                         * mostrador y el costeo. Un precio guardado aparte
                         * seria un segundo numero para lo mismo.
                         *
                         * Y no es el costo. El costo dice lo que nos costo
                         * traerlo; el precio, lo que cobramos. Confundirlos
                         * hace que una pieza impresa se venda por el precio del
                         * plastico que lleva.
                         */
                        TextInput::make('precio_venta')
                            ->label('Precio de venta al público')
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('Lo que paga quien compra. Si se deja vacío, la tienda estima un precio con el costo y el margen, y avisa de que lo hizo.'),

                        FileUpload::make('photo_path')
                            ->label('Foto')
                            ->image()
                            // Disco publico EXPLICITO: se enseña en la tienda,
                            // que se mira sin haber entrado.
                            ->disk('public')
                            ->visibility('public')
                            ->directory('tienda')
                            ->maxSize(20480)
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(1400)
                            ->imageResizeTargetHeight(1400)
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(\App\Services\Media\OptimizadorDeImagen::class)
                                    ->guardar($file, 'tienda')
                            ),

                        Textarea::make('public_description')
                            ->label('Cómo se explica en la tienda')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Para quien no sabe qué es. La descripción de arriba es la interna.'),
                    ]),

                Section::make('Existencias')
                    ->description('La existencia no se edita aquí: se mueve con entradas, salidas y ajustes, y cada movimiento queda con su motivo.')
                    ->columns(2)
                    ->schema([
                        /*
                         * Solo al crear.
                         *
                         * Lo que hay hoy en el estante existe antes que su
                         * ficha, y obligar a crearla vacía para luego mover la
                         * existencia son dos pasos donde cabe uno. Pero **no
                         * se escribe en `stock`**: entra como un movimiento de
                         * entrada como cualquier otro, con su motivo y su
                         * autor. Al editar no aparece, porque a partir de ahí
                         * cambiar la existencia a mano es justo lo que rompe
                         * la trazabilidad.
                         */
                        TextInput::make('existencia_inicial')
                            ->label('Existencia inicial')
                            ->numeric()
                            ->minValue(0)
                            // Se deshidrata: hace falta que llegue al array de
                            // datos para poder convertirla en movimiento. La
                            // pagina la saca antes de guardar el insumo.
                            ->visibleOn('create')
                            ->helperText('Lo que ya hay en el estante. Queda anotado como un movimiento de entrada, no como un número suelto.'),

                        TextInput::make('stock')
                            ->label('Existencia actual')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit')
                            ->helperText('Usa el botón «Mover existencia» en el listado.'),

                        TextInput::make('reorder_point')
                            ->label('Mínimo · punto de reposición')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Por debajo de esto, el insumo aparece en el carrito de reposición. Dice CUÁNDO comprar.'),

                        TextInput::make('max_stock')
                            ->label('Máximo · hasta cuánto reponer')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Dice CUÁNTO comprar: se pide la diferencia hasta aquí. Sin esto, quien repone sabe que hace falta pero no cuánto.')
                            ->rule(fn (\Filament\Schemas\Components\Utilities\Get $get) => function (string $atributo, $valor, $falla) use ($get) {
                                if (filled($valor) && filled($get('reorder_point')) && (float) $valor < (float) $get('reorder_point')) {
                                    $falla('El máximo no puede ser menor que el mínimo.');
                                }
                            }),

                        TextInput::make('last_cost')
                            ->label('Último costo por unidad')
                            ->numeric()
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('Se actualiza solo al recibir una compra.'),
                    ]),
            ]);
    }
}
