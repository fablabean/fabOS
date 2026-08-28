<?php

namespace App\Filament\Resources\ShiftAssignments;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\ShiftAssignments\Pages\CreateShiftAssignment;
use App\Filament\Resources\ShiftAssignments\Pages\EditShiftAssignment;
use App\Filament\Resources\ShiftAssignments\Pages\ListShiftAssignments;
use App\Filament\Resources\ShiftAssignments\Schemas\ShiftAssignmentForm;
use App\Filament\Resources\ShiftAssignments\Tables\ShiftAssignmentsTable;
use App\Models\ShiftAssignment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShiftAssignmentResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = ShiftAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $modelLabel = 'Jornada programada';

    protected static ?string $pluralModelLabel = 'Jornadas programadas';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Jornadas';
    }

    public static function form(Schema $schema): Schema
    {
        return ShiftAssignmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShiftAssignmentsTable::configure($table);
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
            'index' => ListShiftAssignments::route('/'),
            'create' => CreateShiftAssignment::route('/create'),
            'edit' => EditShiftAssignment::route('/{record}/edit'),
        ];
    }
}
