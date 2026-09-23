<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Componentes\CampoDeTelefono;
use App\Models\Project;
use App\Models\ProjectPartner;
use App\Services\Projects\AcuerdoDeAlianza;
use App\Services\Projects\Alianzas;
use App\Services\Projects\ProjectException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Las partes de una alianza (§11): quién es, qué pone, cuánto le toca.
 *
 * Solo aparece en proyectos en modalidad alianza. Es donde se decide si la
 * alianza cuadra —quién puso qué, cuánta participación queda por repartir— y
 * desde donde sale el acuerdo.
 */
class PartnersRelationManager extends RelationManager
{
    protected static string $relationship = 'partners';

    protected static ?string $title = 'Aliados';

    protected static ?string $modelLabel = 'parte';

    protected static ?string $pluralModelLabel = 'partes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->esAlianza() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $propuestos = $ownerRecord->partners()->where('status', 'propuesto')->count();

        return $propuestos > 0 ? $propuestos . ' por confirmar' : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quién')
                ->columns(2)
                ->schema([
                    /*
                     * El papel del laboratorio no se toca.
                     *
                     * Se excluia «El laboratorio» de las opciones para que
                     * nadie creara una segunda fila suya, y eso degradaba la
                     * de verdad: al abrirla, el selector no encontraba su
                     * valor entre las opciones, caia en «Aliado» y al guardar
                     * lo escribia. La alianza se quedaba sin laboratorio sin
                     * que nadie lo hubiera pedido, y con ella la cuenta de lo
                     * que ponemos y de lo que nos toca: el embudo pasaba a
                     * decir «cero» y «falta pactar».
                     *
                     * Ahora su fila lo conserva y nadie mas puede tomarlo.
                     */
                    Select::make('role')
                        ->label('Papel')
                        ->options(fn (?ProjectPartner $record) => $record?->esElLaboratorio()
                            ? ProjectPartner::ROLES
                            : collect(ProjectPartner::ROLES)->except('laboratorio')->all())
                        ->disabled(fn (?ProjectPartner $record) => $record?->esElLaboratorio() ?? false)
                        // Un campo deshabilitado no se envía: sin esto se
                        // guardaría vacío, que es el mismo problema con otra cara.
                        ->dehydrated()
                        ->helperText(fn (?ProjectPartner $record) => $record?->esElLaboratorio()
                            ? 'De esta fila salen las dos cifras de la alianza: lo que ponemos y lo que nos toca. Por eso no cambia de papel.'
                            : null)
                        ->default('aliado')
                        ->required(),

                    TextInput::make('name')->label('Nombre')->required()->maxLength(160),
                    TextInput::make('organization')->label('Empresa u organización')->maxLength(160),
                    TextInput::make('document')->label('NIT o cédula')->maxLength(40)->helperText('Sale en el acuerdo.'),
                    TextInput::make('email')->label('Correo')->email()->maxLength(160)->helperText('A donde le llega el acuerdo.'),
                    CampoDeTelefono::make('phone'),
                ]),

            Section::make('Qué pone')
                ->columns(2)
                ->schema([
                    Select::make('contribution_kind')
                        ->label('Tipo de aporte')
                        ->options(ProjectPartner::APORTES)
                        ->default('otro')
                        ->required(),

                    TextInput::make('contribution_value')
                        ->label('Valorado en pesos')
                        ->numeric()
                        ->default(0)
                        ->prefix(config('fabos.money.symbol'))
                        ->helperText('Lo que vale lo que pone, aunque no sea dinero: 200 horas de diseño también valen.'),

                    TextInput::make('share_percent')
                        ->label('Participación (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->helperText('Vacío: por definir. Entre todos los confirmados no puede pasar de 100.'),

                    TextInput::make('contribution_note')
                        ->label('En qué consiste')
                        ->maxLength(255)
                        ->placeholder('200 horas de diseño electrónico')
                        ->columnSpanFull(),

                    Textarea::make('notes')->label('Notas internas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Quién')
                    ->weight('medium')
                    ->state(fn (ProjectPartner $r) => $r->quien())
                    ->description(fn (ProjectPartner $r) => $r->email),

                TextColumn::make('role')
                    ->label('Papel')
                    ->badge()
                    ->formatStateUsing(fn (ProjectPartner $r) => $r->papel())
                    ->color(fn (string $state) => match ($state) {
                        'laboratorio' => 'primary',
                        'iniciador'   => 'info',
                        'inversor'    => 'warning',
                        default       => 'gray',
                    }),

                TextColumn::make('aporte')
                    ->label('Qué pone')
                    ->state(fn (ProjectPartner $r) => $r->aporteLegible())
                    ->wrap(),

                TextColumn::make('share_percent')
                    ->label('Participación')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2, ',', '.'), '0'), ',') . ' %')
                    ->placeholder('por definir'),

                TextColumn::make('status')
                    ->label('En qué va')
                    ->badge()
                    ->formatStateUsing(fn (ProjectPartner $r) => $r->estadoLegible())
                    ->color(fn (string $state) => match ($state) {
                        'confirmado' => 'success',
                        'retirado'   => 'gray',
                        default      => 'warning',
                    })
                    ->description(fn (ProjectPartner $r) => $r->source === 'web' ? 'pidió entrar desde el sitio' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('En qué va')->options(ProjectPartner::ESTADOS),
            ])
            ->headerActions([
                self::agregar(),
                self::acuerdo(),
            ])
            ->recordActions([
                self::confirmar(),
                self::retirar(),
                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Todavía no hay partes')
            ->emptyStateDescription('Al convertir el proyecto en alianza entran el laboratorio y quien trajo la idea. Los demás se anotan aquí, o piden entrar desde el sitio.');
    }

    private static function agregar(): Action
    {
        return Action::make('agregar')
            ->label('Sumar una parte')
            ->icon('heroicon-o-user-plus')
            ->modalHeading('Sumar una parte a la alianza')
            ->modalDescription('Nace confirmada: quien la anota ya habló con ella. Lo que entra por el sitio queda propuesto hasta que se confirme.')
            ->modalWidth('3xl')
            ->schema(fn (Schema $schema) => $this->form($schema)->getComponents())
            ->action(function (array $data, RelationManager $livewire) {
                try {
                    app(Alianzas::class)->agregar($livewire->getOwnerRecord(), $data, auth()->user());
                } catch (ProjectException $e) {
                    Notification::make()->title('No se pudo sumar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Parte sumada')->success()->send();
            });
    }

    /** El acuerdo: se ve, se genera y se manda a cada parte. */
    private static function acuerdo(): Action
    {
        return Action::make('acuerdo')
            ->label('Acuerdo de alianza')
            ->icon('heroicon-o-document-check')
            ->color('success')
            ->modalHeading(fn (RelationManager $livewire) => 'Acuerdo de alianza de ' . $livewire->getOwnerRecord()->code)
            ->modalDescription(function (RelationManager $livewire) {
                $p = $livewire->getOwnerRecord();
                $repartida = $p->participacionRepartida();

                return $p->partners()->confirmados()->count() . ' partes confirmadas · aportes por '
                    . config('fabos.money.symbol') . number_format($p->totalAportado(), 0, ',', '.')
                    . ' · participación repartida ' . rtrim(rtrim(number_format($repartida, 2, ',', '.'), '0'), ',') . ' %'
                    . ($repartida < 100 ? ' (quedan ' . rtrim(rtrim(number_format(100 - $repartida, 2, ',', '.'), '0'), ',') . ' % por definir)' : '');
            })
            ->modalWidth('3xl')
            ->fillForm(fn (RelationManager $livewire) => app(AcuerdoDeAlianza::class)->datosSugeridos($livewire->getOwnerRecord()))
            ->schema([
                Textarea::make('objeto')->label('Qué se construye')->rows(3)->required(),

                Textarea::make('clausulas')
                    ->label('Cláusulas')
                    ->rows(16)
                    ->required()
                    ->helperText('Entre llaves: ' . collect(AcuerdoDeAlianza::VARIABLES)->keys()->map(fn ($k) => '{' . $k . '}')->implode(' ') . '. La base se edita en Proyectos → Acuerdo de servicio.'),

                Select::make('enviar')
                    ->label('Al generarlo')
                    ->options(['no' => 'Solo dejarlo en Documentos', 'si' => 'Mandárselo a cada parte para firmar'])
                    ->default('si'),

                Textarea::make('mensaje')->label('Un mensaje para las partes')->rows(2)->helperText('Va dentro del correo. Opcional.'),
            ])
            ->extraModalFooterActions(fn (Action $action) => [
                $action->makeModalSubmitAction('vistaPrevia', arguments: ['vista' => true])->label('Vista previa')->color('gray'),
            ])
            ->action(function (array $data, array $arguments, Action $action, RelationManager $livewire) {
                $proyecto = $livewire->getOwnerRecord();

                if ($arguments['vista'] ?? false) {
                    $token = \Illuminate\Support\Str::random(40);
                    \Illuminate\Support\Facades\Cache::put('alianza:' . $token, $data + ['project_id' => $proyecto->id], now()->addHour());
                    $livewire->js('window.open(' . json_encode(route('panel.alianza', ['project' => $proyecto, 'token' => $token])) . ', "_blank")');
                    $action->halt();
                }

                try {
                    app(AcuerdoDeAlianza::class)->generar(
                        $proyecto, $data, auth()->user(),
                        enviar: ($data['enviar'] ?? 'no') === 'si',
                        mensaje: $data['mensaje'] ?? null,
                    );
                } catch (ProjectException $e) {
                    Notification::make()->title('No se pudo generar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Acuerdo generado')->body('Quedó en Documentos como contrato del proyecto.')->success()->send();
            });
    }

    private static function confirmar(): Action
    {
        return Action::make('confirmar')
            ->label('Confirmar')
            ->iconButton()
            ->tooltip('Confirmar como parte')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (ProjectPartner $r) => $r->status === 'propuesto')
            ->modalHeading(fn (ProjectPartner $r) => 'Confirmar a ' . $r->quien())
            ->modalDescription(fn (ProjectPartner $r) => 'Pone: ' . $r->aporteLegible() . '. Se le avisa por correo y entra en el acuerdo.')
            ->schema([
                TextInput::make('share_percent')
                    ->label('Participación (%)')
                    ->numeric()->minValue(0)->maxValue(100)->step(0.01)
                    ->default(fn (ProjectPartner $r) => $r->share_percent)
                    ->helperText('Vacío: por definir.'),
            ])
            ->action(function (ProjectPartner $record, array $data) {
                try {
                    app(Alianzas::class)->confirmar($record, auth()->user(), isset($data['share_percent']) && $data['share_percent'] !== '' ? (float) $data['share_percent'] : null);
                } catch (ProjectException $e) {
                    Notification::make()->title('No se pudo confirmar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Confirmado')->success()->send();
            });
    }

    private static function retirar(): Action
    {
        return Action::make('retirar')
            ->label('Se retira')
            ->iconButton()
            ->tooltip('Se retira de la alianza')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (ProjectPartner $r) => ! $r->esElLaboratorio() && $r->status !== 'retirado')
            ->schema([
                TextInput::make('motivo')->label('Por qué')->maxLength(255),
            ])
            ->action(function (ProjectPartner $record, array $data) {
                try {
                    app(Alianzas::class)->retirar($record, $data['motivo'] ?? null);
                } catch (ProjectException $e) {
                    Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Anotado')->success()->send();
            });
    }
}
