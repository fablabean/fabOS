<?php

namespace App\Filament\Resources\Credenciales\Tables;

use App\Filament\Resources\Credenciales\Actions\RevelarElSecreto;
use App\Models\Credencial;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La lista de credenciales (§19).
 *
 * Lo que NO hay aqui: ninguna columna con el secreto, ni oculta ni recortada.
 * Una columna que se puede «mostrar» desde el selector de columnas acabaria
 * poniendo la clave en el HTML de quien solo venia a mirar la lista, sin
 * pulsar nada y sin quedar registrado.
 */
class CredencialesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nombre')
            ->columns([
                TextColumn::make('nombre')
                    ->label('Qué es')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Credencial $r) => $r->software?->nombre),

                TextColumn::make('tipo')
                    ->label('Clase')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Credencial::TIPOS[$state] ?? $state),

                // El usuario si, el secreto no: saber a que cuenta pertenece
                // no es el secreto, y sin eso la lista no sirve para nada.
                TextColumn::make('usuario')
                    ->label('Usuario')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('owner.name')
                    ->label('Responsable')
                    ->toggleable(),

                /*
                 * Cuando se miro por ultima vez, y quien.
                 *
                 * Es lo que hace que el registro se use: ver ahi un nombre que
                 * no esperabas es la unica forma de enterarse de que una clave
                 * circula mas de lo que creias.
                 */
                TextColumn::make('ultima_lectura')
                    ->label('Vista por última vez')
                    ->state(fn (Credencial $r) => $r->ultimaLectura()
                        ?->created_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i'))
                    ->description(fn (Credencial $r) => $r->ultimaLectura()?->quien())
                    ->placeholder('nunca'),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Clase')->options(Credencial::TIPOS),
                SelectFilter::make('software_id')->label('Programa')->relationship('software', 'nombre'),
            ])
            ->recordActions([
                RevelarElSecreto::make(),
                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('No tienes credenciales guardadas')
            ->emptyStateDescription('Aquí va cómo se entra a los servicios del laboratorio: el panel del proveedor de correo, la cuenta del fabricante, el router. El día que quien lo montó no esté, esto es lo que evita quedarse fuera.');
    }
}
