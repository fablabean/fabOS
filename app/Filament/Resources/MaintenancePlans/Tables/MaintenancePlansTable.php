<?php

namespace App\Filament\Resources\MaintenancePlans\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenancePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('assets')->with(['asset', 'riskFamily']))
            ->columns([
                TextColumn::make('name')
                    ->label('Plan')
                    ->searchable(),
                TextColumn::make('cubre')
                    ->label('Cubre')
                    ->state(fn (\App\Models\MaintenancePlan $r) => $r->assets_count
                        ? $r->assets_count . ($r->assets_count === 1 ? ' equipo' : ' equipos')
                        : ($r->asset?->name ?? ($r->riskFamily ? 'Familia ' . $r->riskFamily->name : '—'))),
                TextColumn::make('every_days')
                    ->label('Cada')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' días' : '—')
                    ->sortable(),
                TextColumn::make('starts_on')
                    ->label('Primera revisión')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('work_orders_count')
                    ->label('Revisiones')
                    ->counts('workOrders'),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
}
