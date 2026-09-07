<?php

namespace App\Filament\Resources\ScheduleExceptions\Schemas;

use App\Models\ScheduleException;
use App\Models\WorkSchedule;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Una ausencia o un bloqueo (§5).
 *
 * Dos alcances, y el formulario cambia con el que se elija:
 *
 *  · **Días enteros**: vacaciones, incapacidad, un festivo. Lo de siempre.
 *  · **Una franja del día**: la clase de inglés de los jueves de cuatro a
 *    cinco, una cita el martes a las diez. Puede ir en unas fechas o
 *    repetirse cada semana hasta una fecha, o hasta nuevo aviso.
 */
class ScheduleExceptionForm
{
    public static function configure(Schema $schema): Schema
    {
        $esFranja = fn (Get $get) => $get('alcance') === 'franja';

        // Se repite si es de franja y hay algun dia marcado: uno al editar,
        // varios al crear.
        $seRepite = fn (Get $get) => $esFranja($get) && filled(array_filter((array) $get('weekdays')));

        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Persona')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Todo el laboratorio')
                    ->helperText('Sin persona aplica a todo el laboratorio: un festivo, un cierre, una reunión de todo el equipo.'),

                Select::make('kind')
                    ->label('Tipo')
                    ->options(ScheduleException::TIPOS)
                    ->required()
                    ->default('bloqueo'),

                /*
                 * No se guarda: se deduce de si hay horas. Existe para que el
                 * formulario pregunte una sola cosa clara en vez de dejar
                 * cuatro campos opcionales a interpretar.
                 */
                Radio::make('alcance')
                    ->label('Alcance')
                    ->options([
                        'dia'    => 'Días enteros',
                        'franja' => 'Una franja del día',
                    ])
                    ->default('dia')
                    ->inline()
                    ->inlineLabel(false)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, ?ScheduleException $record) => $component->state(
                        $record?->esDeFranja() ? 'franja' : 'dia',
                    ))
                    ->columnSpanFull(),

                /*
                 * Hora de PARED, no un instante: «de cuatro a cinco» es a las
                 * cuatro de cada jueves en el reloj del laboratorio. Sin fijar
                 * la zona, el panel la corria cinco horas.
                 */
                TimePicker::make('starts_time')
                    ->label('Desde las')
                    ->timezone(config('app.timezone'))
                    ->seconds(false)
                    ->visible($esFranja)
                    ->required($esFranja)
                    ->dehydrateStateUsing(fn ($state, Get $get) => $esFranja($get) ? $state : null),

                TimePicker::make('ends_time')
                    ->label('Hasta las')
                    ->timezone(config('app.timezone'))
                    ->seconds(false)
                    ->visible($esFranja)
                    ->required($esFranja)
                    ->after('starts_time')
                    ->dehydrateStateUsing(fn ($state, Get $get) => $esFranja($get) ? $state : null),

                /*
                 * Se marcan varios dias: la clase es martes y jueves a la
                 * misma hora, y pedir el formulario dos veces es poner a
                 * alguien de copiadora. Se guarda un bloqueo por dia, igual
                 * que las jornadas, porque cada uno puede cambiar o borrarse
                 * por su cuenta despues. Al editar, el bloqueo se queda con
                 * su dia y los demas marcados se crean como copias.
                 *
                 * No se guarda tal cual: las paginas lo traducen a filas.
                 */
                CheckboxList::make('weekdays')
                    ->label('Se repite')
                    ->options(WorkSchedule::DIAS)
                    ->columns(4)
                    ->bulkToggleable()
                    ->visible($esFranja)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, ?ScheduleException $record) => $component->state(
                        $record?->weekday !== null ? [(int) $record->weekday] : [],
                    ))
                    ->helperText('Cada semana, los días marcados, entre las fechas de abajo. Un bloqueo por cada día, todos con la misma franja. Sin marcar ninguno, es solo en esas fechas.')
                    ->columnSpanFull(),

                DatePicker::make('starts_on')
                    ->label(fn (Get $get) => $seRepite($get) ? 'Desde el' : 'Desde')
                    ->required()
                    ->default(now()),

                DatePicker::make('ends_on')
                    ->label(fn (Get $get) => $seRepite($get) ? 'Hasta el' : 'Hasta')
                    ->afterOrEqual('starts_on')
                    // Una ausencia de dias tiene fin; un bloqueo que se repite
                    // puede no tenerlo todavia: «hasta nuevo aviso».
                    ->required(fn (Get $get) => ! $seRepite($get))
                    ->placeholder(fn (Get $get) => $seRepite($get) ? 'hasta nuevo aviso' : null)
                    ->helperText(fn (Get $get) => $seRepite($get)
                        ? 'La fecha en que termina el semestre, el curso, lo que sea. Vacío es hasta nuevo aviso.'
                        : null),

                TextInput::make('note')
                    ->label('Motivo')
                    ->maxLength(255)
                    ->placeholder('Clase de inglés')
                    ->columnSpanFull()
                    ->helperText('Se le muestra a quien intente asignar esa hora: «tiene esa hora bloqueada (clase de inglés)».'),
            ]);
    }
}
