<?php

namespace App\Filament\Resources\Credenciales;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Credenciales\Pages\CreateCredencial;
use App\Filament\Resources\Credenciales\Pages\EditCredencial;
use App\Filament\Resources\Credenciales\Pages\ListCredenciales;
use App\Filament\Resources\Credenciales\Schemas\CredencialForm;
use App\Filament\Resources\Credenciales\Tables\CredencialesTable;
use App\Models\Credencial;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las claves con que el laboratorio entra a sus servicios (§19).
 *
 * Existe por una situacion concreta: cuando la persona que monto el proveedor
 * de correo se va, el laboratorio se queda fuera de su propio servicio y nadie
 * sabe ni a que cuenta pertenece.
 *
 * **El filtro por dueño vive en la consulta base**, no en la tabla. Una lista
 * que filtra bien pero deja abrir por URL lo que no enseña es peor que no
 * filtrar: da por seguro algo que no lo es. Aqui no hay ninguna pantalla,
 * buscador ni exportacion que pueda saltarselo.
 */
class CredencialResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Credencial::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'credencial';

    protected static ?string $pluralModelLabel = 'Credenciales';

    /*
     * La direccion, a mano.
     *
     * Derivada del nombre del modelo salia `credenciales/credencials`, que es
     * la clase de direccion que alguien pega en un chat y da verguenza.
     */
    protected static ?string $slug = 'credenciales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Software y claves';
    }

    /**
     * Todo lo que salga de aqui ya viene filtrado por dueño.
     *
     * Filament construye sobre esta consulta la lista, el buscador global, la
     * resolucion del registro al abrir una direccion y cualquier relacion: con
     * el filtro aqui, ninguna de esas puertas puede quedarse fuera de la regla.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visiblesPara(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return CredencialForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CredencialesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCredenciales::route('/'),
            'create' => CreateCredencial::route('/create'),
            'edit' => EditCredencial::route('/{record}/edit'),
        ];
    }
}
