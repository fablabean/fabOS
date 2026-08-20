<?php

namespace App\Filament\Resources\ProductionJobs;

use App\Filament\Resources\ProductionJobs\Pages\CreateProductionJob;
use App\Filament\Resources\ProductionJobs\Pages\EditProductionJob;
use App\Filament\Resources\ProductionJobs\Pages\ListProductionJobs;
use App\Filament\Resources\ProductionJobs\Schemas\ProductionJobForm;
use App\Filament\Resources\ProductionJobs\Tables\ProductionJobsTable;
use App\Models\ProductionJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductionJobResource extends Resource
{
    protected static ?string $model = ProductionJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $modelLabel = 'Encargo';

    protected static ?string $pluralModelLabel = 'Encargos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Tienda';
    }

    /** El numero que importa al entrar: cuantos encargos estan esperando. */
    public static function getNavigationBadge(): ?string
    {
        $enCola = ProductionJob::whereIn('status', ProductionJob::EN_COLA)->count();

        return $enCola ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductionJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionJobsTable::configure($table);
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
            'index' => ListProductionJobs::route('/'),
            'create' => CreateProductionJob::route('/create'),
            'edit' => EditProductionJob::route('/{record}/edit'),
        ];
    }
}
