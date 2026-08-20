<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Models\Sale;
use App\Models\Supply;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Money\PricingService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * El mostrador.
 *
 * Al elegir un insumo se traen su unidad y su precio: quien atiende no debería
 * tener que consultar una lista aparte con el cliente esperando.
 */
class SaleForm
{
    public static function configure(Schema $schema): Schema
    {
        $moneda = config('fabos.currency.code');
        $unidades = config('fabos.currency.minor_units');

        return $schema
            ->components([
                Section::make('A quién')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Cliente')
                            ->options(fn () => User::where('status', 'activo')->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->helperText(function ($state) use ($moneda, $unidades) {
                                $persona = $state ? User::find($state) : null;

                                if (! $persona) {
                                    return 'Se cobra contra su saldo en ' . config('fabos.currency.name') . 's.';
                                }

                                return 'Saldo: ' . number_format(
                                    app(LedgerService::class)->saldoDe($persona) / $unidades, 2, ',', '.'
                                ) . ' ' . $moneda;
                            }),

                        Textarea::make('notes')->label('Notas')->columnSpanFull(),
                    ]),

                Section::make('Qué se lleva')
                    ->description('Los insumos descuentan existencia al cobrar. Un servicio especial no toca el inventario.')
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->addActionLabel('Añadir')
                            ->columns(12)
                            ->itemLabel(fn (array $state) => $state['description'] ?? null)
                            ->disabled(fn (?Sale $record) => $record && ! $record->esEditable())
                            ->schema([
                                Select::make('supply_id')
                                    ->label('Insumo')
                                    ->options(fn () => Supply::where('is_active', true)
                                        ->where('stock', '>', 0)
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn (Supply $s) => [
                                            $s->id => $s->name . ' — quedan ' .
                                                rtrim(rtrim(number_format((float) $s->stock, 3, ',', '.'), '0'), ',') .
                                                ' ' . $s->unit,
                                        ]))
                                    ->searchable()
                                    ->columnSpan(5)
                                    ->placeholder('Servicio especial')
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) use ($unidades) {
                                        $insumo = $state ? Supply::find($state) : null;

                                        if (! $insumo) {
                                            return;
                                        }

                                        $set('description', $insumo->name);
                                        $set('unit', $insumo->unit);
                                        $set('unit_price_minor', app(PricingService::class)->precioDe($insumo) / $unidades);
                                    }),

                                TextInput::make('description')
                                    ->label('Descripción')
                                    ->required()
                                    ->columnSpan(3),

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

                                TextInput::make('unit_price_minor')
                                    ->label('Precio unitario')
                                    ->numeric()
                                    ->required()
                                    ->prefix($moneda)
                                    ->columnSpan(2)
                                    ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / $unidades)
                                    ->dehydrateStateUsing(fn (?string $state) => (int) round(((float) $state) * $unidades)),
                            ]),
                    ]),
            ]);
    }
}
