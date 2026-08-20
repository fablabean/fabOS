<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\Auth\LoginCodeService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
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
            ->recordActions([
                self::validarYDarAcceso(),
                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /**
     * Validar a alguien que esta delante, y darle el acceso en el momento.
     *
     * Es la puerta que no depende del correo. Quien atiende el laboratorio tiene
     * a la persona enfrente —una comprobacion de identidad mas fuerte que
     * cualquier buzon— y le dicta un codigo que sirve una vez y caduca en
     * quince minutos.
     *
     * Con ese ingreso la persona configura su app de autenticacion, y a partir
     * de ahi entra sola. El correo pasa a ser util para avisos, no imprescindible
     * para entrar.
     */
    private static function validarYDarAcceso(): Action
    {
        return Action::make('validar')
            ->label('Validar y dar acceso')
            ->icon('heroicon-o-identification')
            ->color('primary')
            ->modalHeading(fn (User $record) => 'Dar acceso a ' . $record->name)
            ->modalDescription(
                'Solo cuando la persona este delante. El codigo sirve una vez y caduca en 15 minutos.'
            )
            ->modalSubmitActionLabel('Generar el codigo')
            // El consultor mira pero no da acceso: dar acceso es un acto, no una
            // consulta.
            ->visible(fn () => auth()->user()?->hasAnyRole([
                User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN,
            ]) ?? false)
            ->action(function (User $record) {
                $codigo = app(LoginCodeService::class)->emitirEnMano($record->email);

                $record->forceFill([
                    'category_confirmed' => true,
                    'validated_by_id'    => auth()->id(),
                    'validated_at'       => now(),
                ])->save();

                Notification::make()
                    ->title('Codigo para ' . $record->name)
                    ->body("**{$codigo}**

Que lo escriba en " . route('login') . " con su correo. Caduca en 15 minutos.")
                    ->persistent()
                    ->success()
                    ->send();
            });
    }
}
