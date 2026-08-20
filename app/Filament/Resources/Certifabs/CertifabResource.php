<?php

namespace App\Filament\Resources\Certifabs;

use App\Filament\Resources\Certifabs\Pages\CreateCertifab;
use App\Filament\Resources\Certifabs\Pages\EditCertifab;
use App\Filament\Resources\Certifabs\Pages\ListCertifabs;
use App\Filament\Resources\Certifabs\Schemas\CertifabForm;
use App\Filament\Resources\Certifabs\Tables\CertifabsTable;
use App\Models\Certifab;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CertifabResource extends Resource
{
    protected static ?string $model = Certifab::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'Certifab';

    protected static ?string $pluralModelLabel = 'Certifabs';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Personas';
    }

    public static function form(Schema $schema): Schema
    {
        return CertifabForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CertifabsTable::configure($table);
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
            'index' => ListCertifabs::route('/'),
            'create' => CreateCertifab::route('/create'),
            'edit' => EditCertifab::route('/{record}/edit'),
        ];
    }
}
