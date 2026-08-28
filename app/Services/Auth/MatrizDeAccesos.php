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

        foreach (Secciones::porDefecto() as $nombre => $porSeccion) {
            $rol = Role::findByName($nombre, 'web');

            if ($rol->permissions()->count() === 0) {
                $permisos = [];

                foreach ($porSeccion as $clave => $acciones) {
                    foreach ($acciones as $accion) {
                        $permisos[] = $accion . '.' . $clave;
                    }
                }

                $rol->syncPermissions($permisos);

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
     * La matriz para pintarla: rol => clave de sección => acción => si puede.
     *
     * El superadmin no está: lo ve todo por código, y ofrecer una casilla que
     * no hace nada —o peor, que sí la hace y te deja fuera— es mentir con la
     * interfaz.
     *
     * @return array<string, array<string, array<string, bool>>>
     */
    public function matriz(): array
    {
        $matriz = [];

        foreach ($this->rolesEditables() as $nombre) {
            $tiene = Role::findByName($nombre, 'web')->permissions->pluck('name')->all();

            foreach (Secciones::todas() as $clave => $seccion) {
                foreach (array_keys(Secciones::accionesDe($seccion['clase'])) as $accion) {
                    $matriz[$nombre][$clave][$accion] = in_array($accion . '.' . $clave, $tiene, true);
                }
            }
        }

        return $matriz;
    }

    /**
     * Guarda lo marcado.
     *
     * Dos cosas se corrigen aquí en silencio, y a propósito:
     *
     *  · Una sección o una acción que no existe se descarta. El formulario
     *    llega del navegador; no es la autoridad sobre qué permisos hay.
     *  · **Sin ver, nada.** Marcar «editar» y dejar «ver» apagado deja un
     *    permiso que no se puede ejercer, y alguien buscando después por qué
     *    no aparece el botón.
     *
     * @param  array<string, array<string, array<string, bool>>>  $matriz
     */
    public function guardar(array $matriz): void
    {
        $secciones = Secciones::todas();

        DB::transaction(function () use ($matriz, $secciones) {
            foreach ($this->rolesEditables() as $nombre) {
                $permisos = [];

                foreach ($matriz[$nombre] ?? [] as $clave => $acciones) {
                    if (! isset($secciones[$clave]) || empty($acciones['ver'])) {
                        continue;
                    }

                    $aplican = Secciones::accionesDe($secciones[$clave]['clase']);

                    foreach ($acciones as $accion => $marcada) {
                        if ($marcada && isset($aplican[$accion])) {
                            $permisos[] = $accion . '.' . $clave;
                        }
                    }
                }

                // Crear lo que falte antes de asignarlo: si una seccion es
                // mas nueva que la ultima sincronizacion, Spatie no reconoce
                // el permiso y la pantalla revienta al guardar en vez de
                // guardar.
                $this->asegurarQueExisten($permisos);

                Role::findByName($nombre, 'web')->syncPermissions($permisos);
            }
        });

        $this->olvidarLaCache();
    }

    /** @param  list<string>  $permisos */
    private function asegurarQueExisten(array $permisos): void
    {
        $existentes = Permission::whereIn('name', $permisos)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        foreach (array_diff($permisos, $existentes) as $permiso) {
            Permission::create(['name' => $permiso, 'guard_name' => 'web']);
        }

        if (array_diff($permisos, $existentes) !== []) {
            $this->olvidarLaCache();
        }
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
