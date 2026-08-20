<?php

namespace App\Filament\Resources\RiskFamilies\Schemas;

use App\Models\Certifab;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RiskFamilyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->description('Una familia agrupa equipos que comparten el mismo riesgo y la misma inducción. La regla se declara aquí una vez y gobierna todos sus equipos.')
                    ->columns(2)
                    ->schema([
                        Select::make('area_id')
                            ->label('Área')
                            ->relationship('area', 'name')
                            ->required()
                            ->preload(),

                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // El identificador se deriva del nombre para no
                            // obligar a inventarlo, pero queda editable.
                            ->afterStateUpdated(fn ($state, $set, $get) => filled($get('slug'))
                                ? null
                                : $set('slug', Str::slug((string) $state))),

                        TextInput::make('slug')
                            ->label('Identificador')
                            ->helperText('Interno, sin espacios ni tildes.')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Condiciones para operar')
                    ->description('Esto es lo que el motor de reservas evalúa antes de dejar reservar cualquier equipo de la familia.')
                    ->columns(2)
                    ->schema([
                        Select::make('required_course_level')
                            ->label('Nivel de curso exigido')
                            ->options(array_combine(Certifab::NIVELES, Certifab::NIVELES))
                            ->helperText('Es el prerrequisito. Lo que finalmente habilita es el certifab del equipo.')
                            ->placeholder('Sin exigencia de nivel'),

                        Toggle::make('requires_companion')
                            ->label('Requiere acompañamiento')
                            ->helperText('Nunca se opera en solitario: exige un colaborador certificado en jornada.'),

                        Textarea::make('safety_notes')
                            ->label('Notas de seguridad')
                            ->helperText('Elementos de protección, ventilación, materiales prohibidos.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
