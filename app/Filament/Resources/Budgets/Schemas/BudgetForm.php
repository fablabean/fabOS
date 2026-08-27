<?php

namespace App\Filament\Resources\Budgets\Schemas;

use App\Models\Budget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * El presupuesto se habla en **pesos**, no en FabCoins.
 *
 * Es plata que asigna la Universidad y se conversa con su área de compras. Los
 * FabCoins son la moneda interna con la que se reparte capacidad entre quienes
 * usan el laboratorio: mezclarlas en una misma cifra hace que nadie sepa de
 * cuál se está hablando.
 */
class BudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        $simbolo = config('fabos.money.symbol');
        $tasa = (int) config('fabos.currency.peso_rate');
        $moneda = config('fabos.currency.code');

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->placeholder('Insumos y mantenimiento'),

                Select::make('kind')
                    ->label('Qué clase de presupuesto')
                    ->options(Budget::TIPOS)
                    ->default('gasto')
                    ->required()
                    ->live()
                    ->helperText('De gasto: plata asignada que se consume. De venta: la meta de ingresos, contra la que suma lo que se factura.'),

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
                    ->label(fn (Get $get) => $get('kind') === 'venta' ? 'Meta de ingresos' : 'Monto aprobado')
                    ->numeric()
                    ->required()
                    ->prefix($simbolo)
                    ->helperText(fn (Get $get) => $get('kind') === 'venta'
                        ? "En pesos enteros. Es la meta del año, no un techo. Referencia: 1 {$moneda} = {$simbolo}" . number_format($tasa, 0, ',', '.') . '.'
                        : "En pesos enteros, como lo aprobó la Universidad. Referencia: 1 {$moneda} = {$simbolo}" . number_format($tasa, 0, ',', '.') . '.'),

                Select::make('status')
                    ->label('Estado')
                    ->options(Budget::ESTADOS)
                    ->default('vigente')
                    ->required()
                    ->helperText('Solo contra un presupuesto vigente se pueden aprobar solicitudes.'),

                /*
                 * Lo que ya se había movido antes de que existiera el sistema.
                 *
                 * El ejecutado se deriva de las solicitudes de compra, y así
                 * debe seguir: un «disponible» editable a mano es lo que hace
                 * que a mitad de año nadie sepa cuánto queda. Pero el año
                 * arrancó antes que fabOS, y lo gastado en enero no tiene
                 * solicitud que lo respalde. Sin poder anotarlo, el presupuesto
                 * enseña como disponible una plata que ya no está.
                 *
                 * Va aparte y con explicación obligatoria: se distingue siempre
                 * lo que el sistema sabe de lo que alguien afirmó.
                 */
                TextInput::make('opening_executed')
                    ->label(fn (Get $get) => $get('kind') === 'venta'
                        ? 'Ingresos de arranque'
                        : 'Ejecutado de arranque')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix($simbolo)
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get) => $get('kind') === 'venta'
                        ? 'Lo que ya había entrado antes de que el sistema llevara las ventas.'
                        : 'Lo gastado antes de que el sistema llevara las compras. De aquí en adelante lo justifica la solicitud de compra.'),

                Textarea::make('opening_note')
                    ->label('De dónde sale ese arranque')
                    ->rows(2)
                    ->columnSpanFull()
                    ->required(fn (Get $get) => (int) $get('opening_executed') > 0)
                    ->helperText('Obligatorio si hay arranque: dentro de seis meses, una cifra sin explicación no se puede defender ante nadie.'),

                Textarea::make('notes')
                    ->label('Notas')
                    ->columnSpanFull(),
            ]);
    }
}
