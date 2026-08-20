<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Otorga un rol de backoffice desde la consola.
 *
 * Existe porque el primer superadmin no puede crearse desde la interfaz: haria
 * falta ya estar dentro. Es la unica puerta de entrada al panel, y por eso vive
 * en el servidor y no en la web.
 */
class GrantRole extends Command
{
    protected $signature = 'fabos:grant
                            {email : Correo de la persona}
                            {role=superadmin : consultor|administrador|superadmin}
                            {--categoria= : estudiante|profesor|colaborador|externo|invitado}
                            {--nombre= : Nombre a mostrar}';

    protected $description = 'Otorga un rol de backoffice a un usuario';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $role  = Str::lower(trim($this->argument('role')));

        if (! in_array($role, User::ROLES_BACKOFFICE, true)) {
            $this->error("Rol no válido. Opciones: " . implode(', ', User::ROLES_BACKOFFICE));

            return self::FAILURE;
        }

        $user = User::firstWhere('email', $email);

        if (! $user) {
            // Se crea sin contrasena: entrara con su codigo al correo como todos.
            $user = User::create([
                'name'   => Str::of(Str::before($email, '@'))->replace(['.', '_'], ' ')->title()->value(),
                'email'  => $email,
                'status' => 'activo',
            ]);
            $this->line("Usuario creado: {$user->name}");
        }

        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        $user->update(['status' => 'activo']);

        if ($nombre = $this->option('nombre')) {
            $user->update(['name' => $nombre]);
        }

        if ($categoria = Str::lower(trim((string) $this->option('categoria')))) {
            $cat = UserCategory::firstWhere('slug', $categoria);

            if (! $cat) {
                $this->error('Categoría no válida: ' . UserCategory::pluck('slug')->implode(', '));

                return self::FAILURE;
            }

            // Asignada desde consola: se da por confirmada, no queda pendiente
            // de que la coordinación la revise.
            $user->update([
                'user_category_id'   => $cat->id,
                'category_confirmed' => true,
            ]);
        }

        $user->refresh();

        $this->info("«{$role}» otorgado a {$email}");
        $this->table(
            ['Nombre', 'Categoría', 'Roles'],
            [[$user->name, $user->category?->name ?? '—', $user->getRoleNames()->implode(', ')]]
        );
        $this->line('Ingresa por /ingresar con ese correo; el código llega a Mailpit.');

        return self::SUCCESS;
    }
}
