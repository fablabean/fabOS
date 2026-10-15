<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestAdjustment;
use App\Models\Supply;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * El carrito.
 *
 * Las líneas se editan aquí mismo con un repetidor, no en otra pantalla: quien
 * arma una compra piensa en la lista completa, no en un formulario por ítem.
 */
class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Un bloque debajo de otro, a todo el ancho. Repartidos en dos
            // columnas, la lista de lo que se pide —siete campos por fila—
            // quedaba apretada en media pantalla.
            ->columns(1)
            ->components([
                Section::make('Para qué es')
                    ->columns(2)
                    ->schema([
                        Select::make('tax_rate')
                            ->label('Impuesto')
                            ->options([
                                ''     => 'El del laboratorio (' . round((float) config('fabos.money.tax_rate') * 100) . '%)',
                                '0'    => 'Sin impuesto',
                                '0.05' => '5%',
                                '0.19' => '19%',
                            ])
                            ->default('')
                            /*
                             * La base guarda 0.0000 y las opciones dicen «0»:
                             * sin traducir, al abrir una solicitud sin
                             * impuesto el desplegable la mostraba bien pero
                             * al guardar decia «impuesto invalido». Se
                             * recorta al abrir, y lo vacio vuelve a ser nulo.
                             */
                            ->formatStateUsing(fn ($state) => $state === null || $state === ''
                                ? ''
                                : rtrim(rtrim(number_format((float) $state, 4, '.', ''), '0'), '.'))
                            ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? null : $state)
                            ->helperText('No todo lleva IVA: unos honorarios o un servicio exento, no. Si no se dice, se usa el del laboratorio.'),

                        /*
                         * En que moneda se escribe. Lo de Amazon viene en
                         * dolares con centavos, y convertir cada linea a mano
                         * es invitar al error: se escribe como esta y se dice
                         * a cuantos pesos va el dolar.
                         */
                        Select::make('currency')
                            ->label('Moneda')
                            ->options(PurchaseRequest::MONEDAS)
                            ->default('COP')
                            ->required()
                            ->live()
                            ->helperText('Los precios se escriben en esta moneda. El presupuesto sigue en pesos.'),

                        TextInput::make('exchange_rate')
                            ->label('Pesos por dólar')
                            ->numeric()
                            ->minValue(1)
                            ->prefix(config('fabos.money.symbol'))
                            // La TRM de verdad, no la cifra de la configuracion.
                            // Su ayuda ya prometia «la TRM del dia» mientras
                            // ofrecia un supuesto escrito el dia que se monto
                            // esto; quien no la corregia comparaba contra el
                            // presupuesto con la tasa del ano pasado.
                            ->default(fn () => round(\App\Support\Dinero::tasaUsd()))
                            ->visible(fn (Get $get) => $get('currency') === 'USD')
                            ->required(fn (Get $get) => $get('currency') === 'USD')
                            ->helperText(fn () => 'Llega con la TRM del día. Cámbiala si compras usa otra.'),

                        Select::make('budget_id')
                            ->label('Presupuesto')
                            ->options(fn () => Budget::where('status', 'vigente')
                                ->orderByDesc('year')
                                ->get()
                                ->mapWithKeys(fn (Budget $b) => [
                                    $b->id => $b->name . ' ' . $b->year . ' — quedan ' .
                                        config('fabos.money.symbol') . number_format($b->disponible(), 0, ',', '.'),
                                ]))
                            ->searchable()
                            ->helperText('Contra este saldo se aprueba. Se puede dejar para después.'),

                        Select::make('area_id')
                            ->label('Área')
                            ->relationship('area', 'name'),

                        Select::make('project_id')
                            ->label('Para un proyecto')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('Si se compra para un proyecto concreto, lo recibido entra en su costeo.'),

                        TextInput::make('justification')
                            ->label('Justificación')
                            ->columnSpanFull()
                            ->maxLength(255)
                            ->placeholder('Reposición de filamento para el curso kilo del segundo semestre')
                            ->helperText('Una línea. Es lo primero que lee quien aprueba.'),

                        Textarea::make('notes')->label('Notas')->columnSpanFull(),
                    ]),

                /*
                 * A quién se le compra. Iba en las notas, cada vez escrito de
                 * una forma, y compras lo tenía que pescar del texto. Y la
                 * cotización formal, que viajaba aparte por correo, queda
                 * junto a la requisición que la justifica.
                 */
                Section::make('Proveedor')
                    ->description('A quién se le compra y con qué cotización. Sale en la requisición que se le entrega a compras.')
                    ->columns(2)
                    ->schema([
                        /*
                         * De la lista, para no teclearlo otra vez. Elegirlo
                         * rellena los datos de abajo, que son los que se
                         * imprimen y se pueden ajustar para esta compra. No se
                         * guarda: lo que cuenta es lo que queda escrito, y con
                         * eso la solicitud se enlaza sola a su proveedor.
                         */
                        Select::make('proveedor_de_la_lista')
                            ->label('Proveedor de la lista')
                            ->placeholder('Buscar por empresa o NIT…')
                            ->options(fn () => \App\Models\Supplier::orderBy('name')->limit(50)->get()
                                ->mapWithKeys(fn ($p) => [$p->id => $p->name . ($p->tax_id ? ' · ' . $p->tax_id : '')])
                                ->all())
                            // Buscando en el servidor: el NIT se encuentra con
                            // puntos, sin ellos o sin el guion, aunque en la
                            // lista esté escrito de otra forma.
                            ->getSearchResultsUsing(fn (string $search) => \App\Models\Supplier::query()
                                ->buscar($search)->orderBy('name')->limit(30)->get()
                                ->mapWithKeys(fn ($p) => [$p->id => $p->name . ($p->tax_id ? ' · ' . $p->tax_id : '')])
                                ->all())
                            ->getOptionLabelUsing(fn ($value) => ($p = \App\Models\Supplier::find($value))
                                ? $p->name . ($p->tax_id ? ' · ' . $p->tax_id : '')
                                : null)
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->afterStateHydrated(fn ($component, ?PurchaseRequest $record) => $component->state($record?->supplier_id))
                            ->afterStateUpdated(function ($state, $set) {
                                $p = $state ? \App\Models\Supplier::find($state) : null;

                                if (! $p) {
                                    return;
                                }

                                $set('supplier_name', $p->name);
                                $set('supplier_tax_id', $p->tax_id);
                                $set('supplier_address', $p->address);
                                $set('supplier_contact', $p->contact);
                            })
                            ->helperText('Elige uno y se llenan sus datos. Si es nuevo, escríbelo abajo: al guardar queda en Compras → Proveedores.'),

                        TextInput::make('supplier_name')
                            ->label('Empresa')
                            ->maxLength(180)
                            ->placeholder('Tap Publicidad S.A.S.'),

                        TextInput::make('supplier_tax_id')
                            ->label('NIT')
                            ->maxLength(40)
                            ->placeholder('901.234.567-8'),

                        TextInput::make('supplier_address')
                            ->label('Dirección')
                            ->maxLength(200),

                        TextInput::make('supplier_contact')
                            ->label('Representante o responsable')
                            ->maxLength(160)
                            ->helperText('Quien firma o responde por la cotización.'),

                        \App\Filament\Componentes\ArchivoPrivado::previsualizar(
                            \Filament\Forms\Components\FileUpload::make('quote_path')
                        )
                            ->label('Propuesta o cotización formal')
                            ->columnSpanFull()
                            // Disco privado: lleva precios y datos del proveedor.
                            ->disk('local')
                            ->directory('compras/cotizaciones')
                            ->storeFileNamesIn('quote_name')
                            ->acceptedFileTypes([
                                'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(20 * 1024)
                            ->helperText('El PDF que mandó el proveedor, o una foto legible. Hasta 20 MB. Se puede bajar desde la requisición.'),
                    ]),

                Section::make('Qué se pide')
                    ->description('Cada línea lleva su cantidad y su precio estimado. El total con impuesto es lo que se compara contra el presupuesto.')
                    ->schema([
                        // Cuando la compra va por Amazon -o cualquier tienda con
                        // carrito- se deja armado antes de pedir: a compras le
                        // queda mas facil copiar un enlace que buscar cada cosa.
                        TextInput::make('cart_url')
                            ->label('Enlace del carrito')
                            ->url()
                            ->maxLength(2000)
                            ->placeholder('https://www.amazon.com/gp/cart/...')
                            ->helperText('Si el carrito ya está armado en Amazon u otra tienda, pégalo aquí: va en la requisición para que compras lo copie tal cual.'),

                        Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->addActionLabel('Añadir una línea')
                            ->columns(12)
                            ->itemLabel(fn (array $state) => $state['description'] ?? null)
                            ->disabled(fn (?PurchaseRequest $record) => $record && ! $record->esEditable())
                            ->schema([
                                Select::make('supply_id')
                                    ->label('Repone')
                                    ->options(fn () => Supply::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->columnSpan(3)
                                    // Por aqui tambien pasan servicios y honorarios,
                                    // que no reponen nada y no tienen ficha.
                                    ->placeholder('Nada: un servicio o algo nuevo')
                                    ->helperText('Si repone un insumo, al recibir entra solo al inventario. Un servicio no repone nada.')
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $insumo = $state ? Supply::find($state) : null;

                                        if ($insumo) {
                                            $set('description', $insumo->name);
                                            $set('unit', $insumo->unit);
                                            $set('unit_price', $insumo->last_cost ?? 0);
                                        }
                                    }),

                                TextInput::make('description')
                                    ->label('Qué')
                                    ->required()
                                    ->columnSpan(3)
                                    ->placeholder('Sensor de temperatura DS18B20')
                                    ->maxLength(1000)
                                    // Lo que no esta en el catalogo se escribe
                                    // aqui tal cual: la ficha, si hace falta,
                                    // se crea cuando llegue.
                                    ->helperText('Con sus palabras. No tiene que estar en el catálogo.'),

                                TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->columnSpan(1),

                                TextInput::make('unit')
                                    ->label('Unidad')
                                    ->default('unidad')
                                    ->columnSpan(1),

                                TextInput::make('unit_price')
                                    ->label('Precio unitario')
                                    ->numeric()
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default(0)
                                    // Desde la linea, `../../` es la solicitud.
                                    ->prefix(fn (Get $get) => $get('../../currency') === 'USD' ? 'US$' : config('fabos.money.symbol'))
                                    ->columnSpan(2),

                                TextInput::make('supplier')
                                    ->label('Proveedor')
                                    ->columnSpan(1),

                                TextInput::make('reference_url')
                                    ->label('Enlace')
                                    ->url()
                                    ->maxLength(2000)
                                    ->columnSpan(1)
                                    ->helperText('Del producto'),
                            ]),
                    ]),

                /*
                 * Lo que el proveedor resta o suma sobre el pedido entero.
                 * Las lineas sumaban bien y aun asi la cuenta no daba: el
                 * descuento de Amazon, el envio, un cargo de importacion. No
                 * son lineas con cantidad y precio, y meterlas como si lo
                 * fueran era mentir para que cuadrara.
                 */
                Section::make('Descuentos y cobros adicionales')
                    ->description('Lo que el proveedor resta o suma sobre el pedido entero: el descuento de Amazon, el envío, un cargo de importación. Se escriben en positivo; el tipo pone el signo.')
                    ->collapsible()
                    ->collapsed(fn (?PurchaseRequest $record) => ! $record || $record->adjustments()->doesntExist())
                    ->schema([
                        Repeater::make('adjustments')
                            ->label('')
                            ->relationship()
                            ->orderColumn('sort')
                            ->addActionLabel('Añadir un descuento o un cobro')
                            ->columns(12)
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state) => $state['description'] ?? null)
                            ->disabled(fn (?PurchaseRequest $record) => $record && ! $record->esEditable())
                            ->schema([
                                Select::make('kind')
                                    ->label('Tipo')
                                    ->options(PurchaseRequestAdjustment::TIPOS)
                                    ->default(PurchaseRequestAdjustment::DESCUENTO)
                                    ->required()
                                    ->live()
                                    ->columnSpan(3)
                                    // Un descuento del proveedor casi siempre
                                    // baja la base del impuesto; un envio o un
                                    // cargo de importacion casi nunca lo lleva.
                                    ->afterStateUpdated(fn ($state, callable $set) => $set('applies_tax', $state === PurchaseRequestAdjustment::DESCUENTO)),

                                TextInput::make('description')
                                    ->label('Qué es')
                                    ->required()
                                    ->maxLength(160)
                                    ->columnSpan(4)
                                    ->placeholder('Descuento de Amazon · Envío · Cargo de importación'),

                                TextInput::make('amount')
                                    ->label('Valor')
                                    ->numeric()
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->required()
                                    ->prefix(fn (Get $get) => $get('../../currency') === 'USD' ? 'US$' : config('fabos.money.symbol'))
                                    ->columnSpan(3),

                                Toggle::make('applies_tax')
                                    ->label('Lleva impuesto')
                                    ->default(true)
                                    ->inline(false)
                                    ->columnSpan(2)
                                    ->helperText('Si cuenta para la base del IVA.'),
                            ]),
                    ]),
            ]);
    }
}
