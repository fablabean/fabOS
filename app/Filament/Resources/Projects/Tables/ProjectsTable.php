<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Media\OptimizadorDeImagen;
use App\Services\Projects\EliminarProyecto;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->weight('bold')
                    /*
                     * Encogida a su contenido.
                     *
                     * En una pantalla estrecha el navegador reparte el ancho
                     * segun el texto mas largo de cada columna, y la linea de
                     * abajo —«Formulario del sitio · Area o facultad de la
                     * Universidad»— pedia mas sitio que el nombre del proyecto.
                     * Resultado: el codigo ancho y vacio, y el nombre partido
                     * palabra por palabra en vertical.
                     *
                     * `1px` es la forma de decirle a una tabla «lo justo»: el
                     * resto del ancho se lo quedan las demas.
                     */
                    ->width('1px')
                    ->extraCellAttributes(['style' => 'white-space:nowrap'])
                    ->description(fn (Project $r) => (Project::ORIGENES[$r->source] ?? $r->source)
                        // Aceptada es el momento en que deja de ser una
                        // conversacion y pasa a ser un compromiso.
                        . ($r->accepted_at
                            ? ' · aceptada'
                            : ($r->proposal_sent_at
                                ? ' · propuesta ' . ($r->propuestaVigente()?->etiqueta() ?? 'enviada')
                                : ''))),

                ImageColumn::make('reference_image_path')
                    ->label('')
                    ->height(38)
                    ->extraImgAttributes(['style' => 'border-radius:.35rem;object-fit:cover'])
                    // Por la ruta con permiso, no por /storage.
                    ->getStateUsing(fn (Project $r) => $r->imagenDeReferencia()),

                TextColumn::make('name')
                    ->label('Proyecto')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    // Se queda con el ancho que sobra: es lo que se viene a
                    // leer, y el resto de columnas caben en lo suyo.
                    ->width('100%')
                    ->description(fn (Project $r) => $r->quienPide()),

                // De quien es el encargo: estaba pegado al codigo, donde
                // ensanchaba una columna que solo tiene que decir «PRY-2026-33».
                TextColumn::make('client_kind')
                    ->label('Cliente')
                    ->formatStateUsing(fn (?string $state) => Project::CLIENTES[$state] ?? $state)
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('stage')
                    ->width('1px')
                    ->label('Etapa')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Project::ETAPAS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'idea'      => 'gray',
                        'propuesta' => 'info',
                        'contrato',
                        'brief'     => 'warning',
                        'ejecucion' => 'primary',
                        default     => 'success',
                    }),

                TextColumn::make('lead.name')
                    ->label('Responsable')
                    ->placeholder('sin asignar')
                    ->color(fn (Project $r) => $r->lead_id ? null : 'danger'),

                TextColumn::make('avance')
                    ->label('Avance')
                    ->alignEnd()
                    ->state(fn (Project $r) => $r->avance() . '%')
                    ->description(fn (Project $r) => $r->tasks()->count() . ' tareas'),

                TextColumn::make('due_on')
                    ->label('Entrega')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (Project $r) => $r->due_on && $r->due_on->isPast() && ! $r->estaCerrado()
                        ? 'danger'
                        : null),

                TextColumn::make('status')
                    ->width('1px')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Project::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'activo'  => 'success',
                        'pausado' => 'warning',
                        'ganado'  => 'success',
                        'cerrado' => 'gray',
                        default   => 'danger',
                    })
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('sin_responder')
                    ->label('Solicitudes de la web sin responder')
                    ->query(fn ($query) => $query
                        ->where('source', 'formulario')
                        ->whereNull('proposal_sent_at')
                        ->where('stage', 'idea')),

                SelectFilter::make('stage')->label('Etapa')->options(Project::ETAPAS),
                SelectFilter::make('status')->label('Estado')->options(Project::ESTADOS)->default('activo'),
                SelectFilter::make('lead_id')->label('Responsable')->relationship('lead', 'name'),
            ])
            ->recordActions([
                // Responder la solicitud con una propuesta.
                //
                // El enlace del correo va firmado y caduca: sirve de inmediato,
                // sin obligar a entrar, que es lo que hace que un correo se
                // lea. La misma pagina queda accesible con la sesion de quien
                // pidio el proyecto, para cuando el correo se pierda.
                Action::make('propuesta')
                    ->label('Enviar propuesta')
                    // Solo el icono: cinco acciones con texto en cada fila
                    // empujan fuera de pantalla lo que se vino a leer. El
                    // tooltip conserva el nombre para quien lo necesite, y el
                    // label sigue ahi para los lectores de pantalla.
                    ->iconButton()
                    ->tooltip('Enviar propuesta')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    // Con que haya a quién mandársela. El laboratorio anota
                    // proyectos de quien no tiene cuenta -una empresa que
                    // escribió por WhatsApp- y responderle es igual de
                    // necesario.
                    ->visible(fn (Project $record) => self::puedeManejar($record)
                        && filled($record->requestedBy?->email ?: $record->contact_email))
                    ->modalHeading('La propuesta')
                    ->modalDescription(fn (Project $record) => 'Esto es lo que va a ver '
                        . ($record->requestedBy?->name ?: $record->contact_name ?: $record->contact_email)
                        . '. Lo que escribas aquí queda guardado en el proyecto: la propuesta es el proyecto, no un documento aparte que se separaría a la primera corrección.')
                    ->modalSubmitActionLabel('Guardar y enviar')
                    ->modalWidth('3xl')
                    ->fillForm(fn (Project $record) => [
                        'estimated_value' => $record->estimated_value ?: null,
                        'starts_on'       => $record->starts_on?->toDateString(),
                        'due_on'          => $record->due_on?->toDateString(),
                        'entregables'     => $record->deliverables
                            ->map(fn ($e) => [
                                'id'     => $e->id,
                                'title'  => $e->title,
                                'due_on' => $e->due_on?->toDateString(),
                            ])
                            ->all(),
                    ])
                    ->schema([
                        Repeater::make('entregables')
                            ->label('Qué entregaríamos')
                            // Vivos para que la vista previa siga lo que se
                            // escribe: mandar a ciegas es como se cuelan las
                            // listas a medias y los valores en cero.
                            ->live(onBlur: true)
                            ->addActionLabel('Añadir un entregable')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state) => $state['title'] ?? null)
                            ->helperText('Es el corazón de la propuesta: lo que la otra parte va a aceptar o rechazar. Se guarda en el proyecto, y al cerrarlo es lo que dice si se entregó lo prometido.')
                            ->columns(3)
                            ->schema([
                                Hidden::make('id'),

                                TextInput::make('title')
                                    ->label('Entregable')
                                    ->required()
                                    ->columnSpan(2),

                                DatePicker::make('due_on')->label('Para cuándo'),
                            ]),

                        TextInput::make('estimated_value')
                            ->label('Valor estimado')
                            ->numeric()
                            ->live(onBlur: true)
                            ->minValue(0)
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('En pesos. Si queda en cero, la propuesta dirá «por definir».'),

                        DatePicker::make('starts_on')->label('Arranca')->live(onBlur: true),
                        DatePicker::make('due_on')->label('Se entrega')->live(onBlur: true),

                        FileUpload::make('imagenes')
                            ->label('Imágenes de la propuesta')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->maxFiles(6)
                            ->maxSize(20480)
                            ->columnSpanFull()
                            ->live()
                            // Disco privado, como todo lo del trabajo de un
                            // cliente: se sirven por la ruta que comprueba
                            // quien pide, nunca por una URL adivinable.
                            ->directory('proyectos/propuestas')
                            // El navegador la encoge antes de subirla: una foto
                            // de telefono son cuatro megas y por el tunel esa
                            // lentitud se vuelve un 502 sin explicacion.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(2000)
                            ->imageResizeTargetHeight(2000)
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(OptimizadorDeImagen::class)
                                    ->guardar($file, 'proyectos/propuestas', 'local')
                            )
                            ->helperText('Un render, una referencia, un boceto. Enseñan de qué se habla antes de que nadie lea una línea. Se quedan con esta versión de la propuesta.'),

                        Toggle::make('usar_como_referencia')
                            ->label('Usar la primera como imagen del proyecto')
                            ->default(fn (Project $record) => blank($record->reference_image_path))
                            ->columnSpanFull()
                            ->helperText('Es la que sale en el listado y en la ficha. Casi siempre es la misma, y volver a subirla sería trabajo doble.'),

                        Textarea::make('mensaje')
                            ->label('Algo que quieras añadir')
                            ->rows(3)
                            ->live(onBlur: true)
                            ->columnSpanFull()
                            ->helperText('Va dentro del correo, antes del cierre. Opcional.'),

                        ViewField::make('vista_previa')
                            ->label('Así se va a ver')
                            ->columnSpanFull()
                            ->view('filament.proyectos.vista-previa-propuesta')
                            ->viewData(fn (Project $record) => [
                                'proyecto'     => $record,
                                'destinatario' => $record->requestedBy?->name
                                    ?: $record->contact_name
                                    ?: $record->contact_email,
                            ]),
                    ])
                    ->action(function (Project $record, array $data) {
                        try {
                            app(ProjectService::class)->enviarPropuesta($record, $data);
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo enviar')->body($e->getMessage())->send();

                            return;
                        }

                        $a = $record->requestedBy?->email ?: $record->contact_email;

                        Notification::make()
                            ->success()
                            ->title('Propuesta enviada')
                            ->body('A ' . $a . '. El enlace vale 60 días.')
                            ->send();
                    }),

                self::tablero(),
                self::avanzar(),
                self::mover(),
                self::pausar(),
                self::reanudar(),
                self::descartar(),
                self::borrar(),
                EditAction::make()->iconButton()->tooltip('Editar'),

                // Para quien ve y no edita: del equipo, sin la seccion. Sin
                // esto veia la fila y ninguna puerta para entrar.
                ViewAction::make()
                    ->iconButton()
                    ->tooltip('Abrir')
                    ->visible(fn (Project $r) => ! ProjectResource::canEdit($r)),
            ]);
    }

    /**
     * Si esta persona puede mover este proyecto.
     *
     * Del equipo se ve; para cambiarlo hay que responder por el. Sin esto, un
     * miembro cualquiera podria avanzar de etapa o descartar un proyecto que
     * solo vino a consultar.
     */
    private static function puedeManejar(Project $proyecto): bool
    {
        return ProjectResource::canEdit($proyecto);
    }

    private static function tablero(): Action
    {
        return Action::make('tablero')
            ->label('Tablero')
            ->iconButton()
            ->tooltip('Abrir el tablero')
            ->icon('heroicon-o-view-columns')
            ->color('gray')
            ->url(fn (Project $r) => route('proyectos.tablero', $r))
            ->openUrlInNewTab();
    }

    /**
     * Avanzar dice exactamente qué falta cuando no se puede.
     *
     * Un «no se puede» sin explicación obliga a preguntar; con la explicación,
     * quien coordina sabe qué documento conseguir.
     */
    private static function avanzar(): Action
    {
        return Action::make('avanzar')
            ->label(fn (Project $r) => 'Pasar a ' . mb_strtolower(
                Project::ETAPAS[app(ProjectService::class)->siguienteEtapa($r) ?? ''] ?? 'la siguiente etapa'
            ))
            ->iconButton()
            ->tooltip(fn (Project $r) => 'Pasar a ' . mb_strtolower(
                Project::ETAPAS[app(ProjectService::class)->siguienteEtapa($r) ?? ''] ?? 'la siguiente etapa'
            ))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->visible(fn (Project $r) => self::puedeManejar($r)
                && app(ProjectService::class)->siguienteEtapa($r) !== null
                && ! in_array($r->status, ['perdido', 'descartado'], true))
            ->requiresConfirmation()
            ->modalDescription(fn (Project $r) => app(ProjectService::class)->queFalta($r)
                ?? 'Todo lo que exige la siguiente etapa está en su sitio.')
            ->action(function (Project $record) {
                try {
                    $proyecto = app(ProjectService::class)->avanzar($record);
                } catch (ProjectException $e) {
                    Notification::make()
                        ->title('Todavía no se puede avanzar')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Ahora está en ' . mb_strtolower(Project::ETAPAS[$proyecto->stage]))
                    ->success()
                    ->send();
            });
    }

    private static function mover(): Action
    {
        return Action::make('mover')
            ->label('Mover de etapa')
            ->iconButton()
            ->tooltip('Mover de etapa')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (Project $r) => self::puedeManejar($r))
            ->schema([
                Select::make('etapa')
                    ->label('A qué etapa')
                    ->options(Project::ETAPAS)
                    ->required()
                    ->helperText('Retroceder no pide nada; avanzar comprueba las compuertas de cada etapa intermedia.'),
            ])
            ->action(function (Project $record, array $data) {
                try {
                    app(ProjectService::class)->moverA($record, $data['etapa']);
                } catch (ProjectException $e) {
                    Notification::make()->title('No se pudo mover')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Etapa actualizada')->success()->send();
            });
    }

    /**
     * Pausar, sin darlo por muerto.
     *
     * Antes, un proyecto que esperaba una firma o el semestre siguiente solo
     * podia descartarse para que dejara de contarse como activo. Y lo que se
     * descarta no se vuelve a mirar.
     */
    private static function pausar(): Action
    {
        return Action::make('pausar')
            ->label('Pausar')
            ->iconButton()
            ->tooltip('Pausar')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (Project $r) => self::puedeManejar($r) && $r->status === 'activo')
            ->modalDescription('Sigue vivo: deja de contarse como trabajo en curso, pero no se cierra ni se descarta.')
            ->schema([
                Textarea::make('motivo')
                    ->label('Por qué se pausa')
                    ->required()
                    ->helperText('Lo que hace falta para volver a arrancarlo. Sin esto, dentro de dos meses nadie sabe qué se estaba esperando.'),
            ])
            ->action(function (Project $record, array $data) {
                app(ProjectService::class)->pausar($record, $data['motivo']);

                Notification::make()->title('En pausa')->success()->send();
            });
    }

    private static function reanudar(): Action
    {
        return Action::make('reanudar')
            ->label('Reanudar')
            ->iconButton()
            ->tooltip('Reanudar')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (Project $r) => self::puedeManejar($r) && $r->status === 'pausado')
            ->requiresConfirmation()
            ->modalDescription(fn (Project $r) => $r->closing_notes
                ? 'Se pausó por: ' . $r->closing_notes
                : 'Vuelve a contarse como trabajo en curso.')
            ->action(function (Project $record) {
                app(ProjectService::class)->reanudar($record);

                Notification::make()->title('Reanudado')->success()->send();
            });
    }

    private static function descartar(): Action
    {
        return Action::make('descartar')
            ->label('Descartar')
            ->iconButton()
            ->tooltip('Descartar')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            // Tambien desde pausa: lo que se paro hace medio año a veces no
            // vuelve, y obligar a reanudarlo para poder descartarlo es un paso
            // que solo sirve para ensuciar el historico.
            ->visible(fn (Project $r) => self::puedeManejar($r)
                && in_array($r->status, ['activo', 'pausado'], true))
            ->schema([
                Select::make('estado')
                    ->label('Qué pasó')
                    ->options(['perdido' => 'Se perdió', 'descartado' => 'Se descartó'])
                    ->default('descartado')
                    ->required(),

                Textarea::make('motivo')
                    ->label('Por qué')
                    ->required()
                    ->helperText('No se borra: el histórico de lo que no salió enseña tanto como el de lo que sí.'),
            ])
            ->action(function (Project $record, array $data) {
                app(ProjectService::class)->descartar($record, $data['motivo'], $data['estado']);

                Notification::make()->title('Registrado')->success()->send();
            });
    }

    /**
     * Borrar de verdad un proyecto descartado.
     *
     * El histórico enseña, pero después de unas cuantas pruebas la lista se
     * llena de ruido que nadie va a volver a mirar —y una lista con ruido se
     * deja de mirar entera—.
     *
     * Solo superadmin, y hay que **escribir el código** para confirmarlo. No es
     * ceremonia: un borrado irreversible detrás de un botón junto a «Editar» se
     * pulsa por error tarde o temprano.
     */
    private static function borrar(): Action
    {
        return Action::make('borrar')
            ->label('Borrar definitivamente')
            ->iconButton()
            ->tooltip('Borrar definitivamente')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Project $r) => in_array($r->status, ['descartado', 'perdido'], true)
                && (auth()->user()?->hasRole(User::ROL_SUPERADMIN) ?? false))
            ->modalHeading(fn (Project $r) => 'Borrar ' . $r->code)
            ->modalDescription('Esto no se deshace.')
            ->modalSubmitActionLabel('Borrar para siempre')
            ->schema([
                Placeholder::make('que_pasa')
                    ->label('')
                    ->content(fn (Project $r) => new HtmlString(self::loQueSeVa($r))),

                TextInput::make('confirmacion')
                    ->label('Escribe el código del proyecto para confirmar')
                    ->required()
                    ->placeholder(fn (Project $r) => $r->code)
                    ->rule(fn (Project $r) => function (string $atributo, $valor, $falla) use ($r) {
                        if (trim((string) $valor) !== $r->code) {
                            $falla('Ese no es el código. Es ' . $r->code . '.');
                        }
                    }),
            ])
            ->action(function (Project $record) {
                try {
                    $resumen = app(EliminarProyecto::class)($record);
                } catch (ProjectException $e) {
                    Notification::make()->danger()->title('No se puede borrar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Proyecto borrado')
                    ->body(sprintf(
                        'Se fueron %d archivos. %d reservas quedaron en el histórico, sin proyecto.',
                        $resumen['archivos'],
                        $resumen['desligadas'],
                    ))
                    ->send();
            });
    }

    /** Qué se lleva por delante y qué sobrevive, antes de pulsar. */
    private static function loQueSeVa(Project $proyecto): string
    {
        $se_va = array_filter([
            $proyecto->deliverables()->count() . ' entregables',
            $proyecto->tasks()->count() . ' tareas',
            $proyecto->documents()->count() . ' documentos',
            $proyecto->proposals()->count() . ' versiones de la propuesta',
            $proyecto->comments()->count() . ' comentarios',
            $proyecto->costs()->count() . ' costos anotados',
            $proyecto->timeLogs()->count() . ' registros de horas',
        ], fn (string $linea) => ! str_starts_with($linea, '0 '));

        $reservas = Reservation::where('project_id', $proyecto->id)->count();
        $material = $proyecto->contenido()->count();

        $sobrevive = array_filter([
            $reservas ? "{$reservas} reservas de máquina, que quedan en el histórico sin proyecto" : null,
            $material ? "{$material} piezas de material grabado, que se quedan en el banco" : null,
        ]);

        $html = '<p><strong>Se borra:</strong> '
            . ($se_va ? e(implode(', ', $se_va)) : 'nada más que la ficha')
            . ', y sus archivos del disco.</p>';

        if ($sobrevive) {
            $html .= '<p style="margin-top:.6rem"><strong>Se queda:</strong> '
                . e(implode('; ', $sobrevive))
                . '. Ocurrió de verdad, y borrarlo dejaría el inventario y el libro contable '
                . 'diciendo cosas que no cuadran.</p>';
        }

        return $html;
    }
}
