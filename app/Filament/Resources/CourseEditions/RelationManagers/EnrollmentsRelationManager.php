<?php

namespace App\Filament\Resources\CourseEditions\RelationManagers;

use App\Models\Enrollment;
use App\Models\User;
use App\Services\Booking\AsesoriaService;
use App\Services\Training\PracticaService;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Los inscritos de una edición.
 *
 * No hay «crear» ni «borrar» sueltos: inscribir pasa por el servicio, que
 * respeta el cupo, y retirarse deja rastro en vez de desaparecer la fila.
 */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Inscritos';

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Persona')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Enrollment $r) => $r->user?->email),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Enrollment::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'aprobado'  => 'success',
                        'inscrito'  => 'info',
                        'reprobado' => 'danger',
                        default     => 'gray',
                    }),

                /*
                 * El examen teorico, en una columna: la nota, los intentos y
                 * cuando lo paso. Hasta ahora se corregia solo y no se veia
                 * en ningun sitio del panel, asi que quien tenia que firmar
                 * la practica no sabia si la persona habia llegado ahi.
                 */
                TextColumn::make('theory_score')
                    ->label('Examen')
                    ->alignEnd()
                    ->visible(fn (RelationManager $livewire) => (bool) $livewire->getOwnerRecord()->course?->tieneExamen())
                    ->state(fn (Enrollment $r) => $r->theory_score === null ? null : $r->theory_score . '%')
                    ->placeholder('Sin presentar')
                    ->color(fn (Enrollment $r) => $r->teoriaAprobada() ? 'success' : ($r->theory_score === null ? 'gray' : 'danger'))
                    ->weight('medium')
                    ->description(fn (Enrollment $r) => $r->theory_score === null
                        ? null
                        : ($r->teoriaAprobada()
                            ? 'Aprobado el ' . $r->theory_passed_at?->timezone($tz)->format('d/m/Y')
                            : 'No aprobado')
                            . ' · ' . $r->theory_attempts . ($r->theory_attempts === 1 ? ' intento' : ' intentos')),

                TextColumn::make('practica')
                    ->label('Práctica')
                    ->visible(fn (RelationManager $livewire) => (bool) $livewire->getOwnerRecord()->course?->requires_practical)
                    ->state(function (Enrollment $r) use ($tz) {
                        if ($r->practicaAprobada()) {
                            return 'Firmada';
                        }

                        if ($agendada = $r->practicaAgendada()) {
                            return 'Agendada ' . $agendada->starts_at->timezone($tz)->format('d/m H:i');
                        }

                        return $r->teoriaLista() ? 'Por agendar' : 'Espera el examen';
                    })
                    ->color(fn (Enrollment $r) => $r->practicaAprobada() ? 'success' : ($r->practicaAgendada() ? 'info' : 'gray'))
                    ->description(function (Enrollment $r) use ($tz) {
                        if ($r->practicaAprobada()) {
                            return 'Por ' . ($r->practicalBy?->name ?? '—') . ' el ' . $r->practical_passed_at?->timezone($tz)->format('d/m/Y');
                        }

                        return $r->practicaAgendada()?->reservable?->name;
                    }),

                TextColumn::make('grade')->label('Nota')->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('certificate_code')
                    ->label('Certificado')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->copyable()
                    ->url(fn (Enrollment $r) => $r->certificate_code
                        ? route('publico.verificar', $r->certificate_code)
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('enrolled_at')
                    ->label('Inscrito')
                    ->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('d/m/Y'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Enrollment::ESTADOS),
            ])
            ->headerActions([
                self::inscribir(),
            ])
            ->recordActions([
                self::citar(),
                self::firmarPractica(),
                self::aprobar(),
                self::reprobar(),
                self::retirar(),
            ])
            ->toolbarActions([]);
    }

    private static function inscribir(): Action
    {
        return Action::make('inscribir')
            ->label('Inscribir a alguien')
            ->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('user_id')
                    ->label('Persona')
                    ->options(fn () => \App\Filament\Componentes\SelectorDePersona::personas())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, RelationManager $livewire) {
                try {
                    app(TrainingService::class)->inscribir(
                        $livewire->getOwnerRecord(),
                        User::findOrFail($data['user_id']),
                    );
                } catch (TrainingException $e) {
                    Notification::make()->title('No se pudo inscribir')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Inscrito')->success()->send();
            });
    }

    /**
     * Citar a la persona a la practica: hora y evaluador concretos.
     *
     * Es la otra puerta. La normal es que la persona pida hora desde su
     * cuenta; esta es para cuando la coordinacion ya hablo con ella y quiere
     * dejarlo fijado, o cuando la persona no encuentra hueco.
     */
    private static function citar(): Action
    {
        return Action::make('citar')
            ->label('Citar a la práctica')
            ->icon('heroicon-o-calendar-days')
            ->color('info')
            ->visible(fn (Enrollment $r) => $r->status === 'inscrito'
                && $r->edition?->course?->requires_practical
                && ! $r->practicaAprobada()
                && $r->practicaAgendada() === null
                && auth()->user()?->hasAnyRole([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]))
            ->modalHeading(fn (Enrollment $r) => 'Citar a ' . ($r->user?->name ?? 'la persona') . ' a la práctica')
            ->modalDescription(fn (Enrollment $r) => $r->teoriaLista()
                ? 'Le llega un correo con la hora y quién la evalúa. Queda reservado el tiempo de esa persona.'
                : 'Todavía no ha aprobado el examen teórico: la práctica se evalúa sobre eso.')
            ->schema([
                DateTimePicker::make('inicio')
                    ->label('Cuándo')
                    ->seconds(false)
                    ->minutesStep(15)
                    ->required()
                    // Como texto y en la hora del laboratorio: el navegador
                    // compara el minimo con la hora de pared que se escribe,
                    // y `now()` a secas llegaba en UTC, cinco horas adelante:
                    // a las cuatro de la tarde no dejaba citar para las cinco.
                    ->minDate(fn () => now(config('fabos.lab.timezone'))->format('Y-m-d H:i')),

                Select::make('evaluador_id')
                    ->label('Quién la evalúa')
                    ->options(function (RelationManager $livewire) {
                        $area = $livewire->getOwnerRecord()->course?->area;

                        // Primero quienes asesoran el area del curso; despues
                        // el resto del equipo, por si toca cubrir.
                        $delArea = $area ? app(AsesoriaService::class)->asesoresDe($area)->pluck('name', 'id') : collect();
                        $resto = User::role(User::ROLES_BACKOFFICE)->where('status', 'activo')->orderBy('name')->pluck('name', 'id');

                        return $delArea->map(fn ($n) => $n . ' · asesora el área')
                            ->union($resto->except($delArea->keys()->all()))
                            ->all();
                    })
                    ->searchable()
                    ->required(),

                Textarea::make('nota')
                    ->label('Algo que decirle')
                    ->rows(2)
                    ->maxLength(300)
                    ->placeholder('Trae el archivo que quieras imprimir.')
                    ->helperText('Va en el correo. Opcional.'),
            ])
            ->action(function (Enrollment $record, array $data) {
                $inicio = Carbon::parse($data['inicio'], config('app.timezone'))->setTimezone(config('fabos.lab.timezone'));

                try {
                    $reserva = app(PracticaService::class)->citar(
                        $record,
                        User::findOrFail($data['evaluador_id']),
                        $inicio,
                        nota: $data['nota'] ?? null,
                    );
                } catch (TrainingException $e) {
                    Notification::make()->danger()->title('No se pudo citar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Citada')
                    ->body('El ' . $inicio->format('d/m/Y') . ' a las ' . $inicio->format('H:i') . ' con '
                        . $reserva->reservable->name . '. Le llegó el correo.')
                    ->send();
            });
    }

    /**
     * Firmar la evaluacion presencial.
     *
     * La hace una persona, delante de la maquina: una pantalla no puede ver
     * si alguien nivela una cama o si sabe parar la impresion cuando algo va
     * mal. Queda con nombre y notas, porque quien firma responde de lo que
     * firma. Por ahora firman administradores y superadmin: la firma da el
     * certifab en el mismo acto.
     */
    private static function firmarPractica(): Action
    {
        return Action::make('practica')
            ->label('Firmar la práctica')
            ->icon('heroicon-o-hand-thumb-up')
            ->color('warning')
            ->visible(fn (Enrollment $r) => $r->edition?->course?->requires_practical
                && ! $r->practicaAprobada()
                && $r->status !== 'retirado'
                && auth()->user()?->hasAnyRole([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]))
            ->modalDescription(function (Enrollment $r) {
                if (! $r->teoriaLista()) {
                    return 'Todavía no ha aprobado el examen teórico.';
                }

                $agendada = $r->practicaAgendada();

                return ($r->theory_score !== null ? 'Aprobó la teoría con ' . $r->theory_score . '%. ' : '')
                    . 'Firmas que sabe hacerlo delante de la máquina, y con eso sale el certifab.'
                    . ($agendada ? ' Estaba agendada con ' . ($agendada->reservable?->name ?? 'el equipo') . '.' : '');
            })
            ->schema([
                Textarea::make('notas')
                    ->label('Qué hizo')
                    ->rows(3)
                    ->placeholder('Niveló la cama, cargó filamento y paró una impresión fallida sin ayuda.')
                    ->helperText('Queda en el expediente. Es lo que sostiene el certifab si alguien pregunta.'),
            ])
            ->action(function (Enrollment $record, array $data) {
                try {
                    $inscripcion = app(TrainingService::class)->firmarPracticaYAprobar(
                        $record, auth()->user(), $data['notas'] ?? null,
                    );
                } catch (TrainingException $e) {
                    Notification::make()->danger()->title('No se pudo firmar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($inscripcion->aprobada() ? 'Práctica firmada y certifab otorgado' : 'Práctica firmada')
                    ->body($inscripcion->aprobada()
                        ? 'Le llegó el certificado por correo.'
                        : ($inscripcion->queFaltaParaAprobar() ?? 'Ya se puede aprobar.'))
                    ->send();
            });
    }

    private static function aprobar(): Action
    {
        return Action::make('aprobar')
            ->label('Aprobar')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Enrollment $r) => $r->status === 'inscrito')
            // Decir que falta antes de pulsar, y no despues de un error: el
            // certifab exige los pasos que ese curso declare.
            ->modalDescription(fn (Enrollment $r) => $r->queFaltaParaAprobar()
                ?? 'Se emite el certificado y quedan otorgados los certifabs del curso.')
            ->disabled(fn (Enrollment $r) => $r->queFaltaParaAprobar() !== null)
            ->schema([
                TextInput::make('nota')
                    ->label('Nota')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(5)
                    ->helperText('Opcional, de 0 a 5.'),
            ])
            ->modalDescription('Se emite su certificado verificable y se le otorgan los certifabs del curso.')
            ->action(function (Enrollment $record, array $data) {
                try {
                    app(TrainingService::class)->aprobar(
                        $record,
                        $data['nota'] !== null && $data['nota'] !== '' ? (float) $data['nota'] : null,
                        auth()->user(),
                    );
                } catch (TrainingException $e) {
                    Notification::make()->title('No se pudo aprobar')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Aprobado y habilitado')->success()->send();
            });
    }

    private static function reprobar(): Action
    {
        return Action::make('reprobar')
            ->label('No aprobar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Enrollment $r) => $r->status === 'inscrito')
            ->schema([
                TextInput::make('nota')->label('Nota')->numeric()->minValue(0)->maxValue(5),
                Textarea::make('comentario')
                    ->label('Qué le faltó')
                    ->helperText('Le sirve para saber por dónde retomar.'),
            ])
            ->action(function (Enrollment $record, array $data) {
                app(TrainingService::class)->reprobar(
                    $record,
                    $data['nota'] !== null && $data['nota'] !== '' ? (float) $data['nota'] : null,
                    $data['comentario'] ?? null,
                );

                Notification::make()->title('Registrado')->success()->send();
            });
    }

    private static function retirar(): Action
    {
        return Action::make('retirar')
            ->label('Retirar')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Enrollment $r) => ! $r->aprobada() && $r->status !== 'retirado')
            ->schema([
                TextInput::make('motivo')->label('Motivo')->maxLength(255),
            ])
            ->modalDescription('Libera el cupo para otra persona.')
            ->action(function (Enrollment $record, array $data) {
                try {
                    app(TrainingService::class)->retirar($record, $data['motivo'] ?? null);
                } catch (TrainingException $e) {
                    Notification::make()->title('No se pudo retirar')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Cupo liberado')->success()->send();
            });
    }
}
