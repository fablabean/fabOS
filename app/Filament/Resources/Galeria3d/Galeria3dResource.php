<?php

namespace App\Filament\Resources\Galeria3d;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Galeria3d\Pages\EditModelo3d;
use App\Filament\Resources\Galeria3d\Pages\ListModelos3d;
use App\Filament\Resources\Galeria3d\Pages\SubirModelo3d;
use App\Filament\Resources\Galeria3d\Schemas\Modelo3dForm;
use App\Filament\Resources\Galeria3d\Tables\Modelos3dTable;
use App\Models\Modelo3d;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * La Galería 3D del sitio: los modelos que se suben desde aquí.
 *
 * En Comunicaciones, junto a lo demás que se enseña afuera. Subir un modelo
 * no pasa por un formulario de Filament: tiene su propia pantalla
 * (SubirModelo3d) porque el archivo se convierte y optimiza en el navegador
 * antes de enviarlo. Editar la ficha, los acabados y publicarlo sí es un
 * formulario normal.
 *
 * Quién entra se decide en Configuración → Roles y accesos, como en el resto.
 */
class Galeria3dResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Modelo3d::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $modelLabel = 'modelo 3D';

    protected static ?string $pluralModelLabel = 'Galería 3D';

    protected static ?string $slug = 'galeria-3d';

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Galería 3D';
    }

    public static function form(Schema $schema): Schema
    {
        return Modelo3dForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Modelos3dTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListModelos3d::route('/'),
            'create' => SubirModelo3d::route('/subir'),
            'edit'   => EditModelo3d::route('/{record}/edit'),
        ];
    }
}
