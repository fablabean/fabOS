<?php

namespace App\Filament\Resources\CourseEditions\Tables;

use App\Models\CourseEdition;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseEditionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('code')->label('Código')->searchable()->weight('bold'),

                TextColumn::make('course.name')
                    ->label('Curso')
                    ->searchable()
                    ->description(fn (CourseEdition $r) => $r->course?->level),

                TextColumn::make('starts_on')
                    ->label('Empieza')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (CourseEdition $r) => $r->schedule_note),

                TextColumn::make('instructor.name')->label('Instructor')->placeholder('sin asignar'),

                TextColumn::make('cupo')
                    ->label('Cupo')
                    ->alignEnd()
                    ->state(fn (CourseEdition $r) => $r->inscritos() . ' / ' . $r->capacity)
                    ->color(fn (CourseEdition $r) => $r->cuposLibres() === 0 ? 'danger' : null)
                    ->description(fn (CourseEdition $r) => $r->cuposLibres() . ' libres'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CourseEdition::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'abierta'  => 'success',
                        'en_curso' => 'info',
                        'cerrada'  => 'gray',
                        'planeada' => 'warning',
                        default    => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(CourseEdition::ESTADOS),
                SelectFilter::make('course_id')->label('Curso')->relationship('course', 'name'),
            ])
            ->recordActions([
                self::abrir(),
                self::cerrar(),
                EditAction::make(),
            ]);
    }

    private static function abrir(): Action
    {
        return Action::make('abrir')
            ->label('Abrir inscripciones')
            ->icon('heroicon-o-lock-open')
            ->color('success')
            ->visible(fn (CourseEdition $r) => $r->status === 'planeada')
            ->requiresConfirmation()
            ->modalDescription('A partir de ahora aparece en el sitio y la gente puede inscribirse hasta llenar el cupo.')
            ->action(function (CourseEdition $record) {
                $record->update(['status' => 'abierta']);

                Notification::make()->title('Inscripciones abiertas')->success()->send();
            });
    }

    /**
     * Cerrar aprueba de una vez a quien siga inscrito: es el caso normal de un
     * taller corto. Quien no debía aprobar se marca antes, uno por uno.
     */
    private static function cerrar(): Action
    {
        return Action::make('cerrar')
            ->label('Cerrar y aprobar')
            ->icon('heroicon-o-academic-cap')
            ->color('warning')
            ->visible(fn (CourseEdition $r) => in_array($r->status, ['abierta', 'en_curso'], true))
            ->requiresConfirmation()
            ->modalHeading('Cerrar la edición')
            ->modalDescription(fn (CourseEdition $r) => 'Se aprobará a las '
                . $r->enrollments()->where('status', 'inscrito')->count()
                . ' personas que sigan inscritas: cada una recibe su certificado y los certifabs del curso. '
                . 'Si alguien no debe aprobar, márcalo antes desde la lista de inscritos.')
            ->action(function (CourseEdition $record) {
                try {
                    $n = app(TrainingService::class)->cerrarEdicion($record, auth()->user());
                } catch (TrainingException $e) {
                    Notification::make()->title('No se pudo cerrar')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Edición cerrada')
                    ->body($n . ' personas aprobadas y habilitadas.')
                    ->success()
                    ->send();
            });
    }
}
