<?php

namespace App\Filament\Resources\ShortLinks;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\ShortLinks\Pages\CreateShortLink;
use App\Filament\Resources\ShortLinks\Pages\EditShortLink;
use App\Filament\Resources\ShortLinks\Pages\ListShortLinks;
use App\Filament\Resources\ShortLinks\Schemas\ShortLinkForm;
use App\Filament\Resources\ShortLinks\Tables\ShortLinksTable;
use App\Models\ShortLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Enlaces cortos con QR (§7).
 *
 * El laboratorio pega codigos en carteles, en piezas y en fichas de curso. Con
 * la direccion larga impresa, el dia que cambia la pagina el cartel queda
 * mintiendo y no hay forma de saber si alguien lo escaneo alguna vez.
 */
class ShortLinkResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = ShortLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $modelLabel = 'Enlace corto';

    protected static ?string $pluralModelLabel = 'Enlaces y QR';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Operación';
    }

    public static function form(Schema $schema): Schema
    {
        return ShortLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShortLinksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListShortLinks::route('/'),
            'create' => CreateShortLink::route('/crear'),
            'edit'   => EditShortLink::route('/{record}/editar'),
        ];
    }
}
