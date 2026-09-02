<?php

namespace App\Filament\Resources\Contenidos;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Contenidos\Pages\ListContenidos;
use App\Models\Contenido;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * El banco de contenido, visto desde el laboratorio (§21).
 *
 * Es la única pantalla del panel a la que entra **Comunicaciones**: vienen a
 * buscar material para divulgación, no a mirar reservas ni saldos. Por eso el
 * permiso de aquí es más ancho que el del resto del backoffice, y el de todos
 * los demás recursos sigue siendo el estrecho.
 */
class ContenidoResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Contenido::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static ?string $modelLabel = 'aporte';

    // «Aportes» y no «Contenido»: es lo mismo visto desde quien lo sube. El
    // sitio publico lo llama igual, y el laboratorio puede reconocerlos con
    // FabCoins, cosa que «contenido» no sugiere por ningun lado.
    protected static ?string $pluralModelLabel = 'Aportes';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }


    /** Se sube desde el teléfono, no desde aquí. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ContenidoTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContenidos::route('/'),
        ];
    }
}
