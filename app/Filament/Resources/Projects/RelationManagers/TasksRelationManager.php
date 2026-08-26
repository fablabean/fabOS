<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectTask;
use App\Models\ProjectTaskEvidence;
use App\Models\User;
use App\Services\Media\OptimizadorDeImagen;
use App\Services\Projects\ProjectService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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

                Repeater::make('evidence')
                    ->relationship()
                    ->label('Evidencia')
                    ->columnSpanFull()
                    ->addActionLabel('Añadir evidencia')
                    ->defaultItems(0)
                    ->collapsible()
                    ->itemLabel(fn (array $state) => $state['caption'] ?? null)
                    ->helperText('«Se hizo» es una afirmación; una foto es una comprobación. Dentro de dos años es todo lo que queda, y es lo que se le enseña a quien encargó el trabajo.')
                    ->columns(2)
                    ->schema([
                        Select::make('kind')
                            ->label('Qué es')
                            ->options(ProjectTaskEvidence::TIPOS)
                            ->default('foto')
                            ->live()
                            ->required(),

                        TextInput::make('caption')->label('Qué se ve'),

                        FileUpload::make('file_path')
                            ->label('Foto')
                            ->visible(fn (Get $get) => $get('kind') === 'foto')
                            ->columnSpanFull()
                            ->image()
                            // Disco privado A PROPOSITO: son fotos del trabajo
                            // de un cliente. En el disco publico quedarian en
                            // una URL que cualquiera puede pedir sin sesion. Se
                            // sirven por una ruta que comprueba quien pide.
                            ->directory('proyectos/evidencia')
                            // Generoso: lo pesado se encoge, no se rechaza.
                            ->maxSize(20480)
                            // El navegador la encoge ANTES de subirla: una foto
                            // de telefono son tres o cuatro megas, y por el tunel
                            // esa lentitud se vuelve un 502 sin explicacion.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(2000)
                            ->imageResizeTargetHeight(2000)
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(OptimizadorDeImagen::class)
                                    ->guardar($file, 'proyectos/evidencia', 'local')
                            )
                            ->helperText('Súbela tal cual: el sistema la endereza y la comprime.'),

                        TextInput::make('url')
                            ->label('Enlace')
                            ->visible(fn (Get $get) => $get('kind') !== 'foto')
                            ->url()
                            ->columnSpanFull()
                            ->helperText('A YouTube, Drive o donde ya viva. Un video de dos minutos no tiene por qué pasar por aquí.'),
                    ]),
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

                CreateAction::make()->label('Añadir tarea'),
            ])
            ->recordActions([
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
