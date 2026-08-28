<?php

namespace App\Services\Auth;

use App\Models\Setting;
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
    /** Que el reparto de las acciones nuevas ya se hizo. */
    private const YA_HEREDADO = 'accesos.acciones_heredadas';

    /** Y que se corrigio: el primer reparto abrio de mas. */
    private const YA_REPARADO = 'accesos.acciones_reparadas';

    /**
     * Secciones que el sistema anterior reservaba al superadmin.
     *
     * Un administrador podia VER las personas, no editarlas: darse permisos a
     * uno mismo no deberia estar a un clic.
     */
    private const RESERVADAS = ['user'];

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

        $this->heredarLasAccionesDeLoQueYaSeVeia();
        $this->repararElRepartoQueAbrioDeMas();

        $this->olvidarLaCache();
    }

    /**
     * Una sola vez: lo que se veía, se podía tocar.
     *
     * Antes de que existieran crear, editar y borrar, el permiso de **ver** era
     * la unica llave: quien veia una seccion podia crear y borrar en ella. Si
     * al partir la llave en cuatro no se reparten las tres nuevas, el dia del
     * despliegue el consultor amanece sin poder editar nada —sin que nadie lo
     * decidiera, y sin que nadie lo sepa hasta que se queje—.
     *
     * Corre una vez y se anota, para no volver a abrir lo que el laboratorio
     * cierre despues a proposito.
     */
    private function heredarLasAccionesDeLoQueYaSeVeia(): void
    {
        if (Setting::get(self::YA_HEREDADO)) {
            return;
        }

        foreach ($this->rolesEditables() as $nombre) {
            $rol = Role::findByName($nombre, 'web');
            $tiene = $rol->permissions->pluck('name');

            // Si ya distingue acciones, alguien lo configuro: no se toca.
            if ($tiene->contains(fn (string $p) => ! str_starts_with($p, 'ver.'))) {
                continue;
            }

            $nuevos = $this->loQuePodiaHacer($nombre, $tiene->all());

            if ($nuevos !== []) {
                $this->asegurarQueExisten($nuevos);
                $rol->givePermissionTo($nuevos);
            }
        }

        Setting::put(self::YA_HEREDADO, true, 'accesos');
    }

    /**
     * Lo que un rol podia hacer de verdad antes de la matriz.
     *
     * Y «de verdad» no es lo que decia el recurso: encima habia una **politica**
     * —consultor ve, administrador crea y edita, superadmin borra— que se
     * aplicaba a todo el backoffice. Mirar solo el recurso hacia creer que
     * quien veia una seccion podia editarla, y no era cierto para nadie salvo
     * el administrador.
     *
     * @param  list<string>  $tiene  permisos actuales del rol
     * @return list<string>
     */
    private function loQuePodiaHacer(string $rol, array $tiene): array
    {
        // Borrar era solo del superadmin, y el superadmin no esta en la matriz.
        if ($rol !== User::ROL_ADMINISTRADOR) {
            return [];
        }

        $nuevos = [];

        foreach ($tiene as $permiso) {
            if (! str_starts_with($permiso, 'ver.')) {
                continue;
            }

            $clave = substr($permiso, strlen('ver.'));
            $seccion = Secciones::todas()[$clave] ?? null;

            if (! $seccion || in_array($clave, self::RESERVADAS, true)) {
                continue;
            }

            $aplican = Secciones::accionesDe($seccion['clase']);

            foreach (['crear', 'editar'] as $accion) {
                if (isset($aplican[$accion])) {
                    $nuevos[] = $accion . '.' . $clave;
                }
            }
        }

        return $nuevos;
    }

    /**
     * Corrige el primer reparto, que abrio de mas.
     *
     * Aquel reparto miro solo lo que decia cada recurso y concluyo que quien
     * veia una seccion podia crear, editar y borrar en ella. Encima habia una
     * politica que decia otra cosa: el consultor nunca pudo editar nada. El
     * resultado fue darle al consultor —y a cualquier rol configurado— permisos
     * que el sistema anterior le negaba.
     *
     * Se corrige solo donde nadie ha tocado nada desde entonces: si un rol
     * quedo tal cual lo dejo aquel reparto, se rehace bien; si alguien ya lo
     * ajusto a mano, se respeta, porque una decision tomada mirando la pantalla
     * vale mas que esta correccion a ciegas.
     */
    private function repararElRepartoQueAbrioDeMas(): void
    {
        if (Setting::get(self::YA_REPARADO)) {
            return;
        }

        foreach ($this->rolesEditables() as $nombre) {
            $rol = Role::findByName($nombre, 'web');
            $tiene = $rol->permissions->pluck('name')->all();

            if (! $this->esLaHuellaDelRepartoViejo($tiene)) {
                continue;
            }

            $ver = array_values(array_filter($tiene, fn (string $p) => str_starts_with($p, 'ver.')));
            $fiel = array_merge($ver, $this->loQuePodiaHacer($nombre, $ver));

            $this->asegurarQueExisten($fiel);
            $rol->syncPermissions($fiel);
        }

        Setting::put(self::YA_REPARADO, true, 'accesos');
    }

    /**
     * Si un rol tiene exactamente todas las acciones de todo lo que ve.
     *
     * Es lo que dejaba el reparto viejo, y practicamente nadie configura eso a
     * mano: sirve para distinguir «esto lo hizo el programa» de «esto lo
     * decidio una persona».
     *
     * @param  list<string>  $tiene
     */
    private function esLaHuellaDelRepartoViejo(array $tiene): bool
    {
        $esperado = [];

        foreach ($tiene as $permiso) {
            if (! str_starts_with($permiso, 'ver.')) {
                continue;
            }

            $clave = substr($permiso, strlen('ver.'));
            $seccion = Secciones::todas()[$clave] ?? null;

            if (! $seccion) {
                return false;
            }

            foreach (array_keys(Secciones::accionesDe($seccion['clase'])) as $accion) {
                $esperado[] = $accion . '.' . $clave;
            }
        }

        sort($esperado);
        $actual = $tiene;
        sort($actual);

        return $esperado === $actual && $esperado !== [];
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
