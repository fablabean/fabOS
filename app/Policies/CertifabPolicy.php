<?php

namespace App\Policies;

use App\Models\Certifab;
use App\Models\User;

/**
 * Quién puede certificar (§5, §10).
 *
 * Certificar no es un trámite administrativo: quien firma que alguien puede
 * operar una sierra de banco responde por esa decisión. Por eso no basta con
 * ser administrador —hay que ser responsable del área o superadmin—.
 *
 * Reemplaza a BackofficePolicy para este modelo: ver sigue siendo abierto a
 * todo el backoffice, pero otorgar y revocar quedan acotados.
 */
class CertifabPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(User::ROLES_BACKOFFICE);
    }

    public function view(User $user, Certifab $certifab): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Puede otorgar quien responde por alguna área. El alcance concreto se
     * comprueba al guardar, contra el área del equipo o la familia elegida.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(User::ROL_SUPERADMIN)
            || $user->responsibleAreas()->exists();
    }

    public function update(User $user, Certifab $certifab): bool
    {
        return $this->puedeSobre($user, $certifab);
    }

    public function delete(User $user, Certifab $certifab): bool
    {
        // Borrar un certifab borra la evidencia de que existió. Solo superadmin;
        // los responsables revocan, que deja rastro.
        return $user->hasRole(User::ROL_SUPERADMIN);
    }

    public function restore(User $user, Certifab $certifab): bool
    {
        return $this->delete($user, $certifab);
    }

    public function forceDelete(User $user, Certifab $certifab): bool
    {
        return $user->hasRole(User::ROL_SUPERADMIN);
    }

    /** Revocar: mismo alcance que otorgar. Quien habilita, deshabilita. */
    public function revoke(User $user, Certifab $certifab): bool
    {
        return $this->puedeSobre($user, $certifab);
    }

    private function puedeSobre(User $user, Certifab $certifab): bool
    {
        if ($user->hasRole(User::ROL_SUPERADMIN)) {
            return true;
        }

        return $user->esResponsableDe($this->areaDe($certifab));
    }

    /** El área a la que pertenece el alcance del certifab. */
    private function areaDe(Certifab $certifab): ?int
    {
        return $certifab->asset?->area_id
            ?? $certifab->riskFamily?->area_id;
    }
}
