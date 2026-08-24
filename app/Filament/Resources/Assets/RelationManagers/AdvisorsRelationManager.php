<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Quién asesora sobre este equipo (§10).
 *
 * No se deriva de los certifabs a propósito. Estar certificada para usar una
 * máquina y ser quien atiende al público sobre ella son cosas distintas: media
 * plantilla puede estar certificada en la láser y aun así la asesoría la dan
 * dos personas concretas. Es una decisión de coordinación, y por eso se declara
 * aquí a mano.
 */
class AdvisorsRelationManager extends RelationManager
{
    protected static string $relationship = 'advisors';

    protected static ?string $title = 'Quién asesora';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('es_responsable')
                ->label('Es la responsable del equipo')
                ->helperText('Si hay responsable, todas las asesorías de este equipo son suyas y no entran en el reparto rotativo.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->emptyStateHeading('Nadie declarado todavía')
            ->emptyStateDescription(
                'Sin asesores declarados, nadie puede pedir una asesoría sobre este equipo: '
                . 'el sistema no tendría a quién asignársela.'
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Persona')
                    ->searchable(),

                IconColumn::make('es_responsable')
                    ->label('Responsable')
                    ->state(fn (User $record) => (bool) $record->pivot->es_responsable)
                    ->boolean()
                    ->tooltip('Las asesorías van siempre a la responsable; el resto se reparten por turno'),

                TextColumn::make('email')
                    ->label('Correo')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Declarar asesor')
                    ->modalHeading('Quién más puede asesorar sobre este equipo')
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Persona'),
                        Toggle::make('es_responsable')
                            ->label('Es la responsable del equipo'),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Cambiar'),
                DetachAction::make()->label('Quitar'),
            ]);
    }
}
