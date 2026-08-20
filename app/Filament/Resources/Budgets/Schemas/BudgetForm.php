<?php

namespace App\Filament\Resources\Budgets\Schemas;

use App\Models\Budget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class BudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->placeholder('Insumos y mantenimiento'),

                TextInput::make('year')
                    ->label('Vigencia')
                    ->numeric()
                    ->required()
                    ->default((int) date('Y')),

                Select::make('area_id')
                    ->label('Área')
                    ->relationship('area', 'name')
                    ->placeholder('Todo el laboratorio')
                    ->helperText('Déjalo vacío si el presupuesto no está partido por área.'),

                TextInput::make('amount')
                    ->label('Monto aprobado')
                    ->numeric()
                    ->required()
                    ->prefix(config('fabos.money.symbol'))
                    ->helperText('En pesos enteros, como lo aprobó la Universidad.'),

                Select::make('status')
                    ->label('Estado')
                    ->options(Budget::ESTADOS)
                    ->default('vigente')
                    ->required()
                    ->helperText('Solo contra un presupuesto vigente se pueden aprobar solicitudes.'),

                Textarea::make('notes')
                    ->label('Notas')
                    ->columnSpanFull(),
            ]);
    }
}
