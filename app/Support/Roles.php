<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Los roles que existen, leídos de la base de datos (§5).
 *
 * Los cinco fijos —los de `User::ROLES`— están siempre y no se borran; los
 * demás los crea el laboratorio desde *Roles y accesos*. Todo lo que antes
 * preguntaba a la constante pregunta aquí, y así un rol nuevo aparece en la
 * matriz, en el selector de personas y en la puerta del panel sin tocar
 * código.
 *
 * Se lee una vez por petición. Si la tabla todavía no existe —instalando, en
 * la primera migración— se responde con las constantes, que es lo que había.
 */
final class Roles
{
    private static ?array $cache = null;

    /** @return array<string,string> slug => etiqueta, los fijos primero */
    public static function todos(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $fijos = User::ROLES;

        try {
            if (! Schema::hasColumn('roles', 'label')) {
                return self::$cache = $fijos;
            }

            $enBase = Role::query()
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->get(['name', 'label'])
                ->mapWithKeys(fn (Role $r) => [$r->name => $r->label ?: ($fijos[$r->name] ?? ucfirst($r->name))])
                ->all();
        } catch (\Throwable) {
            return self::$cache = $fijos;
        }

        // Los fijos en su orden de siempre, y los demás detrás, por nombre.
        return self::$cache = $fijos + $enBase;
    }

    /** Los que son del equipo: acompañan, reciben traspasos, ven el inventario. */
    public static function delEquipo(): array
    {
        $fijos = User::ROLES_BACKOFFICE;

        try {
            if (! Schema::hasColumn('roles', 'del_equipo')) {
                return $fijos;
            }

            $enBase = Role::query()
                ->where('guard_name', 'web')
                ->where('del_equipo', true)
                ->pluck('name')
                ->all();
        } catch (\Throwable) {
            return $fijos;
        }

        return array_values(array_unique([...$fijos, ...$enBase]));
    }

    public static function etiqueta(string $rol): string
    {
        return self::todos()[$rol] ?? ucfirst($rol);
    }

    public static function existe(string $rol): bool
    {
        return array_key_exists($rol, self::todos());
    }

    /** Los que vienen con el código y no se borran. */
    public static function esFijo(string $rol): bool
    {
        return array_key_exists($rol, User::ROLES);
    }

    public static function olvidar(): void
    {
        self::$cache = null;
    }
}
