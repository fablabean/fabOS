<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Permisos del backoffice por rol (§5).
 *
 * Hasta ahora el rol solo decidía QUIÉN ENTRA; una vez dentro, un consultor
 * podía editar igual que un administrador. Esto lo cierra:
 *
 *   consultor      ve
 *   administrador  crea y edita
 *   superadmin     además configura, borra y toca personas y accesos
 *
 * Filament consulta estas políticas solo: si `create` devuelve false, el botón
 * de crear ni siquiera se dibuja.
 */
class BackofficePolicy
{
    /**
     * Modelos que solo el superadmin puede tocar: quién entra al sistema y con
     * qué reglas. Un administrador no debería poder darse permisos a sí mismo.
     */
    private const RESERVADOS = [
        User::class,
        Setting::class,
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(User::ROLES_BACKOFFICE);
    }

    public function view(User $user, Model $registro): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]);
    }

    public function update(User $user, Model $registro): bool
    {
        if ($this->esReservado($registro)) {
            return $user->hasRole(User::ROL_SUPERADMIN);
        }

        return $this->create($user);
    }

    /**
     * Borrar es distinto de editar: se pierde el historial. Queda en manos del
     * superadmin, que es quien responde por la integridad del catálogo.
     */
    public function delete(User $user, Model $registro): bool
    {
        return $user->hasRole(User::ROL_SUPERADMIN);
    }

    public function restore(User $user, Model $registro): bool
    {
        return $this->delete($user, $registro);
    }

    public function forceDelete(User $user, Model $registro): bool
    {
        return $user->hasRole(User::ROL_SUPERADMIN);
    }

    private function esReservado(Model $registro): bool
    {
        return in_array($registro::class, self::RESERVADOS, true);
    }
}
