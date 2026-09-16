<?php

namespace App\Filament\Resources\Software\RelationManagers;

use App\Filament\Resources\Credenciales\Actions\RevelarElSecreto;
use App\Filament\Resources\Credenciales\CredencialResource;
use App\Models\Credencial;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las credenciales de este programa, en su ficha (§19).
 *
 * Es de solo lectura: se guardan y editan en su propia seccion. Duplicar aqui
 * el formulario daria dos sitios donde escribir un secreto, y basta con que
 * uno de los dos olvide una regla para que la regla no exista.
 *
 * **Filtrada por dueño igual que la seccion.** Una relacion que enseñara las
 * credenciales de otros seria la puerta de atras que deja sin efecto toda la
 * regla: bastaria abrir la ficha del programa.
 */
class CredencialesRelationManager extends RelationManager
{
    protected static string $relationship = 'credenciales';

    protected static ?string $title = 'Cómo se entra';

    protected static ?string $modelLabel = 'credencial';

    /** El mismo filtro que la seccion, y en la consulta base. */
    public function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()?->visiblesPara(auth()->user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                TextColumn::make('nombre')->label('Qué es')->weight('medium'),

                TextColumn::make('tipo')
                    ->label('Clase')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Credencial::TIPOS[$state] ?? $state),

                TextColumn::make('usuario')->label('Usuario')->copyable()->placeholder('—'),

                TextColumn::make('owner.name')->label('Responsable'),
            ])
            ->headerActions([
                // Lleva a la seccion en vez de traer el formulario aqui.
                Action::make('ir')
                    ->label('Guardar una credencial')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => CredencialResource::getUrl('create'))
                    ->visible(fn () => CredencialResource::canCreate()),
            ])
            ->recordActions([
                RevelarElSecreto::make('revelarDesdeSoftware'),

                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->url(fn (Credencial $record) => CredencialResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Credencial $record) => CredencialResource::canEdit($record)),
            ])
            ->emptyStateHeading('No hay credenciales tuyas para este programa')
            ->emptyStateDescription('Puede haberlas de otra persona: aquí solo se ven las que tienes tú.');
    }
}
