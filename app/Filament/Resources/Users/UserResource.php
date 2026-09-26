<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    /** Cómo se llama un registro cuando se le nombra suelto: en la
     *  búsqueda de arriba, en un desplegable, en un gestor de relación. */
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Persona';

    protected static ?string $pluralModelLabel = 'Personas';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Personas';
    }

    /**
     * Buscar a alguien desde cualquier pantalla (§5).
     *
     * También por documento y correo: en el mostrador se pregunta «¿a nombre
     * de quién?» y lo que se tiene a mano es la cédula del carnet, no cómo
     * quedó escrito el nombre. Y los nombres se escriben de muchas maneras
     * —con tilde, sin tilde, con dos apellidos o con uno—, así que buscar sólo
     * por nombre falla justo cuando hay alguien esperando.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'document_number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Categoría' => $record->category?->name,
            'Correo'    => $record->email,
            'Estado'    => $record->status === 'activo' ? null : ucfirst((string) $record->status),
        ]);
    }

    /** Con la categoría ya traída: si no, es una consulta por resultado. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('category');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => \App\Filament\Resources\Users\Pages\ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
