<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Persona')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (User $record) => $record->email),

                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->badge()
                    ->color('gray')
                    ->placeholder('sin asignar'),

                IconColumn::make('category_confirmed')
                    ->label('Confirmada')
                    ->boolean()
                    ->tooltip('El correo prueba pertenencia a la institución, no si es estudiante o docente'),

                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),

                TextColumn::make('certifabs_count')
                    ->label('Certifabs')
                    ->counts('certifabs')
                    ->badge()->color('gray'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => $state === 'activo' ? 'success' : 'gray'),

                TextColumn::make('identity_verified_via')
                    ->label('Verificado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name')
                    ->preload(),

                SelectFilter::make('roles')
                    ->label('Rol')
                    ->relationship('roles', 'name')
                    ->preload(),

                TernaryFilter::make('category_confirmed')
                    ->label('Categoría confirmada')
                    ->placeholder('Todas')
                    ->trueLabel('Solo confirmadas')
                    ->falseLabel('Pendientes de confirmar'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
