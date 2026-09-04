<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Models\Course;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('level')
            ->columns([
                TextColumn::make('name')
                    ->label('Curso')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Course $r) => $r->area?->name),

                TextColumn::make('level')
                    ->label('Nivel')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state),

                TextColumn::make('habilita')
                    ->label('Habilita')
                    ->state(fn (Course $r) => $r->riskFamilies->pluck('name')->implode(', ') ?: '—')
                    ->wrap()
                    ->tooltip('Familias de riesgo sobre las que otorga certifab al aprobarlo'),

                TextColumn::make('hours')->label('Horas')->alignEnd()->placeholder('—'),

                TextColumn::make('ediciones')
                    ->label('Ediciones')
                    ->alignEnd()
                    ->state(fn (Course $r) => $r->editions()->count()),

                /*
                 * Se apaga y se enciende desde la lista, sin entrar. Apagar
                 * no borra nada: el curso deja de ofrecerse y se queda con
                 * su gente y sus notas. Solo quien puede editar lo toca.
                 */
                ToggleColumn::make('is_public')
                    ->label('En el sitio')
                    ->tooltip('Si sale en la vitrina pública')
                    ->disabled(fn (Course $r) => ! auth()->user()->can('update', $r)),

                ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->tooltip('Apagado, no se ofrece ni se abren ediciones. No se pierde nada.')
                    ->disabled(fn (Course $r) => ! auth()->user()->can('update', $r)),
            ])
            ->filters([
                SelectFilter::make('level')->label('Nivel')->options(Course::NIVELES),
                TernaryFilter::make('is_active')->label('Activo')->default(true),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Editar'),

                /*
                 * Borrar, solo si nadie paso por el. Con gente inscrita el
                 * boton se queda pero no responde, y dice por que: un boton
                 * que desaparece deja a la persona buscandolo.
                 */
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip(fn (Course $r) => $r->porQueNoSeBorra() ?? 'Borrar el curso')
                    ->disabled(fn (Course $r) => ! $r->sePuedeBorrar())
                    ->modalHeading(fn (Course $r) => 'Borrar «' . $r->name . '»')
                    ->modalDescription(fn (Course $r) => self::queSeVa($r)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('apagar')
                        ->label('Apagar')
                        ->icon('heroicon-o-power')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Apagar los cursos seleccionados')
                        ->modalDescription('Dejan de ofrecerse y de salir en el sitio. No se pierde nada: su gente y sus notas se quedan, y se pueden volver a encender.')
                        ->authorize(fn () => auth()->user()->can('update', new Course))
                        ->action(function (Collection $records) {
                            $records->each->update(['is_active' => false, 'is_public' => false]);

                            Notification::make()->title('Apagados')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('encender')
                        ->label('Encender')
                        ->icon('heroicon-o-bolt')
                        ->color('success')
                        ->authorize(fn () => auth()->user()->can('update', new Course))
                        ->action(function (Collection $records) {
                            $records->each->update(['is_active' => true]);

                            Notification::make()->title('Encendidos')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    /*
                     * Borrar por lotes. Se borra lo que se puede; lo que tiene
                     * gente se queda y se dice cual. No se apaga por su cuenta:
                     * apagar es otra decision, y esta a un clic.
                     */
                    BulkAction::make('borrar')
                        ->label('Borrar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Borrar los cursos seleccionados')
                        ->modalDescription('Se borran los que no tienen gente inscrita, con su teoría, su examen y sus ediciones vacías. Los que tienen gente se quedan: esos solo se apagan.')
                        ->authorize(fn () => auth()->user()->can('delete', new Course))
                        ->action(function (Collection $records) {
                            [$seVan, $seQuedan] = $records->partition(fn (Course $c) => $c->sePuedeBorrar());

                            $seVan->each->delete();

                            if ($seQuedan->isEmpty()) {
                                Notification::make()
                                    ->title($seVan->count() === 1 ? 'Curso borrado' : $seVan->count() . ' cursos borrados')
                                    ->success()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title($seVan->isEmpty()
                                    ? 'No se borró ninguno'
                                    : ($seVan->count() === 1 ? 'Se borró uno' : 'Se borraron ' . $seVan->count()))
                                ->body('Con gente inscrita no se borran, se apagan: ' . $seQuedan->pluck('name')->implode(', ') . '.')
                                ->warning()
                                ->persistent()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /** Lo que se lleva borrarlo, para decirlo antes. */
    public static function queSeVa(Course $c): string
    {
        $partes = array_filter([
            ($n = $c->lessons()->count()) ? ($n === 1 ? 'una pantalla de teoría' : "{$n} pantallas de teoría") : null,
            ($n = $c->questions()->count()) ? ($n === 1 ? 'una pregunta' : "{$n} preguntas") : null,
            ($n = $c->editions()->count()) ? ($n === 1 ? 'una edición vacía' : "{$n} ediciones vacías") : null,
        ]);

        return $partes
            ? 'Se va con ' . implode(', ', $partes) . '. Nadie pasó por él, así que no se pierde ninguna nota.'
            : 'Nadie pasó por él y no tiene teoría ni examen: no se pierde nada.';
    }
}
