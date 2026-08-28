<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidad')
                    ->description('El correo es el identificador: no se cambia a la ligera, porque de él cuelga todo el historial.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                        TextInput::make('email')->label('Correo')->email()->required()->maxLength(255),
                        TextInput::make('document_number')->label('Documento')->maxLength(255),
                        TextInput::make('phone')->label('Teléfono')->tel()->maxLength(255),
                    ]),

                Section::make('Categoría y acceso')
                    ->columns(2)
                    ->schema([
                        Select::make('user_category_id')
                            ->label('Categoría')
                            ->relationship('category', 'name')
                            ->preload()
                            ->helperText('Determina tarifas, cupos y dotación.'),

                        Toggle::make('category_confirmed')
                            ->label('Categoría confirmada')
                            ->helperText('Márcala cuando verifiques que es estudiante, docente o colaborador.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options([
                                'pendiente'  => 'Pendiente',
                                'activo'     => 'Activo',
                                'suspendido' => 'Suspendido',
                                'inactivo'   => 'Inactivo',
                            ])
                            ->default('activo')
                            ->required(),

                        Select::make('roles')
                            ->label('Rol en el backoffice')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            // Con su nombre de verdad: «practicante» en minuscula
                            // es como se llama la fila en la base, no como se
                            // habla de una persona.
                            ->getOptionLabelFromRecordUsing(
                                fn ($record) => \App\Models\User::ROLES[$record->name] ?? $record->name
                            )
                            ->helperText('Sin rol, la persona usa el sistema pero no entra al backoffice. Qué ve cada rol se decide en Configuración → Roles y accesos.'),
                    ]),
            ]);
    }
}
