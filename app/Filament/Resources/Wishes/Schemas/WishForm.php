<?php

namespace App\Filament\Resources\Wishes\Schemas;

use App\Models\Supply;
use App\Models\Wish;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Apuntar un deseo tiene que costar poco.
 *
 * Lo único que de verdad hace falta es qué se desea y para qué año; todo lo
 * demás se puede llenar después, cuando se sepa. Un formulario que exija precio
 * y proveedor para dejar constancia de que hace falta una fresadora es un
 * formulario que nadie abre, y entonces la lista no existe (§13).
 */
class WishForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('description')
                    ->label('Qué se desea')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->placeholder('Fresadora CNC de 3 ejes'),

                TextInput::make('target_year')
                    ->label('Para qué año')
                    ->numeric()
                    ->required()
                    ->default(fn () => Wish::anoPorDefecto())
                    ->helperText('Si no alcanza, el deseo se pasa al año siguiente cambiando esta cifra.'),

                Select::make('priority')
                    ->label('Prioridad')
                    ->options(Wish::PRIORIDADES)
                    ->default('media')
                    ->required()
                    ->helperText('Para saber qué entra primero cuando no alcance para todo.'),

                Select::make('budget_line')
                    ->label('Rubro del presupuesto')
                    ->options(fn () => Wish::rubrosDisponibles())
                    ->searchable()
                    ->placeholder('Todavía sin decidir')
                    // Salen de los presupuestos que ya existen, y se puede
                    // escribir uno nuevo para un rubro que solo va a existir el
                    // año que viene.
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Nombre del rubro')
                            ->required()
                            ->maxLength(120)
                            ->helperText('Como se va a llamar el presupuesto: «Formación externa».'),
                    ])
                    ->createOptionUsing(fn (array $data) => $data['name'])
                    ->createOptionModalHeading('Un rubro que todavía no existe')
                    ->helperText('Contra qué presupuesto se pagaría. Es lo que reparte la cifra del año siguiente, y lo que hace que el carrito nazca apuntando al presupuesto correcto.'),

                Select::make('area_id')
                    ->label('Área')
                    ->relationship('area', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Todo el laboratorio')
                    ->helperText('A quién le hace falta. El rubro dice contra qué se paga; el área, para quién es.'),

                Select::make('supply_id')
                    ->label('Repone un insumo')
                    ->relationship('supply', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->placeholder('Algo que todavía no está en el catálogo')
                    // Igual que al armar una línea de compra: si el catálogo ya
                    // sabe cuánto costó la última vez, no hay por qué buscar la
                    // factura anterior.
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $insumo = $state ? Supply::find($state) : null;

                        if (! $insumo) {
                            return;
                        }

                        $set('unit', $insumo->unit);
                        $set('description', $insumo->name);

                        if ($insumo->last_cost) {
                            $set('unit_price', (int) $insumo->last_cost);
                        }
                    })
                    ->helperText('Opcional. La mayoría de los deseos son cosas que el laboratorio todavía no tiene.'),

                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(0.001),

                TextInput::make('unit')
                    ->label('Unidad')
                    ->required()
                    ->default('unidad')
                    ->maxLength(255),

                TextInput::make('unit_price')
                    ->label('Estimado unitario')
                    ->numeric()
                    ->prefix(config('fabos.money.symbol'))
                    ->helperText('En pesos. Déjalo vacío si nadie lo ha cotizado: el resumen del año lo cuenta aparte, en vez de contarlo como cero.'),

                TextInput::make('reference_url')
                    ->label('Enlace al producto')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://')
                    ->columnSpanFull(),

                Textarea::make('justification')
                    ->label('Para qué se necesita')
                    ->rows(2)
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Esto es lo que se lee el día que hay que defender el presupuesto del año que viene.'),

                Textarea::make('notes')
                    ->label('Notas')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
