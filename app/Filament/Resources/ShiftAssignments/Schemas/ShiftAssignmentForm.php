<?php

namespace App\Filament\Resources\ShiftAssignments\Schemas;

use App\Models\Project;
use App\Models\Reservation;
use App\Models\ShiftAssignment;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Una jornada puntual fuera del patrón semanal (§5): un sábado de
 * acompañamiento, un evento, una apertura por un proyecto.
 *
 * Era el formulario crudo de Filament: etiquetas en inglés, «assigned by»
 * como un número, la aceptación como un campo que se escribía a mano. Aquí
 * se pregunta lo que hay que decidir —quién, cuándo, por qué, si cuenta como
 * extra— y lo demás lo pone el sistema: quién la asignó es quien la crea, y
 * aceptada se marca con su botón, no tecleando una fecha.
 */
class ShiftAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $tz = config('fabos.lab.timezone');

        return $schema
            ->components([
                Section::make('Quién y cuándo')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Persona')
                            ->options(fn () => \App\Filament\Componentes\SelectorDePersona::equipo())
                            ->searchable()
                            ->required()
                            ->columnSpanFull(),

                        DateTimePicker::make('starts_at')
                            ->label('Empieza')
                            ->seconds(false)
                            ->minutesStep(15)
                            ->required(),

                        DateTimePicker::make('ends_at')
                            ->label('Termina')
                            ->seconds(false)
                            ->minutesStep(15)
                            ->required()
                            ->after('starts_at'),
                    ]),

                Section::make('Por qué')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reason')
                            ->label('Motivo')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Apertura del sábado para el evento de robótica')
                            ->columnSpanFull(),

                        /*
                         * El proyecto, si la jornada es por uno: se ve desde
                         * el proyecto cuanto tiempo extra costo, y desde la
                         * jornada por que se abrio. Antes iba escrito en el
                         * motivo, y no servia mas que para leerse.
                         */
                        Select::make('project_id')
                            ->label('Proyecto')
                            ->options(fn () => Project::query()
                                ->where('status', 'activo')
                                ->whereNot('stage', 'cierre')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (Project $p) => [$p->id => $p->code . ' · ' . $p->name]))
                            ->searchable()
                            ->placeholder('Ninguno')
                            ->helperText('Si la jornada se abre por un proyecto. Queda ligada a él.'),

                        /*
                         * La reserva, si la jornada es por una: el sabado que
                         * alguien pidio el laboratorio de VR y hay que abrirle.
                         * Cuando se aprueba una solicitud fuera de horario esto
                         * se llena solo; aqui es para las que se programan a mano.
                         */
                        Select::make('reservation_id')
                            ->label('Reserva')
                            ->options(fn () => Reservation::query()
                                ->with('reservable', 'user')
                                ->whereIn('status', ['solicitada', 'confirmada'])
                                ->where('ends_at', '>=', now()->subDay())
                                ->orderBy('starts_at')
                                ->limit(200)
                                ->get()
                                ->mapWithKeys(fn (Reservation $r) => [$r->id => self::etiquetaDeReserva($r, $tz)]))
                            ->searchable()
                            ->placeholder('Ninguna')
                            ->helperText('Si la jornada es para abrir o atender una reserva: un espacio, un equipo.')
                            ->columnSpanFull(),

                        Toggle::make('counts_as_overtime')
                            ->label('Cuenta como hora extra')
                            ->default(true)
                            ->helperText('Apagado: se compensa con tiempo y no consume el tope semanal de extras.'),
                    ]),

                Section::make('Seguimiento')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('asignada_por')
                            ->label('Asignada por')
                            ->content(fn (?ShiftAssignment $record) => $record?->assignedBy?->name ?? (auth()->user()?->name . ' (al guardar)')),

                        Placeholder::make('aceptada')
                            ->label('Aceptada')
                            ->content(fn (?ShiftAssignment $record) => $record?->accepted_at
                                ? 'El ' . $record->accepted_at->timezone($tz)->format('d/m/Y H:i')
                                : 'Pendiente. Se marca desde la lista con «Marcar aceptada», o la persona desde su cuenta.'),

                        Textarea::make('conflict_note')
                            ->label('Conflicto reportado')
                            ->rows(2)
                            ->placeholder('Lo que la persona dijo si no puede: otra jornada, un compromiso.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /** «#280 · Lab. VR · sáb 26/09 08:00–12:00 · Ana»: lo justo para reconocerla. */
    public static function etiquetaDeReserva(Reservation $r, string $tz): string
    {
        $desde = $r->starts_at->timezone($tz);
        $hasta = $r->ends_at->timezone($tz);

        return '#' . $r->id
            . ' · ' . $r->nombreDelRecurso()
            . ' · ' . $desde->isoFormat('ddd D/MM HH:mm') . '–' . $hasta->format('H:i')
            . ($r->user ? ' · ' . $r->user->name : '');
    }
}
