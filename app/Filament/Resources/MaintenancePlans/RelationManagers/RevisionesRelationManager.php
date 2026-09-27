<?php

namespace App\Filament\Resources\MaintenancePlans\RelationManagers;

use App\Models\WorkOrder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * El historial del plan: cada revisión que abrió, a qué equipo, cuándo se
 * cerró, qué se hizo y cuántos puntos de la lista se cumplieron (§8).
 *
 * Solo se mira: cada revisión se trabaja y se cierra en Órdenes de trabajo.
 */
class RevisionesRelationManager extends RelationManager
{
    protected static string $relationship = 'workOrders';

    protected static ?string $title = 'Historial de revisiones';

    protected static ?string $modelLabel = 'revisión';

    protected static ?string $pluralModelLabel = 'revisiones';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('asset.name')->label('Equipo')->searchable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkOrder::ESTADOS[$state] ?? $state)
                    ->color(fn ($state) => in_array($state, WorkOrder::ABIERTAS, true) ? 'warning' : 'success'),
                TextColumn::make('due_at')->label('Tocaba')->date('d/m/Y')->sortable(),
                TextColumn::make('closed_at')->label('Hecha')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('cumplidos')
                    ->label('Lista')
                    ->state(function (WorkOrder $r) {
                        $puntos = \App\Models\MaintenancePlan::puntosDe($r->checklist_snapshot);
                        if ($puntos === []) {
                            return '—';
                        }
                        $hechos = collect($r->checklist_answers ?? [])->filter()->count();

                        return $hechos . ' de ' . count($puntos);
                    }),
                TextColumn::make('work_done')->label('Qué se hizo')->limit(60)->wrap()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('asset_id')->label('Equipo')->relationship('asset', 'name'),
            ]);
    }
}
