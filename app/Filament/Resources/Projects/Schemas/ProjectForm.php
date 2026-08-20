<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('La idea')
                    ->description('Lo primero es que quede anotada. El resto se completa después.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre del proyecto')->required()->columnSpanFull(),

                        Select::make('source')
                            ->label('Por dónde llegó')
                            ->options(Project::ORIGENES)
                            ->default('correo')
                            ->required(),

                        TextInput::make('code')
                            ->label('Código')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se genera solo.'),

                        Textarea::make('summary')
                            ->label('La idea en dos frases')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('objective')
                            ->label('Qué se compromete a entregar')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Se afina en el brief, pero conviene escribirlo desde el principio.'),
                    ]),

                Section::make('Quién pide')
                    ->description('Puede no tener cuenta: una empresa que escribe por WhatsApp no debería registrarse para que le anoten la idea.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organization')->label('Organización'),
                        TextInput::make('contact_name')->label('Persona de contacto'),
                        TextInput::make('contact_email')->label('Correo')->email(),
                        TextInput::make('contact_phone')->label('Teléfono'),

                        Select::make('requested_by')
                            ->label('Si ya tiene cuenta')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                    ]),

                Section::make('Quién responde')
                    ->columns(2)
                    ->schema([
                        Select::make('lead_id')
                            ->label('Responsable')
                            ->options(fn () => User::whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('El laboratorio responde como institución, pero siempre recae en una persona. Sin responsable el proyecto no avanza de etapa.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        Select::make('stage')
                            ->label('Etapa')
                            ->options(Project::ETAPAS)
                            ->default('idea')
                            ->required()
                            ->helperText('Se mueve desde el listado, que comprueba las compuertas.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Project::ESTADOS)
                            ->default('activo')
                            ->required(),
                    ]),

                Section::make('Compromisos')
                    ->columns(3)
                    ->schema([
                        TextInput::make('agreed_value')
                            ->label('Valor acordado')
                            ->numeric()
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('En pesos.'),

                        DatePicker::make('starts_on')->label('Arranca'),
                        DatePicker::make('due_on')->label('Se entrega'),

                        Textarea::make('notes')->label('Notas internas')->columnSpanFull(),

                        Textarea::make('closing_notes')
                            ->label('Notas de cierre')
                            ->columnSpanFull()
                            ->helperText('Qué se entregó, qué quedó pendiente, qué aprendimos.'),
                    ]),
            ]);
    }
}
