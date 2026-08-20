<?php

namespace App\Filament\Resources\UserCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')->label('Categoría')->searchable()->weight('medium'),

                TextColumn::make('rate_factor')
                    ->label('Factor de tarifa')
                    ->numeric(decimalPlaces: 2)
                    ->tooltip('Multiplica el tiempo y la supervisión. El material va a costo.')
                    ->badge()
                    ->color(fn ($state) => $state < 1 ? 'success' : ($state > 1 ? 'warning' : 'gray')),

                TextColumn::make('allowance_minor')
                    ->label('Dotación')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0) . ' ' . config('fabos.currency.code')),

                TextColumn::make('users_count')->label('Personas')->counts('users')->badge()->color('gray'),

                IconColumn::make('can_reserve')->label('Puede reservar')->boolean(),
                IconColumn::make('is_institutional')
                    ->label('De la institución')
                    ->boolean()
                    ->toggleable()
                    ->tooltip('Pertenece a ' . config('fabos.lab.institution')),

                TextColumn::make('max_days_ahead')
                    ->label('Anticipación')
                    ->formatStateUsing(fn ($state) => $state . ' días')
                    ->toggleable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
