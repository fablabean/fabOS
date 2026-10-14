<?php

namespace App\Filament\Resources\Anuncios;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Anuncios\Pages\CreateAnuncio;
use App\Filament\Resources\Anuncios\Pages\EditAnuncio;
use App\Filament\Resources\Anuncios\Pages\ListAnuncios;
use App\Filament\Resources\Anuncios\Schemas\AnuncioForm;
use App\Filament\Resources\Anuncios\Tables\AnunciosTable;
use App\Models\Anuncio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Los anuncios con voz (§3).
 *
 * En Comunicaciones, junto al banner: es lo que el laboratorio cuenta a quien
 * llega, solo que además se oye.
 */
class AnuncioResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Anuncio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSpeakerWave;

    protected static ?string $modelLabel = 'anuncio';

    protected static ?string $pluralModelLabel = 'Anuncios con voz';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function form(Schema $schema): Schema
    {
        return AnuncioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnunciosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAnuncios::route('/'),
            'create' => CreateAnuncio::route('/create'),
            'edit'   => EditAnuncio::route('/{record}/edit'),
        ];
    }
}
