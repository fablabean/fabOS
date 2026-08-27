<?php

namespace App\Filament\Resources\PurchaseRequests\Tables;

use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Purchasing\PurchasingException;
use App\Services\Purchasing\PurchasingService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (PurchaseRequest $r) => $r->justification),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PurchaseRequest::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'borrador'         => 'gray',
                        'enviada'          => 'info',
                        'aprobada',
                        'en_compra'        => 'warning',
                        'recibida'         => 'success',
                        'recibida_parcial' => 'warning',
                        default            => 'danger',
                    }),

                TextColumn::make('budget.name')->label('Presupuesto')->placeholder('sin asignar'),

                TextColumn::make('requestedBy.name')->label('Pide')->toggleable(),

                TextColumn::make('lineas')
                    ->label('Líneas')
                    ->alignEnd()
                    ->state(fn (PurchaseRequest $r) => $r->items()->count()),

                TextColumn::make('total')
                    ->label('Total estimado')
                    ->alignEnd()
                    ->weight('medium')
                    ->state(fn (PurchaseRequest $r) => self::pesos($r->loadMissing('items')->totalEstimado()))
                    // De donde sale: quien escribe 1.989.000 y ve 2.366.910 sin
                    // explicacion deja de fiarse de la cifra, y una cifra en la
                    // que no se confia no se usa para decidir.
                    ->description(fn (PurchaseRequest $r) => $r->impuesto() > 0
                        ? self::pesos($r->subtotal()) . ' + ' . round($r->tasaDeImpuesto() * 100) . '% de impuesto'
                        : 'sin impuesto'),

                TextColumn::make('recibido')
                    ->label('Recibido')
                    ->alignEnd()
                    ->state(fn (PurchaseRequest $r) => self::pesos($r->loadMissing('items')->recibidoEnPesos())),

                TextColumn::make('submitted_at')
                    ->label('Enviada')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(PurchaseRequest::ESTADOS),
                SelectFilter::make('budget_id')->label('Presupuesto')->relationship('budget', 'name'),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::verRequisicion(),
                    self::enviar(),
                    self::aprobar(),
                    self::rechazar(),
                    self::enCompra(),
                    self::recibir(),
                    self::cancelar(),
                    EditAction::make(),
                ]),
            ]);
    }

    private static function verRequisicion(): Action
    {
        return Action::make('requisicion')
            ->label('Ver requisición')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->url(fn (PurchaseRequest $r) => route('compras.requisicion', $r))
            ->openUrlInNewTab();
    }

    private static function enviar(): Action
    {
        return Action::make('enviar')
            ->label('Enviar')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalDescription('Queda con fecha de envío y deja de ser un borrador editable a la ligera.')
            ->visible(fn (PurchaseRequest $r) => $r->status === 'borrador')
            ->action(fn (PurchaseRequest $r) => self::intentar(fn () => app(PurchasingService::class)->enviar($r), 'Solicitud enviada'));
    }

    private static function aprobar(): Action
    {
        return Action::make('aprobar')
            ->label('Aprobar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            // Tambien desde borrador. El servicio siempre lo permitio; la
            // pantalla no, y eso obligaba a quien aprueba a "enviarse" a si
            // mismo una solicitud para poder aprobarla. En un laboratorio donde
            // quien pide y quien aprueba son la misma persona, ese paso no
            // controla nada: solo hace que la plata no quede comprometida
            // porque alguien no adivino que faltaba un clic.
            ->visible(fn (PurchaseRequest $r) => in_array($r->status, ['enviada', 'borrador'], true)
                && auth()->user()?->hasAnyRole([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]))
            ->modalHeading('Aprobar la solicitud')
            ->modalDescription(fn (PurchaseRequest $r) => sprintf(
                'Quedan comprometidos %s contra el presupuesto. %s',
                self::pesos($r->totalEstimado()),
                $r->status === 'borrador' ? 'Es un borrador: se aprueba sin pasar por enviada.' : '',
            ))
            ->schema([
                Select::make('budget_id')
                    ->label('Contra qué presupuesto')
                    ->options(fn () => Budget::where('status', 'vigente')
                        ->orderByDesc('year')
                        ->get()
                        ->mapWithKeys(fn (Budget $b) => [
                            $b->id => $b->name . ' ' . $b->year . ' — quedan ' . self::pesos($b->disponible()),
                        ]))
                    ->default(fn (PurchaseRequest $r) => $r->budget_id)
                    ->required(),
            ])
            ->action(fn (PurchaseRequest $r, array $data) => self::intentar(
                fn () => app(PurchasingService::class)->aprobar($r, auth()->user(), Budget::find($data['budget_id'])),
                'Solicitud aprobada: el presupuesto queda comprometido',
            ));
    }

    private static function rechazar(): Action
    {
        return Action::make('rechazar')
            ->label('Rechazar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (PurchaseRequest $r) => in_array($r->status, ['enviada', 'aprobada'], true)
                && auth()->user()?->hasAnyRole([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]))
            ->schema([
                Textarea::make('motivo')
                    ->label('Por qué')
                    ->required()
                    ->helperText('Quien pidió va a leer esto. Un rechazo sin motivo se pregunta de todos modos.'),
            ])
            ->action(fn (PurchaseRequest $r, array $data) => self::intentar(
                fn () => app(PurchasingService::class)->rechazar($r, auth()->user(), $data['motivo']),
                'Solicitud rechazada',
            ));
    }

    private static function enCompra(): Action
    {
        return Action::make('enCompra')
            ->label('Marcar en compra')
            ->icon('heroicon-o-shopping-cart')
            ->visible(fn (PurchaseRequest $r) => $r->status === 'aprobada')
            ->requiresConfirmation()
            ->modalDescription('Compras de la Universidad ya tramitó la orden. Sigue comprometiendo presupuesto hasta que llegue.')
            ->action(fn (PurchaseRequest $r) => self::intentar(
                fn () => app(PurchasingService::class)->marcarEnCompra($r),
                'Marcada como tramitada por compras',
            ));
    }

    /**
     * Recibir, entero o por partes: casi nunca llega todo junto.
     *
     * Y no siempre es mercancía. Por aquí pasan también unos honorarios, un
     * curso contratado o un servicio: cosas que se reciben igual —se dan por
     * cumplidas y ejecutan el presupuesto— pero que nadie llama mercancía.
     * Llamarlas así hace dudar de si el botón es el correcto.
     */
    private static function recibir(): Action
    {
        return Action::make('recibir')
            ->label('Recibir lo pedido')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('success')
            ->visible(fn (PurchaseRequest $r) => in_array($r->status, ['aprobada', 'en_compra', 'recibida_parcial'], true))
            ->schema(fn (PurchaseRequest $r) => [
                ...$r->items->map(fn ($linea) => TextInput::make('linea_' . $linea->id)
                    ->label($linea->description)
                    ->helperText(sprintf(
                        'Pendiente: %s de %s %s%s',
                        rtrim(rtrim(number_format($linea->pendiente(), 3, ',', '.'), '0'), ','),
                        rtrim(rtrim(number_format((float) $linea->quantity, 3, ',', '.'), '0'), ','),
                        $linea->unit,
                        // Lo que no repone un insumo del catalogo no mueve
                        // existencias: puede ser un servicio, unos honorarios o
                        // algo fisico que todavia no tiene ficha. Decirlo evita
                        // buscar despues por que el stock no se movio.
                        $linea->supply ? ' · entra al inventario' : ' · no entra al inventario',
                    ))
                    ->numeric()
                    ->default(fn () => $linea->pendiente() ?: null)
                    ->disabled($linea->pendiente() <= 0))->all(),

                Textarea::make('memo')
                    ->label('Observaciones de la recepción')
                    ->placeholder('Llegaron 6 de 10 rollos; el resto queda pendiente con el proveedor. O: se dictaron 3 de los 5 cursos.'),
            ])
            ->action(function (PurchaseRequest $r, array $data) {
                $recibido = [];

                foreach ($r->items as $linea) {
                    $recibido[$linea->id] = (float) ($data['linea_' . $linea->id] ?? 0);
                }

                self::intentar(
                    fn () => app(PurchasingService::class)->recibir($r, $recibido, auth()->user(), $data['memo'] ?? null),
                    'Recepción registrada. Lo que repone insumos ya entró al inventario.',
                );
            });
    }

    private static function cancelar(): Action
    {
        return Action::make('cancelar')
            ->label('Cancelar')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (PurchaseRequest $r) => ! in_array($r->status, PurchaseRequest::CERRADAS, true))
            ->schema([Textarea::make('motivo')->label('Por qué')->required()])
            ->action(fn (PurchaseRequest $r, array $data) => self::intentar(
                fn () => app(PurchasingService::class)->cancelar($r, $data['motivo']),
                'Solicitud cancelada',
            ));
    }

    private static function intentar(callable $accion, string $exito): void
    {
        try {
            $accion();
        } catch (PurchasingException $e) {
            Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($exito)->success()->send();
    }

    private static function pesos(int $pesos): string
    {
        return config('fabos.money.symbol') . number_format($pesos, 0, ',', '.');
    }
}
