<?php

namespace App\Filament\Resources\RateCards\Tables;

use App\Models\RateCard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RateCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tarifa')
                    ->description(fn (RateCard $r) => $r->rateable?->name ?? 'Base del laboratorio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('basis')
                    ->label('Se cobra por')
                    ->formatStateUsing(fn (string $state) => RateCard::BASES[$state] ?? $state)
                    ->badge(),

                self::moneda('price_minor', 'Precio')
                    ->description(fn (RateCard $r) => 'por ' . ($r->unit ?: 'unidad')),

                self::moneda('setup_minor', 'Montaje'),
                self::moneda('supervision_hour_minor', 'Acompañam.'),
                self::moneda('minimum_minor', 'Mínimo'),
                self::moneda('deposit_minor', 'Depósito'),

                IconColumn::make('is_assumed')
                    ->label('Supuesta')
                    ->boolean()
                    ->trueIcon('heroicon-o-question-mark-circle')
                    ->falseIcon('heroicon-o-check-badge')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (RateCard $r) => $r->is_assumed
                        ? 'Valor supuesto: pendiente de que la coordinación decida'
                        : 'Precio decidido'),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('basis')
                    ->label('Se cobra por')
                    ->options(RateCard::BASES),

                TernaryFilter::make('is_assumed')
                    ->label('Valor supuesto'),

                TernaryFilter::make('is_active')
                    ->label('Activa')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function moneda(string $campo, string $titulo): TextColumn
    {
        return TextColumn::make($campo)
            ->label($titulo)
            ->alignEnd()
            ->formatStateUsing(fn (?int $state) => $state
                ? number_format($state / config('fabos.currency.minor_units'), 2, ',', '.')
                : '—');
    }
}
