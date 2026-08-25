<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Models\Location;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->helperText('Sede, piso, sala, estante, gaveta, mesa o gabinete.')
                    ->required()
                    ->maxLength(255),

                Select::make('parent_id')
                    ->label('Dentro de')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->helperText('Déjalo vacío si es un nivel raíz. Lo que cuelga de otra ubicación hereda su espacio.'),

                // El espacio solo se declara arriba del todo. Una gaveta no está
                // «en un espacio»: está en un estante, que está en una sala.
                // Declararlo en cada nivel sería repetir el mismo dato tres
                // veces, y bastaría cambiar uno para que el árbol se contradiga.
                Select::make('space_id')
                    ->label('Espacio')
                    ->relationship('space', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get) => blank($get('parent_id')))
                    ->helperText('En qué sala o taller está. Todo lo que se guarde dentro lo hereda.'),

                Placeholder::make('espacio_heredado')
                    ->label('Espacio')
                    ->visible(fn ($get) => filled($get('parent_id')))
                    ->content(function ($get) {
                        $padre = Location::find($get('parent_id'));
                        $espacio = $padre?->espacio();

                        return $espacio
                            ? $espacio->name . ' — heredado de ' . $padre->name
                            : 'Sin espacio: ninguna ubicación por encima lo declara todavía.';
                    }),
            ]);
    }
}
