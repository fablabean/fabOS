<?php

namespace App\Filament\Resources\Supplies\Schemas;

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

                        Select::make('location_id')
                            ->label('Dónde está')
                            ->relationship('location', 'name')
                            ->searchable(),

                        Toggle::make('is_active')->label('Activo')->default(true),

                        Textarea::make('description')->label('Descripción')->columnSpanFull(),
                    ]),

                Section::make('Existencias')
                    ->description('La existencia no se edita aquí: se mueve con entradas, salidas y ajustes, y cada movimiento queda con su motivo.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('stock')
                            ->label('Existencia actual')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Usa el botón «Mover existencia» en el listado.'),

                        TextInput::make('reorder_point')
                            ->label('Punto de reposición')
                            ->numeric()
                            ->helperText('Por debajo de esto, el insumo aparece en el carrito de reposición.'),

                        TextInput::make('last_cost')
                            ->label('Último costo por unidad')
                            ->numeric()
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('Se actualiza solo al recibir una compra.'),
                    ]),
            ]);
    }
}
