<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Supply;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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
                            ->default(config('fabos.money.usd_rate'))
                            ->visible(fn (Get $get) => $get('currency') === 'USD')
                            ->required(fn (Get $get) => $get('currency') === 'USD')
                            ->helperText('La TRM del día, o la que use compras. Con esto se compara contra el presupuesto.'),

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
                                    ->columnSpan(1)
                                    ->helperText('Del producto'),
                            ]),
                    ]),
            ]);
    }
}
