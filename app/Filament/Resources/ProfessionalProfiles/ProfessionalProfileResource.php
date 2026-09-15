<?php

namespace App\Filament\Resources\ProfessionalProfiles;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\ProfessionalProfiles\Pages\CreateProfessionalProfile;
use App\Filament\Resources\ProfessionalProfiles\Pages\EditProfessionalProfile;
use App\Filament\Resources\ProfessionalProfiles\Pages\ListProfessionalProfiles;
use App\Filament\Resources\ProfessionalProfiles\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ProfessionalProfiles\Schemas\ProfessionalProfileForm;
use App\Filament\Resources\ProfessionalProfiles\Tables\ProfessionalProfilesTable;
use App\Models\ProfessionalProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProfessionalProfileResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = ProfessionalProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'Perfil profesional';

    protected static ?string $pluralModelLabel = 'Perfiles profesionales';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Personas';
    }

    /** Lo que espera ser presentado es lo que hace que alguien abra esto. */
    public static function getNavigationBadge(): ?string
    {
        $cuantos = ProfessionalProfile::enEstado('propuesto')->count();

        return $cuantos > 0 ? (string) $cuantos : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'perfiles esperando presentarse a la Universidad';
    }

    public static function form(Schema $schema): Schema
    {
        return ProfessionalProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfessionalProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfessionalProfiles::route('/'),
            'create' => CreateProfessionalProfile::route('/create'),
            'edit' => EditProfessionalProfile::route('/{record}/edit'),
        ];
    }
}
