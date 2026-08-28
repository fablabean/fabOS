<?php

namespace App\Filament\Resources\LedgerTransactions;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\LedgerTransactions\Pages\ListLedgerTransactions;
use App\Filament\Resources\LedgerTransactions\Tables\LedgerTransactionsTable;
use App\Models\LedgerTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * El histórico de movimientos. Solo se mira.
 *
 * Una transacción no se edita ni se borra: si algo salió mal se corrige con
 * otra que la compense. Esa es la razón de que aquí no haya ni formulario ni
 * botón de eliminar.
 */
class LedgerTransactionResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = LedgerTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'Movimiento';

    protected static ?string $pluralModelLabel = 'Movimientos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function table(Table $table): Table
    {
        return LedgerTransactionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerTransactions::route('/'),
        ];
    }
}
