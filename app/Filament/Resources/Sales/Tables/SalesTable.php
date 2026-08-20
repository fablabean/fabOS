<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Models\Sale;
use App\Services\Shop\ShopException;
use App\Services\Shop\ShopService;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Venta')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Sale $r) => $r->created_at?->timezone($tz)->format('d/m/Y H:i')),

                TextColumn::make('user.name')
                    ->label('Cliente')
                    ->searchable()
                    ->description(fn (Sale $r) => $r->user?->email),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Sale::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'pagada'  => 'success',
                        'abierta' => 'warning',
                        default   => 'danger',
                    }),

                TextColumn::make('lineas')
                    ->label('Líneas')
                    ->alignEnd()
                    ->state(fn (Sale $r) => $r->items()->count()),

                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (Sale $r) => number_format($r->loadMissing('items')->total(), 2, ',', '.'))
                    ->description(config('fabos.currency.code')),

                TextColumn::make('servedBy.name')
                    ->label('Atendió')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Sale::ESTADOS),
            ])
            ->recordActions([
                self::cobrar(),
                self::anular(),
                EditAction::make(),
            ]);
    }

    private static function cobrar(): Action
    {
        return Action::make('cobrar')
            ->label('Cobrar')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Sale $r) => $r->status === 'abierta')
            ->requiresConfirmation()
            ->modalHeading('Cobrar esta venta')
            ->modalDescription(fn (Sale $r) => Settings::cobrosActivos()
                ? 'Se descuenta el saldo del cliente y sale la mercancía del inventario.'
                : 'Los cobros están apagados: sale la mercancía del inventario, pero no se descuenta saldo.')
            ->action(function (Sale $record) {
                try {
                    $venta = app(ShopService::class)->cobrar($record, auth()->user());
                } catch (ShopException $e) {
                    Notification::make()->title('No se pudo cobrar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Venta ' . $venta->code . ' cobrada')
                    ->body(number_format($venta->total(), 2, ',', '.') . ' ' . config('fabos.currency.code'))
                    ->success()
                    ->send();
            });
    }

    private static function anular(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (Sale $r) => $r->status !== 'anulada')
            ->schema([
                Textarea::make('motivo')
                    ->label('Por qué se anula')
                    ->required()
                    ->helperText('La venta no se borra: se devuelve el saldo y la mercancía con movimientos nuevos.'),
            ])
            ->action(function (Sale $record, array $data) {
                try {
                    app(ShopService::class)->anular($record, $data['motivo'], auth()->user());
                } catch (ShopException $e) {
                    Notification::make()->title('No se pudo anular')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Venta anulada')->success()->send();
            });
    }
}
