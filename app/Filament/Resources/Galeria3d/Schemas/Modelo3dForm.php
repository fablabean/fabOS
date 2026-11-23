<?php

namespace App\Filament\Resources\Galeria3d\Schemas;

use App\Models\Modelo3d;
use App\Support\Galeria3d;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class Modelo3dForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // La foto, las cifras de la optimización y el archivo de origen.
            View::make('filament.galeria-3d.resumen')->columnSpanFull(),

            Section::make('En la galería')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Toggle::make('publicado')
                        ->label('Publicado')
                        ->helperText('Mientras no esté publicado solo se ve aquí.'),
                    Toggle::make('escala_fija')
                        ->label('Verlo a tamaño real en realidad aumentada')
                        ->helperText('Para muebles, piezas y objetos de medida conocida: en el teléfono no se podrá agrandar ni encoger.'),
                ]),

            Section::make('Ficha')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')->label('Nombre')->required()->maxLength(120),
                    TextInput::make('slug')
                        ->label('Dirección')
                        ->prefix(fn () => route('galeria.index') . '/')
                        ->required()
                        ->maxLength(80)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->rule(fn (?Modelo3d $record) => Rule::unique('modelos_3d', 'slug')->ignore($record))
                        ->rule(fn () => Rule::notIn(array_keys(config('galeria3d.modelos', []))))
                        ->helperText('Minúsculas, números y guiones. Si la cambias, los QR y enlaces que ya se compartieron dejan de funcionar.'),
                    Select::make('categoria')
                        ->label('Técnica principal')
                        ->options(config('galeria3d.categorias', []))
                        ->required(),
                    TextInput::make('tecnica')->label('Proceso')->placeholder('Impresión 3D FDM')->maxLength(120),
                    TextInput::make('maquina')->label('Máquina')->placeholder('Impresora FDM, boquilla de 0,4 mm')->maxLength(160),
                    TextInput::make('material')->label('Material')->placeholder('PLA y tornillería M3')->maxLength(160),
                    Textarea::make('resumen')
                        ->label('Resumen')
                        ->rows(2)
                        ->maxLength(300)
                        ->columnSpanFull()
                        ->helperText('Una o dos frases: van debajo del nombre y en los buscadores.'),
                    Textarea::make('descripcion')
                        ->label('Descripción')
                        ->rows(4)
                        ->maxLength(5000)
                        ->columnSpanFull()
                        ->helperText('Cómo se hizo, quién lo hizo, para qué sirve.'),
                ]),

            Section::make('Acabados')
                ->description('Variantes que el público puede probar en la pestaña «Materiales»: otro color de filamento, la madera teñida, el metal anodizado… El original siempre está.')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    Repeater::make('acabados')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir un acabado')
                        ->defaultItems(0)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['nombre'] ?? null)
                        ->schema([
                            TextInput::make('nombre')->label('Nombre del acabado')->placeholder('PETG negro')->required()->maxLength(60),
                            Repeater::make('materiales')
                                ->label('Qué cambia')
                                ->addActionLabel('Cambiar otro material')
                                ->minItems(1)
                                ->defaultItems(1)
                                ->columns(5)
                                ->schema([
                                    Select::make('material')
                                        ->label('Material del modelo')
                                        ->options(fn (?Modelo3d $record) => collect($record?->nombresDeMateriales() ?? [])->mapWithKeys(fn ($n) => [$n => $n])->all())
                                        ->required()
                                        ->columnSpan(2),
                                    ColorPicker::make('color')->label('Color'),
                                    TextInput::make('metal')->label('Metal')->numeric()->minValue(0)->maxValue(1)->step(0.05)->placeholder('igual'),
                                    TextInput::make('rugosidad')->label('Rugosidad')->numeric()->minValue(0)->maxValue(1)->step(0.05)->placeholder('igual'),
                                ]),
                        ]),
                ]),
        ]);
    }
}
