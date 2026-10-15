<?php

namespace App\Filament\Resources\Actas;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Actas\Pages\CreateActa;
use App\Filament\Resources\Actas\Pages\EditActa;
use App\Filament\Resources\Actas\Pages\ListActas;
use App\Models\Acta;
use App\Models\ActaItem;
use App\Models\Asset;
use App\Models\User;
use App\Services\Activos\ActaException;
use App\Services\Activos\ActaService;
use App\Support\ConfiguracionDeActas;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Las actas de movimiento de activos: el formato FRF-207-f7 de Planta Física (§8).
 *
 * Se prepara aquí con lo que ya está en el inventario y en las cuentas, se
 * manda a firmar a quien entrega y a quien recibe, y si el activo sale de la
 * Universidad se envía —con un botón, cuando ya está firmada— a Planta Física.
 */
class ActaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Acta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'Acta de movimiento';

    protected static ?string $pluralModelLabel = 'Actas de movimiento';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Laboratorio';
    }

    public static function getNavigationBadge(): ?string
    {
        // Las que ya están firmadas, salen de la Universidad y nadie ha enviado.
        $pendientes = Acta::where('status', 'firmada')->whereIn('tipo', Acta::FUERA_DE_LA_U)->count();

        return $pendientes ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Firmadas, por enviar a Planta Física';
    }

    public static function form(Schema $schema): Schema
    {
        $bloqueada = fn (?Acta $record) => $record !== null && ! $record->esEditable();

        return $schema->columns(1)->components([
            Section::make('El movimiento')
                ->columns(2)
                ->schema([
                    Select::make('tipo')
                        ->label('Tipo de movimiento')
                        ->options(Acta::TIPOS)
                        ->required()
                        ->live()
                        ->disabled($bloqueada)
                        ->helperText(fn (Get $get) => in_array($get('tipo'), Acta::FUERA_DE_LA_U, true)
                            ? 'Sale de la Universidad: ya firmada, se envía a Planta Física para su autorización.'
                            : 'Como aparece en el formato FRF-207-f7.'),

                    DatePicker::make('fecha')
                        ->label('Fecha del movimiento')
                        ->required()
                        ->default(fn () => now(config('fabos.lab.timezone'))->toDateString())
                        ->disabled($bloqueada),

                    TextInput::make('tipo_otro')
                        ->label('Especificar')
                        ->maxLength(160)
                        ->visible(fn (Get $get) => $get('tipo') === 'otro')
                        ->required(fn (Get $get) => $get('tipo') === 'otro')
                        ->disabled($bloqueada)
                        ->columnSpanFull(),
                ]),

            self::parte('entrega', 'Área de origen · quien entrega', $bloqueada, porDefecto: true),
            self::parte('recibe', 'Área de destino · quien recibe', $bloqueada),

            Section::make('Los activos')
                ->description('Se eligen del inventario y sus datos se copian al acta: placa, marca, modelo y serie. Se pueden corregir aquí sin tocar el inventario.')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->addActionLabel('Añadir un activo')
                        ->orderColumn('position')
                        ->minItems(1)
                        ->disabled($bloqueada)
                        ->columns(12)
                        ->schema([
                            Select::make('asset_id')
                                ->label('Del inventario')
                                ->placeholder('Buscar por nombre, placa o serie…')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Asset::query()
                                    ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                                        ->orWhere('asset_tag', 'ilike', "%{$search}%")
                                        ->orWhere('serial', 'ilike', "%{$search}%"))
                                    ->orderBy('name')->limit(30)->get()
                                    ->mapWithKeys(fn (Asset $a) => [$a->id => self::rotuloDelActivo($a)])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => ($a = Asset::find($value)) ? self::rotuloDelActivo($a) : null)
                                ->live()
                                ->afterStateUpdated(function ($state, $set) {
                                    if (! ($activo = $state ? Asset::find($state) : null)) {
                                        return;
                                    }

                                    foreach (ActaItem::desdeElActivo($activo) as $campo => $valor) {
                                        if ($campo !== 'asset_id') {
                                            $set($campo, $valor);
                                        }
                                    }
                                })
                                ->columnSpan(12),

                            TextInput::make('placa')->label('Placa de inventario')->maxLength(60)->columnSpan(2),
                            TextInput::make('descripcion')->label('Descripción')->required()->maxLength(200)->columnSpan(4),
                            TextInput::make('marca')->label('Marca')->maxLength(120)->columnSpan(2),
                            TextInput::make('modelo')->label('Modelo')->maxLength(120)->columnSpan(1),
                            TextInput::make('serie')->label('Serie')->maxLength(120)->columnSpan(2),
                            Select::make('estado')
                                ->label('Estado')
                                ->options(Acta::CONDICIONES)
                                ->default('B')
                                ->required()
                                ->selectablePlaceholder(false)
                                ->columnSpan(1),
                        ]),
                ]),

            Section::make('Observaciones')
                ->schema([
                    Textarea::make('observaciones')
                        ->hiddenLabel()
                        ->rows(3)
                        ->maxLength(1000)
                        ->disabled($bloqueada)
                        ->placeholder('Para qué sale, hasta cuándo, accesorios que lo acompañan.'),
                ]),
        ]);
    }

    /** Las casillas de una de las partes: nombre, proceso, cédula y sede. */
    private static function parte(string $parte, string $titulo, \Closure $bloqueada, bool $porDefecto = false): Section
    {
        return Section::make($titulo)
            ->columns(2)
            ->schema([
                Select::make("{$parte}_user_id")
                    ->label('Persona')
                    ->placeholder('Buscar por nombre o correo…')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => User::query()
                        ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"))
                        ->orderBy('name')->limit(30)->get()
                        ->mapWithKeys(fn (User $u) => [$u->id => $u->name . ' · ' . $u->email])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? $u->name . ' · ' . $u->email : null)
                    /*
                     * Quien recibe un equipo prestado muchas veces no tiene
                     * cuenta: un profesor de otra facultad, alguien de
                     * mantenimiento. Se crea aquí, como en las reservas, y con
                     * la cédula de una vez, que el formato la exige. Si el
                     * correo ya tenía cuenta, se usa esa.
                     */
                    ->createOptionForm([
                        ...\App\Filament\Componentes\NuevaPersona::formulario(),
                        TextInput::make('document_number')
                            ->label('Cédula')
                            ->maxLength(40)
                            ->unique(User::class, 'document_number')
                            ->validationMessages(['unique' => 'Esa cédula ya es de otra persona: búscala en la lista.'])
                            ->helperText('La pide el formato para poder enviarlo a firmar.'),
                    ])
                    ->createOptionModalHeading('Nueva persona')
                    ->createOptionUsing(function (array $data): int {
                        $persona = \App\Filament\Componentes\NuevaPersona::crear($data);

                        // Si ya tenía cuenta sin cédula, se completa; la que
                        // tenga no se pisa.
                        if (filled($data['document_number'] ?? null) && blank($persona->document_number)) {
                            $persona->update(['document_number' => trim($data['document_number'])]);
                        }

                        return $persona->id;
                    })
                    ->default(fn () => $porDefecto ? auth()->id() : null)
                    ->live()
                    ->afterStateUpdated(function ($state, $set) use ($parte) {
                        if ($u = $state ? User::find($state) : null) {
                            $set("{$parte}_nombre", $u->name);
                            $set("{$parte}_cedula", $u->document_number);
                        }
                    })
                    ->disabled($bloqueada)
                    ->columnSpanFull()
                    ->helperText('Con cuenta en el sistema: por su correo le llega el enlace para firmar.'),

                TextInput::make("{$parte}_nombre")
                    ->label('Nombre completo')
                    ->required()
                    ->maxLength(160)
                    ->default(fn () => $porDefecto ? auth()->user()?->name : null)
                    ->disabled($bloqueada),

                TextInput::make("{$parte}_cedula")
                    ->label('Cédula')
                    ->maxLength(40)
                    ->default(fn () => $porDefecto ? auth()->user()?->document_number : null)
                    ->disabled($bloqueada)
                    ->helperText('El formato la exige: sin ella no se puede enviar a firmar.'),

                TextInput::make("{$parte}_proceso")
                    ->label('Proceso')
                    ->maxLength(160)
                    ->default(fn () => ConfiguracionDeActas::valor('proceso'))
                    ->disabled($bloqueada),

                TextInput::make("{$parte}_sede")
                    ->label('Sede')
                    ->maxLength(120)
                    ->default(fn () => ConfiguracionDeActas::valor('sede'))
                    ->disabled($bloqueada),
            ]);
    }

    private static function rotuloDelActivo(Asset $a): string
    {
        return $a->name
            . ($a->asset_tag ? ' · placa ' . $a->asset_tag : '')
            . ($a->serial ? ' · ' . $a->serial : '');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('items'))
            ->columns([
                TextColumn::make('code')->label('Acta')->weight('bold')->searchable()->sortable(),

                TextColumn::make('tipo')
                    ->label('Movimiento')
                    ->formatStateUsing(fn (Acta $r) => $r->tipoLegible())
                    ->description(fn (Acta $r) => $r->fecha->format('d/m/Y'))
                    ->wrap(),

                TextColumn::make('recibe_nombre')
                    ->label('Recibe')
                    ->searchable()
                    ->description(fn (Acta $r) => $r->esUnaSolaPersona() ? null : 'Entrega: ' . $r->entrega_nombre),

                TextColumn::make('items_count')->label('Activos')->badge()->color('gray'),

                TextColumn::make('firmas')
                    ->label('Firmas')
                    ->state(fn (Acta $r) => match (true) {
                        $r->status === 'borrador' => null,
                        $r->estaFirmadaPorTodos() => 'Las dos',
                        $r->firmo('entrega')      => 'Falta quien recibe',
                        $r->firmo('recibe')       => 'Falta quien entrega',
                        default                   => 'Ninguna todavía',
                    })
                    ->placeholder('—')
                    ->color(fn (Acta $r) => $r->estaFirmadaPorTodos() ? 'success' : 'warning'),

                // Si lo que salió ya volvió: un acta firmada no es un asunto
                // cerrado mientras el equipo siga fuera.
                TextColumn::make('devolucion')
                    ->label('Devolución')
                    ->state(fn (Acta $r) => $r->resumenDeDevolucion())
                    ->badge()
                    ->color(fn (?string $state) => match (true) {
                        $state === 'Devuelta'     => 'success',
                        $state === 'Sin devolver' => 'warning',
                        default                   => 'info',
                    })
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Acta::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'borrador'   => 'gray',
                        'por_firmar' => 'warning',
                        'firmada'    => 'info',
                        'enviada'    => 'success',
                        default      => 'danger',
                    })
                    ->description(fn (Acta $r) => match (true) {
                        $r->status === 'firmada' && $r->saleDeLaUniversidad() => 'Falta enviarla a Planta Física',
                        $r->status === 'firmada'                              => 'No sale de la Universidad',
                        $r->status === 'enviada'                              => 'A ' . $r->enviada_a_planta_a,
                        default                                               => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Acta::ESTADOS),
                SelectFilter::make('tipo')->label('Movimiento')->options(Acta::TIPOS),
            ])
            ->recordActions([
                self::enviarAFirma(),
                self::enviarAPlantaFisica(),
                self::registrarDevolucion(),
                ActionGroup::make([
                    self::verFormato(),
                    self::verDevoluciones(),
                    self::firmarYo(),
                    self::reenviarEnlaces(),
                    EditAction::make()->visible(fn (Acta $r) => $r->esEditable()),
                    self::anular(),
                ]),
            ]);
    }

    /**
     * Anotar lo que volvió. Va a su propia pantalla: ahí se marca activo por
     * activo, se dice en qué estado llegó y firma quien lo recibe.
     */
    public static function registrarDevolucion(): Action
    {
        return Action::make('devolucion')
            ->label('Registrar devolución')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Acta $r) => $r->admiteDevolucion())
            ->url(fn (Acta $r) => route('actas.devolver', $r))
            ->openUrlInNewTab();
    }

    /** Las devoluciones que ya tiene, con sus formatos. */
    public static function verDevoluciones(): Action
    {
        return Action::make('devoluciones')
            ->label('Ver las devoluciones')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (Acta $r) => $r->devoluciones()->exists())
            ->url(fn (Acta $r) => route('actas.devolver', $r))
            ->openUrlInNewTab();
    }

    public static function verFormato(): Action
    {
        return Action::make('formato')
            ->label('Ver el formato')
            ->icon('heroicon-o-document-text')
            ->url(fn (Acta $r) => route('actas.ver', $r))
            ->openUrlInNewTab();
    }

    public static function enviarAFirma(): Action
    {
        return Action::make('enviarAFirma')
            ->label('Enviar a firma')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->visible(fn (Acta $r) => $r->esEditable())
            ->requiresConfirmation()
            ->modalHeading('Enviar el acta a firma')
            ->modalDescription(fn (Acta $r) => $r->esUnaSolaPersona()
                ? 'A ' . $r->recibe_nombre . ' le llega un correo con el enlace para firmarla. Como entrega y recibe la misma persona, una firma vale por las dos casillas. Después de enviarla ya no se puede editar.'
                : 'A ' . $r->entrega_nombre . ' y a ' . $r->recibe_nombre . ' les llega un correo con su enlace para firmarla. Después de enviarla ya no se puede editar.')
            ->modalSubmitActionLabel('Enviar a firma')
            ->action(function (Acta $record) {
                try {
                    app(ActaService::class)->enviarAFirma($record, auth()->user());
                } catch (ActaException $e) {
                    Notification::make()->title('No se pudo enviar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Enviada a firma')->body('Cada quien recibió su enlace por correo.')->success()->send();
            });
    }

    /** Firmar uno mismo, si es parte del acta: abre su enlace sin ir al correo. */
    public static function firmarYo(): Action
    {
        $miParte = function (Acta $r): ?string {
            foreach (['entrega', 'recibe'] as $parte) {
                if ($r->{"{$parte}_user_id"} === auth()->id() && ! $r->firmo($parte)) {
                    return $parte;
                }
            }

            return null;
        };

        return Action::make('firmarYo')
            ->label('Firmar yo')
            ->icon('heroicon-o-pencil')
            ->visible(fn (Acta $r) => $r->status === 'por_firmar' && $miParte($r) !== null)
            ->url(fn (Acta $r) => $miParte($r) ? $r->enlaceDeFirma($miParte($r)) : null)
            ->openUrlInNewTab();
    }

    public static function reenviarEnlaces(): Action
    {
        return Action::make('reenviar')
            ->label('Reenviar el enlace a quien falta')
            ->icon('heroicon-o-envelope')
            ->visible(fn (Acta $r) => $r->status === 'por_firmar')
            ->requiresConfirmation()
            ->action(function (Acta $record) {
                $servicio = app(ActaService::class);
                $a = collect(['recibe', 'entrega'])
                    ->reject(fn ($parte) => $record->firmo($parte) || ($parte === 'entrega' && $record->esUnaSolaPersona()))
                    ->each(fn ($parte) => $servicio->pedirFirma($record, $parte, auth()->user()))
                    ->map(fn ($parte) => $record->{"{$parte}_nombre"});

                Notification::make()->title('Enlace reenviado')->body('A ' . $a->implode(' y a ') . '.')->success()->send();
            });
    }

    public static function enviarAPlantaFisica(): Action
    {
        return Action::make('enviarAPlanta')
            ->label(fn (Acta $r) => $r->status === 'enviada' ? 'Volver a enviar a Planta Física' : 'Enviar a Planta Física')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(fn (Acta $r) => in_array($r->status, ['firmada', 'enviada'], true) && $r->saleDeLaUniversidad())
            ->requiresConfirmation()
            ->modalHeading('Enviar a Planta Física')
            ->modalDescription(fn (Acta $r) => 'Se le envía el formato FRF-207-f7 firmado, en PDF, a '
                . ConfiguracionDeActas::valor('autoriza_nombre') . ' (' . ConfiguracionDeActas::valor('correo_planta') . ') para su autorización.'
                . ($r->status === 'enviada' ? ' Ya se había enviado el ' . $r->enviada_a_planta_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') . '.' : ''))
            ->modalSubmitActionLabel('Enviar')
            ->action(function (Acta $record) {
                try {
                    $aviso = app(ActaService::class)->enviarAPlantaFisica($record, auth()->user());
                } catch (ActaException $e) {
                    Notification::make()->title('No se pudo enviar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                $aviso->status === 'enviado'
                    ? Notification::make()->title('Enviada a Planta Física')->body('A ' . $aviso->to . ', con el PDF adjunto.')->success()->send()
                    : Notification::make()->title('El correo no salió')->body($aviso->reason ?: 'Revisa la bitácora de avisos.')->danger()->persistent()->send();
            });
    }

    public static function anular(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Acta $r) => in_array($r->status, ['borrador', 'por_firmar'], true))
            ->requiresConfirmation()
            ->modalDescription('Los enlaces de firma dejan de funcionar. El acta no se borra: queda como anulada.')
            ->action(function (Acta $record) {
                app(ActaService::class)->anular($record);

                Notification::make()->title('Acta anulada')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListActas::route('/'),
            'create' => CreateActa::route('/crear'),
            'edit'   => EditActa::route('/{record}/editar'),
        ];
    }
}
