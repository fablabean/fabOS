<?php

namespace App\Filament\Resources\RiskFamilies;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\RiskFamilies\Pages\CreateRiskFamily;
use App\Filament\Resources\RiskFamilies\Pages\EditRiskFamily;
use App\Filament\Resources\RiskFamilies\Pages\ListRiskFamilies;
use App\Filament\Resources\RiskFamilies\Schemas\RiskFamilyForm;
use App\Filament\Resources\RiskFamilies\Tables\RiskFamiliesTable;
use App\Models\RiskFamily;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RiskFamilyResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = RiskFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'Familia de riesgo';

    protected static ?string $pluralModelLabel = 'Familias de riesgo';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Laboratorio';
    }

    public static function form(Schema $schema): Schema
    {
        return RiskFamilyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RiskFamiliesTable::configure($table);
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
            'index' => ListRiskFamilies::route('/'),
            'create' => CreateRiskFamily::route('/create'),
            'edit' => EditRiskFamily::route('/{record}/edit'),
        ];
    }
}
