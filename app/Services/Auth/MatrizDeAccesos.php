<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Secciones;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quién ve qué sección del panel (§5).
 *
 * La matriz vive en la base, no en el código: cambiar «el practicante no ve
 * Finanzas» era desplegar, y por eso no se cambiaba —se le daba el rol de
 * consultor y se miraba para otro lado—.
 */
class MatrizDeAccesos
{
    /**
     * Pone al día los permisos con las secciones que existen hoy.
     *
     * Corre en cada despliegue. Es idempotente **y no pisa lo que el
     * laboratorio haya decidido**: solo reparte los valores por defecto a los
     * roles que todavía no tienen ninguno, que es como decir «la primera vez».
     * Si volviera a repartirlos siempre, cada despliegue desharía los ajustes
     * de la pantalla, y eso se descubre tarde y mal.
     *
     * Una sección nueva sí se le abre al administrador sola. Si no, aparecería
     * en el menú de nadie salvo del superadmin, y quedaría en el limbo hasta
     * que alguien se acordara de ir a marcarla.
     */
    public function sincronizar(): void
    {
        $nuevos = [];

        foreach (Secciones::permisos() as $permiso) {
            if (! Permission::where('name', $permiso)->where('guard_name', 'web')->exists()) {
                Permission::create(['name' => $permiso, 'guard_name' => 'web']);
                $nuevos[] = $permiso;
            }
        }

        foreach (array_keys(User::ROLES) as $nombre) {
            Role::findOrCreate($nombre, 'web');
        }

        $this->olvidarLaCache();

        foreach (Secciones::porDefecto() as $nombre => $claves) {
            $rol = Role::findByName($nombre, 'web');

            if ($rol->permissions()->count() === 0) {
                $rol->syncPermissions(array_map(fn (string $c) => 'ver.' . $c, $claves));

                continue;
            }

            // Ya configurado a mano: solo lo que acaba de existir, y solo al
            // administrador, que es quien lo ve todo salvo lo del superadmin.
            if ($nombre === User::ROL_ADMINISTRADOR && $nuevos !== []) {
                $rol->givePermissionTo($nuevos);
            }
        }

        $this->olvidarLaCache();
    }

    /**
     * La matriz para pintarla: rol => clave de sección => si la ve.
     *
     * El superadmin no está: lo ve todo por código, y ofrecer una casilla que
     * no hace nada —o peor, que sí la hace y te deja fuera— es mentir con la
     * interfaz.
     *
     * @return array<string, array<string, bool>>
     */
    public function matriz(): array
    {
        $matriz = [];

        foreach ($this->rolesEditables() as $nombre) {
            $tiene = Role::findByName($nombre, 'web')->permissions->pluck('name')->all();

            foreach (array_keys(Secciones::todas()) as $clave) {
                $matriz[$nombre][$clave] = in_array('ver.' . $clave, $tiene, true);
            }
        }

        return $matriz;
    }

    /**
     * Guarda lo marcado.
     *
     * @param  array<string, array<string, bool>>  $matriz
     */
    public function guardar(array $matriz): void
    {
        DB::transaction(function () use ($matriz) {
            foreach ($this->rolesEditables() as $nombre) {
                $marcadas = collect($matriz[$nombre] ?? [])
                    ->filter()
                    ->keys()
                    // Solo secciones que existen: una clave inventada por un
                    // formulario manipulado no crea un permiso nuevo.
                    ->filter(fn (string $clave) => isset(Secciones::todas()[$clave]))
                    ->map(fn (string $clave) => 'ver.' . $clave)
                    ->all();

                Role::findByName($nombre, 'web')->syncPermissions($marcadas);
            }
        });

        $this->olvidarLaCache();
    }

    /** Todos menos el superadmin, que no se toca. */
    public function rolesEditables(): array
    {
        return array_values(array_diff(array_keys(User::ROLES), [User::ROL_SUPERADMIN]));
    }

    /**
     * Spatie cachea los permisos.
     *
     * Sin vaciar la caché, la pantalla diría que el cambio se guardó y el menú
     * seguiría igual hasta que caducara sola. Es exactamente el fallo que hace
     * que alguien vuelva a marcar la casilla tres veces.
     */
    private function olvidarLaCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
