<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Roles internos (§5): consultor ve, administrador crea y edita, superadmin
 * configura. Son un eje distinto de la categoria de la persona (estudiante,
 * profesor, externo...), que define tarifas y cupos, no permisos.
 *
 * Instructor y tecnico se modelan como conjuntos de permisos sobre el rol
 * administrador, no como niveles nuevos.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::ROLES_BACKOFFICE as $role) {
            Role::findOrCreate($role, 'web');
        }

        // Comunicaciones entra al panel pero solo al banco de contenido: viene
        // a buscar material para divulgacion, no a mirar reservas ni saldos.
        Role::findOrCreate(User::ROL_COMUNICACIONES, 'web');
    }
}
