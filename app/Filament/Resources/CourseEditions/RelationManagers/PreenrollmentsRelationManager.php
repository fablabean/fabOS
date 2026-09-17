<?php

namespace App\Filament\Resources\CourseEditions\RelationManagers;

use App\Filament\Componentes\CampoDeTelefono;
use App\Models\Preenrollment;
use App\Services\Training\PreinscripcionService;
use App\Services\Training\TrainingException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Los preinscritos de una cohorte (§9).
 *
 * Es la pantalla con la que se decide si la cohorte se abre, y por eso enseña
 * lo que hace falta para decidir: quién es cada uno, **cómo piensa pagarlo** y
 * si ya confirmó. Diez interesados que esperan una beca que no existe no son
 * diez estudiantes, y eso no se ve en un número suelto.
 *
 * Solo aparece en los cursos a los que se entra por preinscripción.
 */
class PreenrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'preenrollments';

    protected static ?string $title = 'Preinscritos';

    protected static ?string $modelLabel = 'preinscripción';

    protected static ?string $pluralModelLabel = 'preinscripciones';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->course?->by_preenrollment
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /** Cuántos siguen en pie, en la pestaña: es la pregunta con la que se entra. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $vivos = $ownerRecord->preinscritos();

        if ($vivos === 0) {
            return null;
        }

        return $ownerRecord->minimum_to_open
            ? $vivos . ' de ' . $ownerRecord->minimum_to_open
            : (string) $vivos;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->label('Nombre')->required()->maxLength(160),

                TextInput::make('email')
                    ->label('Correo')
                    ->email()
                    ->required()
                    ->maxLength(160)
                    ->helperText('Es a donde llega el aviso cuando la cohorte se abra.'),

                CampoDeTelefono::make('phone'),

                TextInput::make('city')->label('Ciudad')->maxLength(120),

                TextInput::make('occupation')->label('A qué se dedica')->maxLength(160),

                TextInput::make('institution')->label('Empresa o universidad')->maxLength(160),

                Select::make('funding')
                    ->label('Cómo piensa financiarlo')
                    ->options(Preenrollment::FINANCIACION),

                TextInput::make('portfolio_url')->label('Portafolio')->url(),

                Textarea::make('motivation')->label('Por qué quiere hacerlo')->rows(3)->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notas internas')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Lo que se habló con la persona. No se le enseña.'),
            ]);
    }

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label('Quién')
                    ->searchable(['name', 'email'])
                    ->weight('medium')
                    ->description(fn (Preenrollment $r) => $r->quienEs())
                    ->extraCellAttributes(['style' => 'min-width:15rem']),

                TextColumn::make('email')
                    ->label('Contacto')
                    ->copyable()
                    ->description(fn (Preenrollment $r) => $r->phone),

                TextColumn::make('funding')
                    ->label('Cómo lo paga')
                    ->badge()
                    ->formatStateUsing(fn (Preenrollment $r) => $r->financiacionLegible())
                    // En ámbar lo que todavía no es dinero: es lo que hay que
                    // resolver antes de contar a esa persona como estudiante.
                    ->color(fn (?string $state) => match ($state) {
                        'propio', 'empresa', 'institucion' => 'success',
                        'beca', 'no_se' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('no dijo'),

                TextColumn::make('motivation')
                    ->label('Por qué')
                    ->wrap()
                    ->limit(180)
                    ->toggleable()
                    ->extraCellAttributes(['style' => 'min-width:18rem']),

                TextColumn::make('status')
                    ->label('En qué va')
                    ->badge()
                    ->formatStateUsing(fn (Preenrollment $r) => $r->estadoLegible())
                    ->color(fn (string $state) => match ($state) {
                        'confirmado' => 'success',
                        'inscrito'   => 'info',
                        'desistio'   => 'gray',
                        default      => 'warning',
                    })
                    ->description(fn (Preenrollment $r) => $r->source === 'web' ? 'por el sitio' : 'lo anotó el equipo'),

                TextColumn::make('created_at')
                    ->label('Desde')
                    ->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('d/m/Y'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('En qué va')->options(Preenrollment::ESTADOS),
                SelectFilter::make('funding')->label('Cómo lo paga')->options(Preenrollment::FINANCIACION),
            ])
            ->headerActions([
                self::abrirCohorte(),

                CreateAction::make()
                    ->label('Anotar a alguien')
                    ->modalHeading('Anotar a un interesado')
                    ->modalDescription('Para quien preguntó por correo o por el pasillo. Lo que entra por el sitio aparece aquí solo.')
                    // Por el servicio, y no creando la fila a pelo: es el que
                    // sabe que el mismo correo dos veces corrige y no duplica.
                    ->using(fn (array $data, RelationManager $livewire) => app(PreinscripcionService::class)
                        ->preinscribir($livewire->getOwnerRecord(), $data, origen: 'equipo')),
            ])
            ->recordActions([
                self::confirmar(),
                self::inscribir(),
                self::desistir(),

                Action::make('persona')
                    ->label('Ver su ficha')
                    ->iconButton()
                    ->tooltip('Ver la ficha de la persona')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->visible(fn (Preenrollment $r) => $r->user_id !== null)
                    ->url(fn (Preenrollment $r) => '/admin/users/' . $r->user_id . '/edit'),

                EditAction::make()->iconButton()->tooltip('Editar'),

                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Borrar')
                    ->modalDescription('Para lo que entró por error o pidió que borraran sus datos. Quien simplemente ya no va «desistió»: así queda cuántos se cayeron.'),
            ])
            ->toolbarActions([]);
    }

    /**
     * Abrir la cohorte: el momento por el que todos dejaron su correo.
     *
     * Va aquí y no solo en el desplegable de estado porque hace dos cosas, y la
     * segunda es la que importa: avisarle a cada preinscrito. Cambiar el estado
     * a mano desde el formulario la abre en silencio.
     */
    private static function abrirCohorte(): Action
    {
        return Action::make('abrir')
            ->label('Abrir la cohorte')
            ->icon('heroicon-o-megaphone')
            ->color('success')
            ->visible(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->status === 'planeada')
            ->requiresConfirmation()
            ->modalHeading('Abrir la cohorte')
            ->modalDescription(function (RelationManager $livewire) {
                $cohorte = $livewire->getOwnerRecord();
                $faltan = $cohorte->faltanParaAbrir();

                return 'Pasa a «inscripciones abiertas» y se le escribe a cada preinscrito para que asegure su cupo. '
                    . 'Hay ' . $cohorte->preinscritos() . ' en la lista, ' . $cohorte->confirmados() . ' confirmados'
                    . ($faltan ? ', y todavía faltan ' . $faltan . ' para el mínimo que se había fijado.' : '.')
                    . ' Desde ese momento ya no se reciben preinscripciones: se inscribe de verdad.';
            })
            ->modalSubmitActionLabel('Abrir y avisar')
            ->action(function (RelationManager $livewire) {
                try {
                    $avisados = app(PreinscripcionService::class)->abrirCohorte($livewire->getOwnerRecord());
                } catch (TrainingException $e) {
                    Notification::make()->title('No se pudo abrir')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Cohorte abierta')
                    ->body($avisados === 1 ? 'Se le avisó a una persona.' : 'Se les avisó a ' . $avisados . ' personas.')
                    ->success()
                    ->send();
            });
    }

    /** «Sí voy» no es lo mismo que «me interesa», y es con lo que se decide. */
    private static function confirmar(): Action
    {
        return Action::make('confirmar')
            ->label('Confirmó que va')
            ->iconButton()
            ->tooltip('Confirmó que va si se abre')
            ->icon('heroicon-o-hand-thumb-up')
            ->color('success')
            ->visible(fn (Preenrollment $r) => in_array($r->status, ['preinscrito', 'desistio'], true))
            ->requiresConfirmation()
            ->modalHeading(fn (Preenrollment $r) => $r->name . ' confirmó que va')
            ->modalDescription('Anótalo cuando la persona lo haya dicho, no cuando se preinscribió: es el número con el que se decide abrir.')
            ->action(function (Preenrollment $record) {
                app(PreinscripcionService::class)->confirmar($record);

                Notification::make()->title('Confirmado')->success()->send();
            });
    }

    /** Con la cohorte abierta, el preinscrito pasa a ocupar un cupo. */
    private static function inscribir(): Action
    {
        return Action::make('inscribir')
            ->label('Inscribir')
            ->iconButton()
            ->tooltip('Inscribirlo en la cohorte')
            ->icon('heroicon-o-academic-cap')
            ->color('primary')
            ->visible(fn (Preenrollment $r, RelationManager $livewire) => ! $r->yaInscrito()
                && $r->status !== 'desistio'
                && $livewire->getOwnerRecord()->status === 'abierta')
            ->requiresConfirmation()
            ->modalHeading(fn (Preenrollment $r) => 'Inscribir a ' . $r->name)
            ->modalDescription(fn (Preenrollment $r) => ($r->user_id
                ? 'Ya tiene cuenta: se le inscribe con ella.'
                : 'Se le crea su cuenta, ya validada, con el correo que dejó.')
                . ' Ocupa un cupo de la cohorte y le llega el correo de inscripción.')
            ->action(function (Preenrollment $record) {
                try {
                    app(PreinscripcionService::class)->inscribir($record);
                } catch (TrainingException $e) {
                    Notification::make()->title('No se pudo inscribir')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Inscrito')->body('Ya aparece en la pestaña de inscritos.')->success()->send();
            });
    }

    private static function desistir(): Action
    {
        return Action::make('desistir')
            ->label('Desistió')
            ->iconButton()
            ->tooltip('Ya no va')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Preenrollment $r) => in_array($r->status, ['preinscrito', 'confirmado'], true))
            ->schema([
                TextInput::make('motivo')
                    ->label('Por qué')
                    ->maxLength(255)
                    ->helperText('El costo, las fechas, otra ciudad: es lo que dice qué cambiar para la próxima cohorte.'),
            ])
            ->action(function (Preenrollment $record, array $data) {
                app(PreinscripcionService::class)->desistir($record, $data['motivo'] ?? null);

                Notification::make()->title('Anotado')->success()->send();
            });
    }
}
