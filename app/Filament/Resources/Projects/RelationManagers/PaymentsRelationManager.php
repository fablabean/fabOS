<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Componentes\ArchivoPrivado;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Services\Projects\PagosDeProyecto;
use App\Services\Projects\ProjectException;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Los pagos del proyecto (§11).
 *
 * Se pide un pago con su valor; el cliente responde con el comprobante, su
 * nombre y su documento; aqui se mira el comprobante y se valida o se
 * devuelve con un motivo. Con el pago validado, la produccion puede empezar.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagos';

    protected static ?string $modelLabel = 'pago';

    protected static ?string $pluralModelLabel = 'pagos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('requested_at')
                    ->label('Pedido')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->description(fn (ProjectPayment $p) => $p->requestedBy?->name),

                TextColumn::make('amount')
                    ->label('Valor')
                    ->state(fn (ProjectPayment $p) => $p->valorFormateado())
                    ->description(fn (ProjectPayment $p) => $p->concept)
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProjectPayment::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ProjectPayment::VALIDADO  => 'success',
                        ProjectPayment::ENVIADO   => 'warning',
                        ProjectPayment::RECHAZADO => 'danger',
                        default                   => 'gray',
                    })
                    ->description(fn (ProjectPayment $p) => $p->notes),

                TextColumn::make('payer_name')
                    ->label('Pagó')
                    ->placeholder('—')
                    ->description(fn (ProjectPayment $p) => $p->payer_document ? 'Doc. ' . $p->payer_document : null),

                TextColumn::make('receipt_path')
                    ->label('Comprobante')
                    ->state(fn (ProjectPayment $p) => $p->receipt_path ? 'Ver' : null)
                    ->url(fn (ProjectPayment $p) => $p->receipt_path ? ArchivoPrivado::url($p->receipt_path, 'comprobante-' . $p->id . '.' . pathinfo($p->receipt_path, PATHINFO_EXTENSION)) : null)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->description(fn (ProjectPayment $p) => $p->submitted_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i')),

                TextColumn::make('validatedBy.name')
                    ->label('Validó')
                    ->placeholder('—')
                    ->description(fn (ProjectPayment $p) => $p->validated_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i')),
            ])
            ->description(fn () => $this->descuentosDichos())
            ->headerActions([
                $this->descontar(),
                self::pedir()->record(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([
                Action::make('validar')
                    ->label('Validar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ProjectPayment $p) => $p->status === ProjectPayment::ENVIADO)
                    ->modalHeading(fn (ProjectPayment $p) => 'Validar el pago de ' . $p->titulo())
                    ->modalDescription(fn (ProjectPayment $p) => 'A nombre de ' . $p->payer_name . ', documento ' . $p->payer_document . '. Mira el comprobante antes: con esto el proyecto puede pasar a producción.')
                    ->schema([
                        Textarea::make('nota')->label('Algo que anotar')->rows(2)->helperText('Opcional. Queda en la conversación.'),
                    ])
                    ->action(function (ProjectPayment $record, array $data) {
                        try {
                            app(PagosDeProyecto::class)->validar($record, auth()->user(), $data['nota'] ?? null);
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo validar')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Pago validado')->body('Le avisamos al cliente.')->send();
                    }),

                Action::make('rechazar')
                    ->label('Devolver')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ProjectPayment $p) => $p->status === ProjectPayment::ENVIADO)
                    ->modalHeading('Devolver el comprobante')
                    ->schema([
                        Textarea::make('motivo')->label('Por qué no sirve')->rows(3)->required()
                            ->helperText('Se lo decimos al cliente tal cual, con el QR otra vez por si tiene que volver a pagar.'),
                    ])
                    ->action(function (ProjectPayment $record, array $data) {
                        try {
                            app(PagosDeProyecto::class)->rechazar($record, auth()->user(), $data['motivo']);
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo devolver')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Comprobante devuelto')->body('Le pedimos otro al cliente.')->send();
                    }),
            ])
            ->emptyStateHeading('Sin pagos pedidos')
            ->emptyStateDescription('Pide un pago con «Pedir un pago»: el cliente recibe el valor con el QR del banco y responde aquí con el comprobante.');
    }

    /** Lo ya descontado en FabCoins, encima de la tabla. Nulo si no hay nada. */
    private function descuentosDichos(): ?string
    {
        $moneda = config('fabos.currency.code');

        $lineas = PagosDeProyecto::descuentosDe($this->getOwnerRecord())
            ->map(fn (array $d) => $d['fabcoins'] . ' ' . $moneda . ' a ' . $d['persona'] . ' (' . $d['cuando']
                . ($d['quien'] ? ', ' . $d['quien'] : '') . ')');

        return $lineas->isEmpty() ? null : 'Descontado en FabCoins: ' . $lineas->join(' · ') . '.';
    }

    /**
     * Descontar FabCoins: una parte del valor acordado se paga con el saldo
     * de la persona, y el resto se cobra en pesos con «Pedir un pago».
     */
    private function descontar(): Action
    {
        $moneda = config('fabos.currency.code');
        $saldo = fn ($id) => ($u = \App\Models\User::find($id))
            ? PagosDeProyecto::fabcoins(app(\App\Services\Ledger\LedgerService::class)->saldoDe($u)) . ' ' . $moneda
            : null;

        return Action::make('descontarFabcoins')
            ->label('Descontar FabCoins')
            ->icon('heroicon-o-minus-circle')
            ->color('gray')
            ->modalHeading(fn () => 'Descontar FabCoins por ' . $this->getOwnerRecord()->code)
            ->modalDescription('Sale del saldo de la persona y queda en la conversación del proyecto. '
                . 'Lo que falte por pagar se cobra con «Pedir un pago».')
            ->modalSubmitActionLabel('Descontar')
            ->schema([
                Select::make('persona')
                    ->label('A quién')
                    ->searchable()
                    ->required()
                    ->live()
                    ->default(fn () => $this->getOwnerRecord()->requested_by)
                    // Filament le pasa el texto por nombre: tiene que llamarse $search.
                    ->getSearchResultsUsing(fn (string $search) => \App\Models\User::query()
                        ->where(fn ($q) => $q->where('name', 'ilike', '%' . $search . '%')->orWhere('email', 'ilike', '%' . $search . '%'))
                        ->orderBy('name')->limit(20)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => \App\Models\User::find($value)?->name)
                    ->helperText(fn (Get $get) => ($s = $saldo($get('persona'))) ? 'Saldo disponible: ' . $s . '.' : null),

                TextInput::make('fabcoins')
                    ->label('Cuántos ' . $moneda)
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),

                Textarea::make('motivo')
                    ->label('Por qué')
                    ->rows(2)
                    ->maxLength(200)
                    ->placeholder('Vale 10 ' . $moneda . ': se descuentan 8 y se cobran 2 en pesos.')
                    ->helperText('Queda en la conversación y en su historial de movimientos.'),
            ])
            ->action(function (array $data) {
                $persona = \App\Models\User::findOrFail($data['persona']);

                try {
                    app(PagosDeProyecto::class)->descontarFabcoins(
                        $this->getOwnerRecord(), $persona, (float) $data['fabcoins'], $data['motivo'] ?? null, auth()->user(),
                    );
                } catch (ProjectException $e) {
                    Notification::make()->danger()->title('No se pudo descontar')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()
                    ->title('Descontados ' . PagosDeProyecto::fabcoins((int) round($data['fabcoins'] * (int) config('fabos.currency.minor_units'))) . ' ' . config('fabos.currency.code'))
                    ->body('A ' . $persona->name . '. Le quedan ' . PagosDeProyecto::fabcoins(app(\App\Services\Ledger\LedgerService::class)->saldoDe($persona)) . '.')
                    ->send();
            });
    }

    /** La accion de pedir un pago, compartida con la lista de proyectos. */
    public static function pedir(): Action
    {
        return Action::make('pedirPago')
            ->label('Pedir un pago')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->modalHeading(fn (Project $record) => 'Pedir un pago por ' . $record->code)
            ->modalDescription(fn (Project $record) => 'A ' . ($record->correoDeLaPropuesta() ?? 'sin correo')
                . ', con el QR del banco adjunto y el enlace para responder con el comprobante.'
                . (Settings::qrDePagos() ? '' : ' Todavía no hay QR: súbelo en Finanzas → Pagos.'))
            ->schema([
                TextInput::make('valor')
                    ->label('Valor a pagar (pesos)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->default(fn (Project $record) => (int) ($record->agreed_value ?: $record->estimated_value) ?: null),

                TextInput::make('concepto')
                    ->label('Por qué')
                    ->placeholder('Anticipo del 50 % · Saldo final')
                    ->maxLength(120),

                Textarea::make('mensaje')
                    ->label('Algo que quieras añadir')
                    ->rows(3)
                    ->helperText('Va dentro del correo. Opcional.'),
            ])
            // Verlo antes de mandarlo: el correo con el QR y la seccion de
            // pago, en otra pestaña, con el formulario intacto.
            ->extraModalFooterActions(fn (Action $action) => [
                $action->makeModalSubmitAction('vistaPrevia', arguments: ['vista' => true])
                    ->label('Vista previa')
                    ->color('gray'),
            ])
            ->action(function (Project $record, array $data, array $arguments, Action $action, $livewire) {
                if ($arguments['vista'] ?? false) {
                    $token = \Illuminate\Support\Str::random(40);
                    \Illuminate\Support\Facades\Cache::put('cobro:' . $token, $data + ['project_id' => $record->id], now()->addHour());

                    $livewire->js('window.open(' . json_encode(route('panel.cobro', ['project' => $record, 'token' => $token])) . ', "_blank")');
                    $action->halt();
                }

                try {
                    $pago = app(PagosDeProyecto::class)->pedir(
                        $record, (int) $data['valor'], $data['concepto'] ?? null, $data['mensaje'] ?? null, auth()->user(),
                    );
                } catch (ProjectException $e) {
                    Notification::make()->danger()->title('No se pudo pedir el pago')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()
                    ->title('Pago pedido: ' . $pago->titulo())
                    ->body('Le llegó a ' . $record->correoDeLaPropuesta() . ' con el QR. Queda en la conversación.')
                    ->send();
            });
    }
}
