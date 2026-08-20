<?php

namespace App\Filament\Resources\Supplies\Tables;

use App\Models\Supply;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SuppliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Insumo')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Supply $r) => collect([$r->area?->name, $r->location?->name])
                        ->filter()->implode(' · ') ?: null),

                TextColumn::make('stock')
                    ->label('Existencia')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 3, ',', '.'), '0'), ','))
                    ->description(fn (Supply $r) => $r->unit)
                    ->color(fn (Supply $r) => $r->bajoMinimos() ? 'danger' : null),

                TextColumn::make('reorder_point')
                    ->label('Mínimo')
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null
                        ? '—'
                        : rtrim(rtrim(number_format((float) $state, 3, ',', '.'), '0'), ',')),

                TextColumn::make('last_cost')
                    ->label('Último costo')
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state) => $state
                        ? config('fabos.money.symbol') . number_format($state, 0, ',', '.')
                        : '—'),

                TextColumn::make('movimientos')
                    ->label('Movimientos')
                    ->alignEnd()
                    ->state(fn (Supply $r) => $r->movements()->count()),
            ])
            ->filters([
                Filter::make('bajo_minimos')
                    ->label('Bajo mínimos')
                    ->query(fn ($q) => $q->whereNotNull('reorder_point')->whereColumn('stock', '<=', 'reorder_point')),

                TernaryFilter::make('is_active')->label('Activo')->default(true),
            ])
            ->recordActions([
                self::mover(),
                EditAction::make(),
            ]);
    }

    /**
     * Un solo botón para las tres formas de mover existencia. Separarlas en tres
     * acciones haría creer que el ajuste es una más, cuando es la excepción.
     */
    private static function mover(): Action
    {
        return Action::make('mover')
            ->label('Mover existencia')
            ->icon('heroicon-o-arrows-up-down')
            ->schema([
                Select::make('tipo')
                    ->label('Qué pasó')
                    ->options([
                        'entrada' => 'Entró (compra fuera del sistema, devolución)',
                        'salida'  => 'Salió (consumo, préstamo, pérdida)',
                        'ajuste'  => 'Conteo físico: corregir a la cantidad real',
                    ])
                    ->default('salida')
                    ->required()
                    ->live(),

                TextInput::make('cantidad')
                    ->label(fn (callable $get) => $get('tipo') === 'ajuste'
                        ? 'Cantidad contada'
                        : 'Cantidad')
                    ->numeric()
                    ->required(),

                TextInput::make('motivo')
                    ->label('Motivo')
                    ->required(fn (callable $get) => $get('tipo') === 'ajuste')
                    ->helperText('Obligatorio en los ajustes: uno sin explicación es indistinguible de una pérdida que nadie reportó.'),
            ])
            ->action(function (Supply $record, array $data) {
                $stock = app(StockService::class);
                $quien = auth()->user();

                try {
                    match ($data['tipo']) {
                        'entrada' => $stock->entrada($record, (float) $data['cantidad'], $data['motivo'] ?? null, null, $quien),
                        'salida'  => $stock->salida($record, (float) $data['cantidad'], $data['motivo'] ?? null, null, $quien),
                        'ajuste'  => $stock->ajustar($record, (float) $data['cantidad'], $data['motivo'], $quien),
                    };
                } catch (StockException $e) {
                    Notification::make()->title('No se pudo mover')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Existencia actualizada')
                    ->body($record->fresh()->name . ': ' . rtrim(rtrim(number_format((float) $record->fresh()->stock, 3, ',', '.'), '0'), ',') . ' ' . $record->unit)
                    ->success()
                    ->send();
            });
    }
}
