<?php

namespace App\Filament\Resources\UserCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                        TextInput::make('slug')->label('Identificador')->required()->maxLength(255),
                        TextInput::make('position')->label('Orden')->numeric()->default(0),
                        Toggle::make('is_institutional')
                            ->label('Pertenece a la Universidad')
                            ->helperText('Define quién recibe la dotación institucional.'),
                    ]),

                Section::make('Qué implica esta categoría')
                    ->description('El factor multiplica tiempo, montaje y supervisión. El material se cobra a costo para todos: subsidiarlo sería plata que sale de caja y no vuelve.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('rate_factor')
                            ->label('Factor de tarifa')
                            ->numeric()
                            ->step('0.01')
                            ->default(1)
                            ->helperText('0,5 = mitad de precio · 2 = doble.'),

                        TextInput::make('allowance_minor')
                            ->label('Dotación periódica')
                            ->numeric()
                            ->suffix(config('fabos.currency.code'))
                            ->helperText('En unidades menores: 100 = 1 ' . config('fabos.currency.name') . '.'),

                        Toggle::make('can_reserve')
                            ->label('Puede reservar')
                            ->default(true)
                            ->helperText('Los invitados existen para trazabilidad, pero no reservan.'),

                        TextInput::make('max_days_ahead')
                            ->label('Anticipación máxima (días)')
                            ->numeric()
                            ->default(30),

                        TextInput::make('max_hours_per_week')
                            ->label('Tope semanal de horas')
                            ->numeric()
                            ->placeholder('sin tope'),
                    ]),

                Section::make('Con cuánto nace')
                    ->description('El saldo de bienvenida se abona en el acto, al crearse la cuenta o al recibir esta categoría, y completa hasta la cifra: no se suma a lo que ya tenga. Solo con el beneficio encendido.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('welcome_minor')
                            ->label('Bienvenida')
                            ->numeric()
                            ->default(0)
                            ->suffix(config('fabos.currency.code'))
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / config('fabos.currency.minor_units'))
                            ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * config('fabos.currency.minor_units')))
                            ->helperText('En ' . config('fabos.currency.name') . 's. Cero: sin bienvenida.'),

                        Toggle::make('weekly_benefit')
                            ->label('Recibe el beneficio semanal')
                            ->helperText('Cada lunes se le completa el saldo hasta el tope, tenga el correo que tenga. Quien tiene correo de una institución aliada lo recibe de todos modos.'),
                    ]),
            ]);
    }
}
