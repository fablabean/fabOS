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

                        TextInput::make('title')
                            ->label('Nombre del grupo')
                            ->maxLength(120)
                            ->placeholder('Grupo sábados')
                            ->helperText('Solo si hay varios grupos de la misma actividad. Sale junto al nombre del curso.'),

                        TextInput::make('location')
                            ->label('Lugar')
                            ->maxLength(200)
                            ->placeholder('Auditorio, sede Norte')
                            ->helperText('Si no es un espacio del laboratorio. Si lo es, basta con «Dónde».'),

                        DatePicker::make('starts_on')->label('Empieza')->required(),
                        DatePicker::make('ends_on')->label('Termina'),

                        \Filament\Forms\Components\TimePicker::make('start_time')->label('Hora de inicio')->seconds(false),
                        \Filament\Forms\Components\TimePicker::make('end_time')->label('Hora de fin')->seconds(false),

                        TextInput::make('schedule_note')
                            ->label('Horario, en palabras')
                            ->placeholder('Martes y jueves, 14:00 a 17:00')
                            ->helperText('Para cuando no es un solo bloque. Si pones las horas de arriba, se usan esas.')
                            ->columnSpanFull(),

                        Select::make('audience')
                            ->label('Dirigida a')
                            ->options(CourseEdition::PUBLICOS)
                            ->default('ambos')
                            ->required()
                            ->helperText('Decide qué tipos de participante ofrece el formulario. La comunidad EAN se inscribe con su correo institucional.'),

                        \Filament\Forms\Components\Toggle::make('is_paid')
                            ->label('Es paga')
                            ->live()
                            ->inline(false),

                        TextInput::make('price')
                            ->label('Valor')
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('En pesos, sin puntos.')
                            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('is_paid')),

                        Textarea::make('payment_info')
                            ->label('Cómo se paga')
                            ->rows(2)
                            ->placeholder('Al inscribirte te enviamos el QR de pago. El cupo se confirma con el comprobante.')
                            ->helperText('Sale en la página y en el correo de confirmación.')
                            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('is_paid'))
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
                            ->helperText('Para publicar, cerrar inscripciones, reprogramar o cancelar usa los botones de arriba: dejan historial y avisan a los inscritos.'),

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
