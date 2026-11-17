<?php

namespace App\Policies;

use App\Models\Modulo;
use App\Models\User;

/**
 * Un módulo no tiene sección propia: es una pieza de su zona. Quien puede
 * editar zonas en la matriz edita sus módulos; sin esto, la pregunta «¿de qué
 * sección es un módulo?» caía del lado del superadmin y nadie más podía subir
 * una presentación.
 */
class ModuloPolicy
{
    private const SECCION = 'zona';

    public function viewAny(User $user): bool
    {
        return $user->puedeEnLaSeccion('ver', self::SECCION);
    }

    public function view(User $user, Modulo $modulo): bool
    {
        return $user->puedeEnLaSeccion('ver', self::SECCION);
    }

    public function create(User $user): bool
    {
        return $user->puedeEnLaSeccion('editar', self::SECCION);
    }

    public function update(User $user, Modulo $modulo): bool
    {
        return $user->puedeEnLaSeccion('editar', self::SECCION);
    }

    public function reorder(User $user): bool
    {
        return $user->puedeEnLaSeccion('editar', self::SECCION);
    }

    public function delete(User $user, Modulo $modulo): bool
    {
        return $user->puedeEnLaSeccion('editar', self::SECCION);
    }

    public function deleteAny(User $user): bool
    {
        return $user->puedeEnLaSeccion('editar', self::SECCION);
    }
}
