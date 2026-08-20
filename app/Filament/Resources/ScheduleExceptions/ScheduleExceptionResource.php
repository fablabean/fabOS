<?php

namespace App\Filament\Resources\ScheduleExceptions;

use App\Filament\Resources\ScheduleExceptions\Pages\CreateScheduleException;
use App\Filament\Resources\ScheduleExceptions\Pages\EditScheduleException;
use App\Filament\Resources\ScheduleExceptions\Pages\ListScheduleExceptions;
use App\Filament\Resources\ScheduleExceptions\Schemas\ScheduleExceptionForm;
use App\Filament\Resources\ScheduleExceptions\Tables\ScheduleExceptionsTable;
use App\Models\ScheduleException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScheduleExceptionResource extends Resource
{
    protected static ?string $model = ScheduleException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?string $modelLabel = 'Ausencia';

    protected static ?string $pluralModelLabel = 'Ausencias';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Jornadas';
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduleExceptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleExceptionsTable::configure($table);
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
            'index' => ListScheduleExceptions::route('/'),
            'create' => CreateScheduleException::route('/create'),
            'edit' => EditScheduleException::route('/{record}/edit'),
        ];
    }
}
