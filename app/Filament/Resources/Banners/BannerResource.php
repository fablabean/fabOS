<?php

namespace App\Filament\Resources\Banners;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Filament\Resources\Banners\Schemas\BannerForm;
use App\Filament\Resources\Banners\Tables\BannersTable;
use App\Models\Banner;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * El banner de la portada (§3, portal publico).
 *
 * Va en Comunicaciones y no en Configuracion a proposito: esto no es un ajuste
 * del sistema, es lo que el laboratorio le esta contando a quien llega. Lo
 * escribe quien comunica.
 */
class BannerResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $modelLabel = 'lámina del banner';

    protected static ?string $pluralModelLabel = 'Banner de la portada';

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function form(Schema $schema): Schema
    {
        return BannerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BannersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'edit'   => EditBanner::route('/{record}/edit'),
        ];
    }
}
