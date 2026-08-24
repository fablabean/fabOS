<?php

namespace App\Filament\Resources\WorkSchedules\Schemas;

use App\Models\WorkSchedule;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;

class WorkScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Persona')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                // Una jornada es una fila por día, porque cada día puede tener
                // horario distinto. Pero casi siempre se repite igual de lunes a
                // viernes, y obligar a repetir el formulario cinco veces es
                // pedirle a alguien que haga de copiadora.
                CheckboxList::make('weekdays')
                    ->label('Días')
                    ->helperText('Se crea una jornada por cada día marcado, todas con el mismo horario.')
                    ->options(WorkSchedule::DIAS)
                    ->columns(4)
                    ->required()
                    ->bulkToggleable()
                    ->visibleOn('create'),

                // Al editar se toca UNA jornada, y aquí el día es uno solo.
                //
                // Cada fila abre una franja horaria propia, así que unas
                // casillas aquí tendrían que decidir en silencio qué significa
                // marcar y desmarcar: ¿duplicar la jornada, moverla, o borrar
                // la del día que se quita? Esa última se llevaría por delante
                // el histórico —estas filas guardan vigencia— sin que nadie lo
                // hubiera pedido. Para varios días a la vez está el formulario
                // de creación; para quitar una jornada, el botón de Borrar.
                Select::make('weekday')
                    ->label('Día')
                    ->options(WorkSchedule::DIAS)
                    ->required()
                    ->hiddenOn('create'),

                TimePicker::make('starts_at')
                    ->label('Entrada')
                    ->seconds(false)
                    ->required(),

                TimePicker::make('ends_at')
                    ->label('Salida')
                    ->seconds(false)
                    ->required()
                    ->after('starts_at'),

                TextInput::make('break_minutes')
                    ->label('Descanso (minutos)')
                    ->helperText('Se descuenta de las horas efectivas. 8:00–17:30 con 60 min son 8,5 h.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(480)
                    ->required()
                    ->default(60),

                Radio::make('modalidad')
                    ->label('Modalidad')
                    ->options(WorkSchedule::MODALIDADES)
                    ->default(WorkSchedule::PRESENCIAL)
                    ->required()
                    ->inline()
                    ->inlineLabel(false)
                    ->helperText('Solo la presencial cuenta como cobertura: quien trabaja desde casa cumple su jornada, pero no abre el laboratorio ni acompaña una máquina.'),

                DatePicker::make('effective_from')
                    ->label('Vigente desde')
                    ->helperText('La jornada anterior no se borra: se cierra y queda el histórico.')
                    ->default(now())
                    ->required(),

                DatePicker::make('effective_until')
                    ->label('Vigente hasta')
                    ->placeholder('sin fecha de fin')
                    ->afterOrEqual('effective_from'),
            ]);
    }
}
