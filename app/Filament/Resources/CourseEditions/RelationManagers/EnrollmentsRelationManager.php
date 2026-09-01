<?php

namespace App\Filament\Resources\CourseEditions\RelationManagers;

use App\Models\Enrollment;
use App\Models\User;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

                TextColumn::make('grade')->label('Nota')->alignEnd()->placeholder('—'),

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
                    ->options(fn () => User::where('status', 'activo')->orderBy('name')->pluck('name', 'id'))
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
     * Firmar la evaluacion presencial.
     *
     * La hace una persona, delante de la maquina: una pantalla no puede ver si
     * alguien nivela una cama o si sabe parar la impresion cuando algo va mal.
     * Queda con nombre y notas, porque quien firma responde de lo que firma.
     */
    private static function firmarPractica(): Action
    {
        return Action::make('practica')
            ->label('Firmar la práctica')
            ->icon('heroicon-o-hand-thumb-up')
            ->color('warning')
            ->visible(fn (Enrollment $r) => $r->edition?->course?->requires_practical
                && ! $r->practicaAprobada()
                && $r->status !== 'retirado')
            ->modalDescription(fn (Enrollment $r) => $r->teoriaAprobada()
                ? 'Aprobó la teoría con ' . $r->theory_score . '%. Firmas que también sabe hacerlo.'
                : 'Todavía no ha aprobado el examen teórico.')
            ->schema([
                Textarea::make('notas')
                    ->label('Qué hizo')
                    ->rows(3)
                    ->placeholder('Niveló la cama, cargó filamento y paró una impresión fallida sin ayuda.')
                    ->helperText('Queda en el expediente. Es lo que sostiene el certifab si alguien pregunta.'),
            ])
            ->action(function (Enrollment $record, array $data) {
                try {
                    app(TrainingService::class)->registrarPractica(
                        $record, auth()->user(), $data['notas'] ?? null,
                    );
                } catch (TrainingException $e) {
                    Notification::make()->danger()->title('No se pudo firmar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Práctica firmada')
                    ->body('Ya se puede aprobar y otorgar el certifab.')
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
