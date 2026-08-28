<?php

namespace App\Filament\Resources\MaintenancePlans;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\MaintenancePlans\Pages\CreateMaintenancePlan;
use App\Filament\Resources\MaintenancePlans\Pages\EditMaintenancePlan;
use App\Filament\Resources\MaintenancePlans\Pages\ListMaintenancePlans;
use App\Filament\Resources\MaintenancePlans\Schemas\MaintenancePlanForm;
use App\Filament\Resources\MaintenancePlans\Tables\MaintenancePlansTable;
use App\Models\MaintenancePlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MaintenancePlanResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = MaintenancePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'Plan preventivo';

    protected static ?string $pluralModelLabel = 'Planes preventivos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Mantenimiento';
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenancePlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenancePlansTable::configure($table);
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
            'index' => ListMaintenancePlans::route('/'),
            'create' => CreateMaintenancePlan::route('/create'),
            'edit' => EditMaintenancePlan::route('/{record}/edit'),
        ];
    }
}
