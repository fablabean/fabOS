<?php

namespace App\Filament\Resources\Paginas;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Paginas\Pages\CreatePagina;
use App\Filament\Resources\Paginas\Pages\EditPagina;
use App\Filament\Resources\Paginas\Pages\ListPaginas;
use App\Filament\Resources\Paginas\Schemas\PaginaForm;
use App\Filament\Resources\Paginas\Tables\PaginasTable;
use App\Models\Pagina;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Las páginas del sitio público (§3, portal público).
 *
 * Va en Comunicaciones, al lado del banner y de los logos, porque es la misma
 * clase de trabajo: lo que el laboratorio le está contando a quien llega. El
 * banner da la frase; aquí está el sitio donde cabe lo que no es una frase.
 */
class PaginaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Pagina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'página';

    protected static ?string $pluralModelLabel = 'Páginas del sitio';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'titulo';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function form(Schema $schema): Schema
    {
        return PaginaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaginasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaginas::route('/'),
            'create' => CreatePagina::route('/create'),
            'edit' => EditPagina::route('/{record}/edit'),
        ];
    }
}
