<?php

namespace App\Filament\Resources\LedgerAccounts;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Filament\Resources\LedgerAccounts\Tables\LedgerAccountsTable;
use App\Models\LedgerAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Saldos en FabCoins.
 *
 * No hay formulario y no lo habrá: una cuenta no se edita, se mueve con
 * asientos. El único botón que abona es «Abonar», y también escribe una
 * transacción como cualquier otra.
 */
class LedgerAccountResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = LedgerAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $modelLabel = 'Cuenta';

    protected static ?string $pluralModelLabel = 'Cuentas y saldos';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function table(Table $table): Table
    {
        return LedgerAccountsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerAccounts::route('/'),
        ];
    }
}
