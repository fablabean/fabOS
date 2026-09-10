<?php

namespace App\Filament\Resources\Logos;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Logos\Pages\CreateLogo;
use App\Filament\Resources\Logos\Pages\EditLogo;
use App\Filament\Resources\Logos\Pages\ListLogos;
use App\Filament\Resources\Logos\Schemas\LogoForm;
use App\Filament\Resources\Logos\Tables\LogosTable;
use App\Models\Logo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Los logos de la portada: quién respalda al laboratorio (§3).
 *
 * Van en una franja debajo del banner. Se suben, se ordenan arrastrando y
 * se apagan sin borrarlos, como las láminas del banner.
 */
class LogoResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Logo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $modelLabel = 'logo';

    protected static ?string $pluralModelLabel = 'Logos de la portada';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function form(Schema $schema): Schema
    {
        return LogoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LogosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListLogos::route('/'),
            'create' => CreateLogo::route('/create'),
            'edit'   => EditLogo::route('/{record}/edit'),
        ];
    }
}
