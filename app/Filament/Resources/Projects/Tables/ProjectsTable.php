<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Project;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

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
                    ->description(fn (Project $r) => Project::ORIGENES[$r->source] ?? $r->source),

                TextColumn::make('name')
                    ->label('Proyecto')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Project $r) => $r->quienPide()),

                TextColumn::make('stage')
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
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Project::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'activo'  => 'success',
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
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->visible(fn (Project $record) => $record->requestedBy?->email !== null)
                    ->modalHeading('Enviar la propuesta')
                    ->modalDescription(fn (Project $record) => 'Le llega a '
                        . ($record->requestedBy?->name ?? 'quien lo pidió')
                        . ' con un enlace donde ve qué entregaríamos, en cuánto tiempo y por cuánto.')
                    ->modalSubmitActionLabel('Enviar')
                    ->schema([
                        Textarea::make('mensaje')
                            ->label('Algo que quieras añadir')
                            ->rows(3)
                            ->helperText('Va dentro del correo, antes del cierre. Opcional.'),
                    ])
                    ->action(function (Project $record, array $data) {
                        $enlace = URL::temporarySignedRoute(
                            'proyectos.propuesta',
                            now()->addDays(60),
                            ['project' => $record->id],
                        );

                        app(NotificationService::class)->enviar('proyecto.propuesta', $record->requestedBy, [
                            'proyecto' => $record->name,
                            'codigo'   => $record->code,
                            'enlace'   => $enlace,
                            'mensaje'  => $data['mensaje'] ?? '',
                        ], $record);

                        $record->update(['proposal_sent_at' => now()]);

                        Notification::make()
                            ->success()
                            ->title('Propuesta enviada')
                            ->body('A ' . $record->requestedBy->email . '. El enlace vale 60 días.')
                            ->send();
                    }),

                self::tablero(),
                self::avanzar(),
                self::mover(),
                self::descartar(),
                EditAction::make(),
            ]);
    }

    private static function tablero(): Action
    {
        return Action::make('tablero')
            ->label('Tablero')
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
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->visible(fn (Project $r) => app(ProjectService::class)->siguienteEtapa($r) !== null
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
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
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

    private static function descartar(): Action
    {
        return Action::make('descartar')
            ->label('Descartar')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->visible(fn (Project $r) => $r->status === 'activo')
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
}
