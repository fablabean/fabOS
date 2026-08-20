<?php

namespace App\Filament\Resources\LedgerTransactions\Tables;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LedgerTransactionsTable
{
    public static function configure(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Cuándo')
                    ->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('d/m/Y H:i'))
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Concepto')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => LedgerTransaction::TIPOS[$state] ?? $state),

                TextColumn::make('memo')
                    ->label('Detalle')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('movimiento')
                    ->label('De → a')
                    ->state(fn (LedgerTransaction $r) => collect($r->entries)
                        ->sortBy(fn (LedgerEntry $e) => $e->direction)
                        ->map(fn (LedgerEntry $e) => ($e->esDebito() ? '−' : '+') . ' ' . $e->account?->name)
                        ->implode(' · ')),

                TextColumn::make('importe')
                    ->label('Importe')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (LedgerTransaction $r) => number_format(
                        $r->importeMenor() / config('fabos.currency.minor_units'), 2, ',', '.'
                    )),

                TextColumn::make('createdBy.name')
                    ->label('Registró')
                    ->placeholder('automático')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('hash')
                    ->label('Sello')
                    ->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 10) : '—')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Concepto')
                    ->options(LedgerTransaction::TIPOS),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
