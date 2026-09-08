<?php

namespace App\Filament\Componentes;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Las opciones de un selector de personas, con el cargo al lado del nombre.
 *
 * Un desplegable con cuarenta nombres a secas obliga a saber de memoria
 * quien es practicante y quien consultor. Con el cargo en la etiqueta se ve
 * de un vistazo, y como el selector busca dentro de la etiqueta, escribir
 * «practicante» deja solo a los practicantes: el filtro por rol sale gratis.
 *
 * Dos listas, segun a quien se busca:
 *  · el **equipo**: quien tiene un rol en el panel;
 *  · **cualquiera**: toda persona activa, con su rol si lo tiene y si no su
 *    categoria —estudiante, profesor, externo—.
 */
class SelectorDePersona
{
    /** @return array<int,string> */
    public static function equipo(): array
    {
        return self::etiquetas(
            User::query()
                ->whereHas('roles')
                ->where('status', 'activo')
                ->with(['roles', 'category'])
                ->orderBy('name')
                ->get(),
        );
    }

    /** @return array<int,string> */
    public static function personas(): array
    {
        return self::etiquetas(
            User::query()
                ->where('status', 'activo')
                ->with(['roles', 'category'])
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * @param  Collection<int,User>  $personas
     * @return array<int,string>
     */
    private static function etiquetas(Collection $personas): array
    {
        return $personas->mapWithKeys(fn (User $u) => [$u->id => $u->etiquetaConCargo()])->all();
    }
}
