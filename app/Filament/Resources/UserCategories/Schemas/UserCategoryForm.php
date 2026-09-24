<?php

namespace App\Filament\Resources\UserCategories\Schemas;

use App\Filament\Componentes\CampoDeDinero;
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
                CampoDeDinero::selector(['allowance_minor', 'welcome_minor']),
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

                        /*
                         * En la moneda de trabajo, no en unidades menores.
                         *
                         * Pedia el numero crudo de la base -«100 = 1 FabCoin»-
                         * y lo explicaba en la ayuda, que es tanto como pedir
                         * que se haga la cuenta a mano cada vez. Un cero de mas
                         * aqui es una dotacion diez veces mayor para todo el
                         * mundo, y no da ningun error.
                         */
                        CampoDeDinero::make('allowance_minor')
                            ->label('Dotación periódica')
                            ->helperText('Lo que recibe cada periodo quien esté en esta categoría.'),

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
                        CampoDeDinero::make('welcome_minor')
                            ->label('Bienvenida')
                            ->helperText('En ' . config('fabos.currency.name') . 's. Cero: sin bienvenida.'),

                        Toggle::make('weekly_benefit')
                            ->label('Recibe el beneficio semanal')
                            ->helperText('Cada lunes se le completa el saldo hasta el tope, tenga el correo que tenga. Quien tiene correo de una institución aliada lo recibe de todos modos.'),
                    ]),
            ]);
    }
}
