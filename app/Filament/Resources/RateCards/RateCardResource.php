<?php

namespace App\Filament\Resources\RateCards;

use App\Filament\Resources\RateCards\Pages\CreateRateCard;
use App\Filament\Resources\RateCards\Pages\EditRateCard;
use App\Filament\Resources\RateCards\Pages\ListRateCards;
use App\Filament\Resources\RateCards\Schemas\RateCardForm;
use App\Filament\Resources\RateCards\Tables\RateCardsTable;
use App\Models\RateCard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RateCardResource extends Resource
{
    protected static ?string $model = RateCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'Tarifa';

    protected static ?string $pluralModelLabel = 'Tarifas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function form(Schema $schema): Schema
    {
        return RateCardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RateCardsTable::configure($table);
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
            'index' => ListRateCards::route('/'),
            'create' => CreateRateCard::route('/create'),
            'edit' => EditRateCard::route('/{record}/edit'),
        ];
    }
}
