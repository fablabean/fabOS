<?php

namespace App\Filament\Componentes;

use App\Models\User;
use App\Models\UserCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Spatie\Permission\Models\Role;

/**
 * Crear a alguien sin salir del formulario donde hace falta.
 *
 * Llama alguien de fuera, se le toma la reserva o se le inscribe, y no tiene
 * cuenta. Quien la crea desde aqui la tiene enfrente o al telefono, asi que
 * sabe quien es: se le pone la categoria y, si es del equipo, el rol, y nace
 * validada. Antes nacia como invitada sin confirmar y alguien tenia que
 * volver a Personas a terminar el trabajo.
 *
 * Con el correo se reutiliza la cuenta si ya la tenia: dos cuentas con el
 * mismo correo parten su historial en dos.
 */
class NuevaPersona
{
    /** @return array<int,\Filament\Schemas\Components\Component> */
    public static function formulario(): array
    {
        return [
            TextInput::make('name')->label('Nombre')->required()->maxLength(120),
            TextInput::make('email')->label('Correo')->email()->required()->maxLength(160),
            CampoDeTelefono::make('phone'),

            Select::make('user_category_id')
                ->label('Categoría')
                ->options(fn () => UserCategory::orderBy('position')->orderBy('id')->pluck('name', 'id'))
                ->required()
                ->helperText('Queda confirmada: quien la crea sabe quién es.'),

            Select::make('roles')
                ->label('Rol en el panel')
                ->options(User::ROLES)
                ->multiple()
                ->placeholder('Ninguno: no es del equipo')
                ->helperText('Solo si es del equipo del laboratorio.'),
        ];
    }

    /** @param  array<string,mixed>  $data */
    public static function crear(array $data): User
    {
        $correo = mb_strtolower(trim($data['email']));

        $persona = User::whereRaw('lower(email) = ?', [$correo])->first();

        if ($persona) {
            return $persona;
        }

        $persona = User::create([
            'name'               => trim($data['name']),
            'email'              => $correo,
            'phone'              => $data['phone'] ?? null,
            'status'             => 'activo',
            'user_category_id'   => $data['user_category_id'] ?? UserCategory::where('slug', 'invitado')->value('id'),
            'category_confirmed' => true,
        ]);

        // Validada por quien la creo, igual que con «Validar y dar acceso».
        $persona->forceFill([
            'validated_by_id' => auth()->id(),
            'validated_at'    => now(),
        ])->save();

        foreach (array_filter((array) ($data['roles'] ?? [])) as $rol) {
            if (array_key_exists($rol, User::ROLES)) {
                $persona->assignRole(Role::findOrCreate($rol, 'web'));
            }
        }

        return $persona;
    }
}
