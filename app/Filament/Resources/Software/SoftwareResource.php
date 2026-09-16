<?php

namespace App\Filament\Resources\Software;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Software\Pages\CreateSoftware;
use App\Filament\Resources\Software\Pages\EditSoftware;
use App\Filament\Resources\Software\Pages\ListSoftware;
use App\Filament\Resources\Software\RelationManagers\CredencialesRelationManager;
use App\Filament\Resources\Software\RelationManagers\InstalacionesRelationManager;
use App\Filament\Resources\Software\RelationManagers\PuestosRelationManager;
use App\Filament\Resources\Software\Schemas\SoftwareForm;
use App\Filament\Resources\Software\Tables\SoftwareTable;
use App\Models\Software;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * El software del laboratorio: lo instalado y lo de la nube (§19).
 *
 * El aviso del menu cuenta lo que vence pronto o ya vencio. Es el numero por
 * el que se abre esta seccion: nadie entra a «ver el inventario de software»,
 * se entra porque algo hay que renovar.
 */
class SoftwareResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Software::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $modelLabel = 'programa';

    protected static ?string $pluralModelLabel = 'Software y suscripciones';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Software y claves';
    }

    public static function getNavigationBadge(): ?string
    {
        $cuantos = Software::porRenovar()->count();

        return $cuantos > 0 ? (string) $cuantos : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Software::enUso()
            ->whereDate('renueva_el', '<', now(config('fabos.lab.timezone')))
            ->exists()
            ? 'danger'
            : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Vencido o por vencer en los próximos '.Software::AVISO_DIAS.' días';
    }

    public static function form(Schema $schema): Schema
    {
        return SoftwareForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SoftwareTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InstalacionesRelationManager::class,
            PuestosRelationManager::class,
            CredencialesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftware::route('/'),
            'create' => CreateSoftware::route('/create'),
            'edit' => EditSoftware::route('/{record}/edit'),
        ];
    }
}
