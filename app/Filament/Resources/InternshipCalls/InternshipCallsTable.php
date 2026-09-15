<?php

namespace App\Filament\Resources\InternshipCalls;

use App\Models\InternshipCall;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Las convocatorias, con lo que falta por decidir a la vista.
 *
 * La pregunta con la que se abre esta pantalla es «¿cuántos me quedan por
 * mirar?», y por eso los pendientes son una columna y no un dato que haya que
 * ir a buscar entrando.
 */
class InternshipCallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Convocatoria')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (InternshipCall $r) => $r->period),

                TextColumn::make('closes_on')
                    ->label('Cierra')
                    ->date('d/m/Y')
                    ->placeholder('sin fecha')
                    ->description(fn (InternshipCall $r) => $r->closes_on && $r->closes_on->isPast() ? 'ya pasó' : null),

                TextColumn::make('postulaciones')
                    ->label('Postulaciones')
                    ->alignEnd()
                    ->state(fn (InternshipCall $r) => $r->applications()->count()),

                TextColumn::make('pendientes')
                    ->label('Sin evaluar')
                    ->alignEnd()
                    ->badge()
                    ->state(fn (InternshipCall $r) => $r->pendientes() ?: '—')
                    ->color(fn (InternshipCall $r) => $r->pendientes() > 0 ? 'warning' : 'gray'),

                TextColumn::make('aceptados')
                    ->label('Aceptados')
                    ->alignEnd()
                    ->state(fn (InternshipCall $r) => $r->slots === null
                        ? (string) $r->aceptados()
                        : $r->aceptados() . ' de ' . $r->slots)
                    // Quien acepta de más tiene que enterarse: esconderlo haría
                    // que el cupo se descubriera al firmar los convenios.
                    ->color(fn (InternshipCall $r) => $r->cuposLibres() !== null && $r->cuposLibres() < 0 ? 'danger' : null)
                    ->description(fn (InternshipCall $r) => $r->sinCuenta() > 0
                        ? $r->sinCuenta() . ' sin cuenta'
                        : null),

                TextColumn::make('is_public')
                    ->label('En el sitio')
                    ->badge()
                    ->state(fn (InternshipCall $r) => $r->is_public ? 'Recibiendo' : 'Interna')
                    ->color(fn (InternshipCall $r) => $r->is_public ? 'success' : 'gray'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => InternshipCall::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'abierta'  => 'info',
                        'evaluada' => 'success',
                        default    => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(InternshipCall::ESTADOS),
            ])
            ->recordActions([
                // El enlace que se anuncia. Sin esto hay que armarlo a mano y
                // el que se pega en Instagram acaba siendo el de otra.
                Action::make('enlace')
                    ->label('Ver la página pública')
                    ->iconButton()
                    ->tooltip('Abrir la página donde se postulan')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (InternshipCall $r) => $r->is_public)
                    ->url(fn (InternshipCall $r) => route('practicas.postular', $r))
                    ->openUrlInNewTab(),

                EditAction::make()->iconButton()->tooltip('Editar'),

                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Borrar')
                    ->modalDescription(fn (InternshipCall $r) => 'Se va con sus '
                        . $r->applications()->count()
                        . ' postulaciones y sus hojas de vida. Las cuentas que ya se crearon se quedan.'),
            ])
            ->emptyStateHeading('Todavía no hay convocatorias')
            ->emptyStateDescription('Una convocatoria es la tanda de un semestre. Se evalúa con la tanda entera delante, que es como se compara de verdad.')
            ->description('Quien se postula no tiene cuenta y no la necesita: la recibe si lo aceptan, con el rol de practicante.');
    }
}
