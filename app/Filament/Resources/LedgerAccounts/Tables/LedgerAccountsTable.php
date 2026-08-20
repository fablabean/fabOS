<?php

namespace App\Filament\Resources\LedgerAccounts\Tables;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Money\ChargeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('kind')
            ->columns([
                TextColumn::make('name')
                    ->label('Cuenta')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (LedgerAccount $r) => $r->code),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state) => $state === 'sistema' ? 'gray' : 'primary'),

                TextColumn::make('saldo')
                    ->label('Saldo')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (LedgerAccount $r) => number_format($r->saldo(), 2, ',', '.'))
                    ->color(fn (LedgerAccount $r) => $r->saldoMenor() < 0 ? 'danger' : 'success')
                    ->description(config('fabos.currency.code')),

                TextColumn::make('movimientos')
                    ->label('Movimientos')
                    ->alignEnd()
                    ->state(fn (LedgerAccount $r) => $r->entries()->count()),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(['usuario' => 'Personas', 'proyecto' => 'Proyectos', 'sistema' => 'Sistema']),
            ])
            ->recordActions([
                self::abonar(),
            ])
            ->headerActions([
                self::abonarA(),
            ]);
    }

    /** Abono directo sobre una cuenta ya existente. */
    private static function abonar(): Action
    {
        return Action::make('abonar')
            ->label('Abonar')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (LedgerAccount $r) => $r->kind === 'usuario' && auth()->user()?->can('create', LedgerAccount::class))
            ->schema(self::campos())
            ->action(function (LedgerAccount $record, array $data) {
                self::mover($record->owner, $data);
            });
    }

    /** Abono a cualquier persona, tenga cuenta abierta o no. */
    private static function abonarA(): Action
    {
        return Action::make('abonarA')
            ->label('Abonar a alguien')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn () => auth()->user()?->can('create', LedgerAccount::class))
            ->schema([
                Select::make('user_id')
                    ->label('Persona')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                ...self::campos(),
            ])
            ->action(function (array $data) {
                self::mover(User::find($data['user_id']), $data);
            });
    }

    /** @return array<int,\Filament\Forms\Components\Component> */
    private static function campos(): array
    {
        return [
            Select::make('concepto')
                ->label('Concepto')
                ->options([
                    'dotacion'     => 'Dotación institucional',
                    'bonificacion' => 'Bonificación por colaborar',
                    'recarga'      => 'Recarga con dinero ya recibido',
                ])
                ->default('bonificacion')
                ->required()
                ->live(),

            TextInput::make('importe')
                ->label('Importe')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->prefix(config('fabos.currency.code')),

            TextInput::make('referencia')
                ->label('Referencia del pago')
                ->helperText('Comprobante o número de la transacción. Evita que una recarga se cargue dos veces.')
                ->visible(fn (callable $get) => $get('concepto') === 'recarga')
                ->required(fn (callable $get) => $get('concepto') === 'recarga'),

            TextInput::make('periodo')
                ->label('Periodo')
                ->placeholder('2026-08')
                ->helperText('Una dotación por periodo: repetirla no abona dos veces.')
                ->visible(fn (callable $get) => $get('concepto') === 'dotacion')
                ->required(fn (callable $get) => $get('concepto') === 'dotacion'),

            Textarea::make('motivo')
                ->label('Motivo')
                ->placeholder('Apoyo en el curso de láser, mentoría a un proyecto…')
                ->visible(fn (callable $get) => $get('concepto') === 'bonificacion')
                ->required(fn (callable $get) => $get('concepto') === 'bonificacion'),
        ];
    }

    private static function mover(?User $usuario, array $data): void
    {
        if (! $usuario) {
            Notification::make()->title('No se encontró la persona')->danger()->send();

            return;
        }

        $cobros = app(ChargeService::class);
        $menor = (int) round(((float) $data['importe']) * config('fabos.currency.minor_units'));

        $transaccion = match ($data['concepto']) {
            'dotacion'     => $cobros->dotar($usuario, $menor, $data['periodo']),
            'recarga'      => $cobros->recargar($usuario, $menor, $data['referencia'], auth()->user()),
            'bonificacion' => $cobros->bonificar($usuario, $menor, $data['motivo'], auth()->user()),
        };

        Notification::make()
            ->title('Abono registrado')
            ->body('Saldo de ' . $usuario->name . ': ' .
                number_format(app(\App\Services\Ledger\LedgerService::class)->saldoDe($usuario) / config('fabos.currency.minor_units'), 2, ',', '.') .
                ' ' . config('fabos.currency.code') .
                ($transaccion?->wasRecentlyCreated === false ? ' (ya estaba registrado)' : ''))
            ->success()
            ->send();
    }
}
