<?php

namespace App\Filament\Resources\Locations\Schemas;

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
                    ->helperText('Sede, piso, sala, estante o gaveta.')
                    ->required()
                    ->maxLength(255),

                Select::make('parent_id')
                    ->label('Dentro de')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Déjalo vacío si es un nivel raíz, como una sede.'),
            ]);
    }
}
