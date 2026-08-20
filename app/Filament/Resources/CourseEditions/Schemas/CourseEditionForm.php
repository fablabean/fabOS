<?php

namespace App\Filament\Resources\CourseEditions\Schemas;

use App\Models\CourseEdition;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourseEditionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('La cohorte')
                    ->columns(2)
                    ->schema([
                        Select::make('course_id')
                            ->label('Curso')
                            ->relationship('course', 'name')
                            ->searchable()
                            ->required(),

                        TextInput::make('code')
                            ->label('Código')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Se genera solo al crear.'),

                        Select::make('instructor_id')
                            ->label('Instructor')
                            ->options(fn () => User::whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                            ->searchable(),

                        Select::make('space_id')->label('Dónde')->relationship('space', 'name'),

                        DatePicker::make('starts_on')->label('Empieza')->required(),
                        DatePicker::make('ends_on')->label('Termina'),

                        TextInput::make('schedule_note')
                            ->label('Horario')
                            ->placeholder('Martes y jueves, 14:00 a 17:00')
                            ->columnSpanFull(),

                        TextInput::make('capacity')
                            ->label('Cupo')
                            ->numeric()
                            ->default(12)
                            ->required()
                            ->helperText('Sobreinscribir significa gente de pie en un taller con máquinas.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(CourseEdition::ESTADOS)
                            ->default('planeada')
                            ->required()
                            ->helperText('Solo una edición «abierta» admite inscripciones.'),

                        Textarea::make('notes')->label('Notas')->columnSpanFull(),
                    ]),
            ]);
    }
}
