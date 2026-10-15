<?php

namespace App\Filament\Resources\Publicaciones;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Publicaciones\Pages\CreatePublicacion;
use App\Filament\Resources\Publicaciones\Pages\EditPublicacion;
use App\Filament\Resources\Publicaciones\Pages\ListPublicaciones;
use App\Filament\Resources\Publicaciones\Pages\Parrilla;
use App\Filament\Resources\Publicaciones\Schemas\PublicacionForm;
use App\Filament\Resources\Publicaciones\Tables\PublicacionesTable;
use App\Models\Publicacion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * La parrilla de redes (§21).
 *
 * Entra por el calendario del mes, que es como se piensa una parrilla; la
 * lista está al lado para buscar y filtrar. Con las sugerencias de lo que
 * fabOS sabe que viene: cursos que abren, proyectos que ya tienen página,
 * material nuevo en el banco, semanas vacías.
 */
class PublicacionResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Publicacion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'publicación';

    protected static ?string $pluralModelLabel = 'Parrilla de redes';

    protected static ?int $navigationSort = 0;

    // En español: Filament la pluralizaba en inglés, «/publicacions».
    protected static ?string $slug = 'parrilla-de-redes';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    /** Lo que espera a la dirección, a la vista en el menú. */
    public static function getNavigationBadge(): ?string
    {
        $n = Publicacion::where('estado', 'por_aprobar')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Por aprobar';
    }

    public static function form(Schema $schema): Schema
    {
        return PublicacionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PublicacionesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\TareasRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Parrilla::route('/'),
            'lista'  => ListPublicaciones::route('/lista'),
            'resumen' => Pages\ResumenDelMes::route('/resumen'),
            'create' => CreatePublicacion::route('/create'),
            'edit'   => EditPublicacion::route('/{record}/edit'),
        ];
    }
}
