<?php

namespace App\Filament\Resources\Wishes;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Wishes\Pages\CreateWish;
use App\Filament\Resources\Wishes\Pages\EditWish;
use App\Filament\Resources\Wishes\Pages\ListWishes;
use App\Filament\Resources\Wishes\Schemas\WishForm;
use App\Filament\Resources\Wishes\Tables\WishesTable;
use App\Models\Wish;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WishResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Wish::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $modelLabel = 'Deseo';

    protected static ?string $pluralModelLabel = 'Lista de deseos';

    /*
     * Antes que presupuestos y solicitudes, que es el orden en que ocurren las
     * cosas: primero se desea, luego se pide con qué, luego se compra.
     */
    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Compras';
    }

    /** Cuántos deseos siguen esperando. Sin esto la lista se olvida. */
    public static function getNavigationBadge(): ?string
    {
        $cuantos = Wish::enEstado('abierto')->count();

        return $cuantos > 0 ? (string) $cuantos : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'deseos todavía en la lista';
    }

    public static function form(Schema $schema): Schema
    {
        return WishForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WishesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWishes::route('/'),
            'create' => CreateWish::route('/create'),
            'edit' => EditWish::route('/{record}/edit'),
        ];
    }
}
