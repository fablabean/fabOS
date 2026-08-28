<?php

namespace App\Filament\Resources\CourseEditions;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\CourseEditions\Pages\CreateCourseEdition;
use App\Filament\Resources\CourseEditions\Pages\EditCourseEdition;
use App\Filament\Resources\CourseEditions\Pages\ListCourseEditions;
use App\Filament\Resources\CourseEditions\Schemas\CourseEditionForm;
use App\Filament\Resources\CourseEditions\Tables\CourseEditionsTable;
use App\Models\CourseEdition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CourseEditionResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = CourseEdition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'Edición';

    protected static ?string $pluralModelLabel = 'Ediciones';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Formación';
    }

    public static function form(Schema $schema): Schema
    {
        return CourseEditionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourseEditionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EnrollmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseEditions::route('/'),
            'create' => CreateCourseEdition::route('/create'),
            'edit' => EditCourseEdition::route('/{record}/edit'),
        ];
    }
}
