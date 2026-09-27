<?php

namespace App\Filament\Resources\WorkOrders\Tables;

use App\Models\WorkOrder;
use App\Services\Maintenance\MaintenanceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('asset.name')
                    ->label('Equipo')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (WorkOrder $record) => $record->asset?->area?->name),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkOrder::TIPOS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'correctivo' ? 'warning' : 'gray'),

                TextColumn::make('reported_issue')->label('Asunto')->limit(45),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkOrder::ESTADOS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'cerrada'    => 'success',
                        'en_proceso' => 'info',
                        'abierta'    => 'warning',
                        default      => 'gray',
                    }),

                IconColumn::make('stops_equipment')
                    ->label('Con paro')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip('El equipo está fuera de servicio mientras esté abierta'),

                // Alimenta el MTTR: cuánto tiempo estuvo detenida la máquina.
                TextColumn::make('paro')
                    ->label('Tiempo detenido')
                    ->state(function (WorkOrder $record) {
                        $m = $record->minutosDeParo();

                        return $m === null ? '—' : round($m / 60, 1) . ' h';
                    })
                    ->color(fn (WorkOrder $record) => $record->stops_equipment && $record->status !== 'cerrada'
                        ? 'danger' : null),

                TextColumn::make('reportedBy.name')->label('Reportó')->placeholder('—')->toggleable(),
                TextColumn::make('assignedTo.name')->label('Atiende')->placeholder('sin asignar'),

                TextColumn::make('created_at')
                    ->label('Abierta')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(WorkOrder::ESTADOS)->multiple(),
                SelectFilter::make('kind')->label('Tipo')->options(WorkOrder::TIPOS),

                Filter::make('abiertas')
                    ->label('Solo abiertas')
                    ->query(fn (Builder $query) => $query->whereIn('status', WorkOrder::ABIERTAS))
                    ->default(),

                Filter::make('con_paro')
                    ->label('Equipos detenidos')
                    ->query(fn (Builder $query) => $query->where('stops_equipment', true)
                        ->whereIn('status', WorkOrder::ABIERTAS)),
            ])
            ->recordActions([
                Action::make('cerrar')
                    ->label('Cerrar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->schema(fn (WorkOrder $record) => array_filter([
                        // La lista de chequeo del plan, si la trae: se marca
                        // lo que se hizo y queda guardado con la orden.
                        ($puntos = \App\Models\MaintenancePlan::puntosDe($record->checklist_snapshot))
                            ? \Filament\Forms\Components\CheckboxList::make('hechos')
                                ->label('Lista de chequeo')
                                ->options(array_combine($puntos, $puntos))
                                ->bulkToggleable()
                            : null,
                        Textarea::make('work_done')
                            ->label('Qué se hizo')
                            ->required()
                            ->rows(3),
                    ]))
                    ->modalDescription('Si la orden tenía paro, el equipo vuelve a estar disponible al cerrarla.')
                    ->visible(fn (WorkOrder $record) => in_array($record->status, WorkOrder::ABIERTAS, true))
                    ->action(function (WorkOrder $record, array $data) {
                        $puntos = \App\Models\MaintenancePlan::puntosDe($record->checklist_snapshot);
                        $hechos = $data['hechos'] ?? [];

                        app(MaintenanceService::class)->cerrar(
                            $record,
                            $data['work_done'],
                            // Cada punto con sí o no: lo que no se marcó también se guarda.
                            $puntos ? collect($puntos)->mapWithKeys(fn ($p) => [$p => in_array($p, $hechos, true)])->all() : null,
                        );
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
