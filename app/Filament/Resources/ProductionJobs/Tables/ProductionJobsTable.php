<?php

namespace App\Filament\Resources\ProductionJobs\Tables;

use App\Models\ProductionJob;
use App\Models\Supply;
use App\Services\Shop\ProductionService;
use App\Services\Shop\ShopException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La cola de producción.
 *
 * El orden por defecto es el de trabajo, no el de llegada: primero lo vencido,
 * luego lo urgente, luego lo más próximo a entregar.
 */
class ProductionJobsTable
{
    public static function configure(Table $table): Table
    {
        $moneda = config('fabos.currency.code');
        $unidades = config('fabos.currency.minor_units');

        return $table
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('code')
                    ->label('Encargo')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (ProductionJob $r) => $r->title),

                TextColumn::make('user.name')
                    ->label('Pide')
                    ->searchable()
                    ->description(fn (ProductionJob $r) => $r->user?->email),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProductionJob::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'solicitado'    => 'gray',
                        'cotizado'      => 'info',
                        'en_cola',
                        'aceptado'      => 'warning',
                        'en_produccion' => 'primary',
                        'listo'         => 'success',
                        'entregado'     => 'success',
                        default         => 'danger',
                    }),

                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProductionJob::PRIORIDADES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'alta' ? 'danger' : 'gray')
                    ->toggleable(),

                TextColumn::make('due_on')
                    ->label('Entrega')
                    ->date('d/m/Y')
                    ->placeholder('sin fecha')
                    ->sortable()
                    ->color(fn (ProductionJob $r) => $r->estaVencido() ? 'danger' : null)
                    ->description(fn (ProductionJob $r) => $r->estaVencido() ? 'vencido' : null),

                TextColumn::make('quoted_total_minor')
                    ->label('Valor')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state) => $state
                        ? number_format($state / $unidades, 2, ',', '.') . ' ' . $moneda
                        : '—'),

                TextColumn::make('assignedTo.name')->label('A cargo')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ProductionJob::ESTADOS),
                SelectFilter::make('priority')->label('Prioridad')->options(ProductionJob::PRIORIDADES),

                Filter::make('en_cola')
                    ->label('Solo la cola de trabajo')
                    ->query(fn ($q) => $q->whereIn('status', ProductionJob::EN_COLA)),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::cotizar(),
                    self::aceptar(),
                    self::iniciar(),
                    self::terminar(),
                    self::entregar(),
                    self::rechazar(),
                    EditAction::make(),
                ]),
            ]);
    }

    private static function cotizar(): Action
    {
        $unidades = config('fabos.currency.minor_units');

        return Action::make('cotizar')
            ->label('Cotizar')
            ->icon('heroicon-o-calculator')
            ->visible(fn (ProductionJob $r) => in_array($r->status, ['solicitado', 'cotizado'], true))
            ->schema([
                TextInput::make('total')
                    ->label('Valor')
                    ->numeric()
                    ->required()
                    ->prefix(config('fabos.currency.code'))
                    ->helperText('Tiempo de máquina, material y trabajo del equipo, todo incluido.'),

                TextInput::make('minutos')
                    ->label('Tiempo estimado de máquina')
                    ->numeric()
                    ->suffix('min'),

                DatePicker::make('fecha')->label('Entrega prometida'),

                Textarea::make('notas')
                    ->label('Qué incluye')
                    ->helperText('Lo va a leer quien pidió: conviene decir qué material entra y qué no.'),
            ])
            ->action(function (ProductionJob $record, array $data) use ($unidades) {
                self::intentar(fn () => app(ProductionService::class)->cotizar(
                    $record,
                    (int) round(((float) $data['total']) * $unidades),
                    $data['minutos'] ? (int) $data['minutos'] : null,
                    $data['fecha'] ?? null,
                    $data['notas'] ?? null,
                ), 'Cotización enviada a quien pidió');
            });
    }

    private static function aceptar(): Action
    {
        return Action::make('aceptar')
            ->label('Aceptar por el cliente')
            ->icon('heroicon-o-hand-thumb-up')
            ->color('success')
            ->visible(fn (ProductionJob $r) => $r->status === 'cotizado')
            ->requiresConfirmation()
            ->modalDescription('Para cuando aceptan por teléfono o en el mostrador. Normalmente lo hace quien pidió, desde su cuenta.')
            ->action(fn (ProductionJob $r) => self::intentar(
                fn () => app(ProductionService::class)->aceptar($r),
                'Entró a la cola',
            ));
    }

    private static function iniciar(): Action
    {
        return Action::make('iniciar')
            ->label('Tomarlo')
            ->icon('heroicon-o-play')
            ->visible(fn (ProductionJob $r) => in_array($r->status, ['en_cola', 'aceptado'], true))
            ->requiresConfirmation()
            ->modalDescription('Queda a tu nombre y pasa a producción.')
            ->action(fn (ProductionJob $r) => self::intentar(
                fn () => app(ProductionService::class)->iniciar($r, auth()->user()),
                'En producción',
            ));
    }

    private static function terminar(): Action
    {
        return Action::make('terminar')
            ->label('Terminado')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (ProductionJob $r) => $r->status === 'en_produccion')
            ->requiresConfirmation()
            ->modalDescription('Se le avisa a quien pidió que ya puede recogerlo.')
            ->action(fn (ProductionJob $r) => self::intentar(
                fn () => app(ProductionService::class)->terminar($r),
                'Listo para recoger',
            ));
    }

    /** Entregar cobra: genera la venta y descuenta el material declarado. */
    private static function entregar(): Action
    {
        return Action::make('entregar')
            ->label('Entregar y cobrar')
            ->icon('heroicon-o-hand-raised')
            ->color('success')
            ->visible(fn (ProductionJob $r) => $r->status === 'listo')
            ->schema(fn () => [
                ...Supply::where('is_active', true)
                    ->where('stock', '>', 0)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Supply $s) => TextInput::make('material_' . $s->id)
                        ->label($s->name)
                        ->numeric()
                        ->helperText('Quedan ' . rtrim(rtrim(number_format((float) $s->stock, 3, ',', '.'), '0'), ',') . ' ' . $s->unit))
                    ->all(),
            ])
            ->modalDescription('Se genera la venta con el valor cotizado, sale el material que declares y se cobra del saldo.')
            ->action(function (ProductionJob $record, array $data) {
                $materiales = [];

                foreach ($data as $clave => $valor) {
                    if (str_starts_with($clave, 'material_') && (float) $valor > 0) {
                        $materiales[(int) substr($clave, 9)] = (float) $valor;
                    }
                }

                self::intentar(
                    fn () => app(ProductionService::class)->entregar($record, $materiales, auth()->user()),
                    'Entregado y cobrado',
                );
            });
    }

    private static function rechazar(): Action
    {
        return Action::make('rechazar')
            ->label('Rechazar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (ProductionJob $r) => $r->estaAbierto())
            ->schema([
                Textarea::make('motivo')
                    ->label('Por qué')
                    ->required()
                    ->helperText('Lo va a leer quien pidió. Un rechazo sin motivo se pregunta de todos modos.'),
            ])
            ->action(fn (ProductionJob $r, array $data) => self::intentar(
                fn () => app(ProductionService::class)->rechazar($r, $data['motivo']),
                'Encargo rechazado',
            ));
    }

    private static function intentar(callable $accion, string $exito): void
    {
        try {
            $accion();
        } catch (ShopException $e) {
            Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($exito)->success()->send();
    }
}
