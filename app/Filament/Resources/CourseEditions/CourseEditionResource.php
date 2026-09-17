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

    /**
     * Los preinscritos con los que nadie ha hablado todavía, en las cohortes
     * que siguen planeadas. Alguien que dejó su correo para Fab Academy y no
     * recibe una llamada en dos semanas ya se fue a otra parte.
     */
    public static function getNavigationBadge(): ?string
    {
        $porAtender = \App\Models\Preenrollment::query()
            ->where('status', 'preinscrito')
            ->whereHas('edition', fn ($q) => $q->where('status', 'planeada'))
            ->count();

        return $porAtender > 0 ? (string) $porAtender : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Preinscritos que todavía no han confirmado';
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
            RelationManagers\PreenrollmentsRelationManager::class,
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
