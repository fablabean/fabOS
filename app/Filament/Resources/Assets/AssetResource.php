<?php

namespace App\Filament\Resources\Assets;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\RelationManagers\AdvisorsRelationManager;
use App\Filament\Resources\Assets\Schemas\AssetForm;
use App\Filament\Resources\Assets\Tables\AssetsTable;
use App\Models\Asset;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AssetResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Activo';

    protected static ?string $pluralModelLabel = 'Activos';

    protected static ?int $navigationSort = 3;

    /**
     * Buscar un equipo desde cualquier pantalla (§7).
     *
     * Por marca, modelo, serie y placa además del nombre. Quien tiene el
     * equipo delante lee lo que dice la etiqueta —«Elegoo Neptune 4»— o el
     * número de inventario, y no el nombre con el que quedó registrado, que
     * suele llevar además un «1» o un «2» de la unidad.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'brand', 'model', 'serial', 'asset_tag'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Área'      => $record->area?->name,
            'Estado'    => Asset::ESTADOS[$record->status] ?? $record->status,
            'Ubicación' => $record->location?->name,
        ]);
    }

    /** Con área y ubicación ya traídas: si no, dos consultas por resultado. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['area', 'location']);
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Laboratorio';
    }

    public static function form(Schema $schema): Schema
    {
        return AssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AdvisorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'create' => CreateAsset::route('/create'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
