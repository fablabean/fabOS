<?php

namespace App\Filament\Resources\Spaces\Schemas;

use App\Models\Space;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SpaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, $set, $get) => filled($get('slug'))
                                ? null
                                : $set('slug', Str::slug((string) $state))),

                        TextInput::make('slug')->label('Identificador')->required()->maxLength(255),

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Space::TIPOS)
                            ->default('fisico')
                            ->required()
                            ->helperText('Los virtuales se reservan igual: salas, licencias, mentorías.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name')->preload(),
                        Select::make('location_id')->label('Ubicación')->relationship('location', 'name')->searchable()->preload(),
                        TextInput::make('capacity')->label('Aforo')->numeric()->placeholder('sin límite'),
                    ]),

                Section::make('Cómo se reserva')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_reservable')->label('Se puede reservar')->default(true),

                        Toggle::make('is_production_space')
                            ->label('Espacio de producción')
                            ->helperText('Donde se asesora, se monta y corren los trabajos.'),

                        TextInput::make('setup_minutes')
                            ->label('Preparación (min)')
                            ->numeric()->default(0)
                            ->helperText('Se bloquea antes de cada reserva.'),

                        TextInput::make('cleanup_minutes')
                            ->label('Limpieza (min)')
                            ->numeric()->default(0)
                            ->helperText('Se bloquea después.'),
                    ]),
            ]);
    }
}
