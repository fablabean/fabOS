<?php

namespace App\Filament\Resources\Supplies;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Supplies\Pages\CreateSupply;
use App\Filament\Resources\Supplies\Pages\EditSupply;
use App\Filament\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Resources\Supplies\Schemas\SupplyForm;
use App\Filament\Resources\Supplies\Tables\SuppliesTable;
use App\Models\Supply;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SupplyResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Supply::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Insumo';

    protected static ?string $pluralModelLabel = 'Insumos';

    protected static ?int $navigationSort = 3;

    /**
     * Buscar un insumo desde cualquier pantalla (§7).
     *
     * También por referencia: el número que trae la caja es lo que se tiene
     * a mano al reponer, y el nombre de catálogo casi nunca coincide con el
     * del proveedor.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Área'       => $record->area?->name,
            'Existencia' => $record->stock . ' ' . $record->unit,
            'Referencia' => $record->sku,
        ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('area');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Compras';
    }

    public static function form(Schema $schema): Schema
    {
        return SupplyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliesTable::configure($table);
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
            'index' => ListSupplies::route('/'),
            'create' => CreateSupply::route('/create'),
            'edit' => EditSupply::route('/{record}/edit'),
        ];
    }
}
