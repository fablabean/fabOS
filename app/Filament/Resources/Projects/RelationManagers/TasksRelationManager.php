<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Componentes\CampoDeEvidencia;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Las tareas del proyecto.
 *
 * Esta lista y el tablero son la misma tabla: el estado pinta la columna del
 * Kanban y las fechas pintan la barra del Gantt.
 */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tareas';

    // Sin esto, el estado vacio de Filament dice «Cree un project member».
    protected static ?string $modelLabel = 'tarea';

    protected static ?string $pluralModelLabel = 'tareas';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->label('Tarea')->required()->columnSpanFull(),

                Select::make('status')
                    ->label('Estado')
                    ->options(ProjectTask::ESTADOS)
                    ->default('por_hacer')
                    ->required(),

                Select::make('assigned_to')
                    ->label('A cargo de')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                DatePicker::make('starts_on')
                    ->label('Empieza')
                    ->helperText('Sin fechas, la tarea solo vive en el tablero.'),

                DatePicker::make('due_on')->label('Termina'),

                TextInput::make('progress')
                    ->label('Avance')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),

                Toggle::make('is_milestone')
                    ->label('Es un hito')
                    ->helperText('Un compromiso con fecha, de los que se levanta acta.'),

                Textarea::make('description')->label('Detalle')->columnSpanFull(),

                CampoDeEvidencia::repetidor(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title')
                    ->label('Tarea')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (ProjectTask $r) => $r->assignedTo?->name),

                IconColumn::make('is_milestone')
                    ->label('Hito')
                    ->boolean()
                    ->trueIcon('heroicon-o-flag')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProjectTask::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'hecha'     => 'success',
                        'en_curso'  => 'info',
                        'bloqueada' => 'danger',
                        default     => 'gray',
                    }),

                TextColumn::make('due_on')
                    ->label('Termina')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (ProjectTask $r) => $r->estaVencida() ? 'danger' : null),

                TextColumn::make('progress')->label('Avance')->alignEnd()->suffix('%'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ProjectTask::ESTADOS),
            ])
            ->headerActions([
                // Los entregables ya son la lista de compromisos. Volver a
                // teclearlos como tareas es trabajo doble y una invitacion a
                // que las dos listas dejen de coincidir.
                Action::make('traerEntregables')
                    ->label('Traer los entregables')
                    ->icon('heroicon-o-flag')
                    ->color('gray')
                    ->visible(fn () => $this->getOwnerRecord()->deliverables()->whereNull('task_id')->exists())
                    ->requiresConfirmation()
                    ->modalHeading('Llevar los entregables al tablero')
                    ->modalDescription('Se crea un hito por cada entregable que todavía no sea tarea. Los que ya lo son se quedan como están.')
                    ->modalSubmitActionLabel('Crearlos')
                    ->action(function () {
                        $cuantas = app(ProjectService::class)
                            ->llevarEntregablesAlTablero($this->getOwnerRecord());

                        Notification::make()
                            ->success()
                            ->title($cuantas === 1 ? 'Se creó un hito' : "Se crearon {$cuantas} hitos")
                            ->body('Están en «por hacer», con la fecha del entregable o la del proyecto.')
                            ->send();
                    }),

                // Quien la escribe queda anotado: de eso depende que pueda
                // luego editarla o borrarla sin tener la seccion de Proyectos.
                CreateAction::make()
                    ->label('Añadir tarea')
                    ->mutateDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                /*
                 * Apartar tiempo para esta tarea (§10, §11).
                 *
                 * Quien lleva un proyecto necesita horas seguidas, y en esas
                 * horas no puede estar en una asesoria ni acompañando una
                 * sala. El bloque es una reserva del tiempo de la persona, y
                 * todo lo que reparte gente ya la respeta. Cada uno aparta el
                 * suyo; el del equipo lo aparta quien lleva el proyecto.
                 */
                Action::make('apartar')
                    ->label('Apartar tiempo')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (ProjectTask $record) => 'Apartar tiempo para «' . $record->title . '»')
                    ->modalDescription('En esas horas no se le asignan asesorías ni acompañamientos. Se puede apartar varias veces para la misma tarea.')
                    ->schema([
                        Select::make('para_quien')
                            ->label('De quién')
                            ->options(function (ProjectTask $record) {
                                $p = $record->project;
                                $gente = collect([$p?->lead])->merge($p?->members?->map->user ?? collect())
                                    ->push($record->assignedTo)->filter()->unique('id');

                                return $gente->sortBy('name')->mapWithKeys(fn (User $u) => [$u->id => $u->name])->all();
                            })
                            ->default(fn (ProjectTask $record) => $record->assigned_to ?? auth()->id())
                            ->required()
                            ->helperText('El tuyo lo apartas tú. El de otra persona, solo quien lleva el proyecto.'),

                        DateTimePicker::make('desde')->label('Desde')->seconds(false)->minutesStep(15)->required(),
                        DateTimePicker::make('hasta')->label('Hasta')->seconds(false)->minutesStep(15)->required()->after('desde'),
                    ])
                    ->action(function (ProjectTask $record, array $data) {
                        // El selector ya entrega UTC: no se vuelve a convertir.
                        $tz = config('fabos.lab.timezone');
                        $desde = \Illuminate\Support\Carbon::parse($data['desde'], config('app.timezone'))->setTimezone($tz);
                        $hasta = \Illuminate\Support\Carbon::parse($data['hasta'], config('app.timezone'))->setTimezone($tz);

                        try {
                            $bloque = app(\App\Services\Projects\TiempoDeProyecto::class)->apartar(
                                $record, User::findOrFail($data['para_quien']), $desde, $hasta, auth()->user(),
                            );
                        } catch (\App\Services\Booking\BookingException $e) {
                            Notification::make()->danger()->title('No se pudo apartar')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title('Tiempo apartado')
                            ->body($bloque->reservable?->name . ' queda ocupado el '
                                . $desde->format('d/m') . ' de ' . $desde->format('H:i') . ' a ' . $hasta->format('H:i') . '.')
                            ->send();
                    }),

                Action::make('mover')
                    ->label('Mover')
                    ->icon('heroicon-o-arrows-right-left')
                    ->schema([
                        Select::make('estado')
                            ->label('A qué columna')
                            ->options(ProjectTask::ESTADOS)
                            ->required(),
                    ])
                    ->action(function (ProjectTask $record, array $data) {
                        app(ProjectService::class)->moverTarea($record, $data['estado']);

                        Notification::make()->title('Tarea movida')->success()->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
