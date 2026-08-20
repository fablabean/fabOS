<?php

namespace App\Filament\Resources\Budgets\Tables;

use App\Models\Budget;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Presupuesto')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Budget $r) => $r->area?->name ?? 'todo el laboratorio'),

                TextColumn::make('year')->label('Vigencia')->sortable(),

                self::pesos('amount', 'Aprobado')->weight('medium'),

                TextColumn::make('comprometido')
                    ->label('Comprometido')
                    ->alignEnd()
                    ->color('warning')
                    ->state(fn (Budget $r) => self::formato($r->comprometido()))
                    ->description('aprobado, sin llegar'),

                TextColumn::make('ejecutado')
                    ->label('Ejecutado')
                    ->alignEnd()
                    ->state(fn (Budget $r) => self::formato($r->ejecutado()))
                    ->description('ya recibido'),

                TextColumn::make('disponible')
                    ->label('Disponible')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (Budget $r) => self::formato($r->disponible()))
                    ->color(fn (Budget $r) => $r->disponible() > 0 ? 'success' : 'danger')
                    ->description(fn (Budget $r) => $r->porcentajeUsado() . '% usado'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Budget::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'vigente' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Budget::ESTADOS),
            ])
            ->recordActions([EditAction::make()]);
    }

    private static function pesos(string $campo, string $titulo): TextColumn
    {
        return TextColumn::make($campo)
            ->label($titulo)
            ->alignEnd()
            ->formatStateUsing(fn (?int $state) => self::formato((int) $state));
    }

    private static function formato(int $pesos): string
    {
        return config('fabos.money.symbol') . number_format($pesos, 0, ',', '.');
    }
}
