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
                            ->options(fn () => \App\Filament\Componentes\SelectorDePersona::equipo())
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

                // Solo dice algo en un curso al que se entra por preinscripción;
                // en los demás una edición planeada es un borrador del equipo.
                Section::make('Preinscripción')
                    ->description('Mientras la cohorte esté «planeada», la gente se preinscribe desde la página del programa. No ocupa cupo: sirve para decidir si se abre.')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (?CourseEdition $record) => ! $record?->course?->by_preenrollment)
                    ->schema([
                        TextInput::make('minimum_to_open')
                            ->label('Cuántos hacen falta para abrir')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Se enseña en la página pública con una barra de avance. Vacío, no se promete ningún umbral.'),

                        DatePicker::make('preenroll_until')
                            ->label('Preinscripciones hasta')
                            ->helperText('Vacío: hasta que la cohorte se abra o se cancele.'),

                        TextInput::make('price_note')
                            ->label('Inversión, como se le dice a la gente')
                            ->maxLength(200)
                            ->placeholder('3800 USD, en cuotas')
                            ->helperText('Texto libre: Fab Academy se paga en dólares y un número en la moneda del laboratorio no lo cuenta.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
