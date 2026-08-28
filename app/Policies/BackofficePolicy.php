<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Secciones;
use Illuminate\Database\Eloquent\Model;

/**
 * Permisos del backoffice (§5).
 *
 * Esta politica ya no decide nada por su cuenta: **pregunta a la matriz**, la
 * misma que consultan los recursos. Antes tenia sus propias reglas escritas
 * —consultor ve, administrador edita, superadmin borra— y eso convivia mal con
 * la matriz configurable: el boton de editar de una fila preguntaba aqui y la
 * pantalla de edicion preguntaba alli, y contestaban cosas distintas.
 *
 * El sintoma era el peor posible: la lista escondia el boton de editar y la
 * direccion de edicion se abria igual y guardaba. Quien lo descubriera por
 * casualidad tendria mas permisos de los que el sistema decia haberle dado.
 *
 * Dos autoridades para la misma pregunta no son el doble de seguridad: son una
 * contradiccion esperando a que alguien la encuentre.
 */
class BackofficePolicy
{
    /**
     * `viewAny` y `create` no reciben el registro, asi que aqui no se sabe de
     * que seccion se habla. No pasa nada: esas dos las decide el recurso
     * —`canAccess` y `canCreate`—, que si lo sabe. Este metodo solo evita que
     * alguien de fuera del backoffice pase por aqui.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(User::ROLES_BACKOFFICE);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Model $registro): bool
    {
        return $this->puede($user, 'ver', $registro);
    }

    public function update(User $user, Model $registro): bool
    {
        return $this->puede($user, 'editar', $registro);
    }

    public function delete(User $user, Model $registro): bool
    {
        return $this->puede($user, 'borrar', $registro);
    }

    /** Restaurar lo borrado es una edicion del pasado, no un borrado. */
    public function restore(User $user, Model $registro): bool
    {
        return $this->puede($user, 'editar', $registro);
    }

    public function forceDelete(User $user, Model $registro): bool
    {
        return $this->puede($user, 'borrar', $registro);
    }

    /**
     * Un modelo sin seccion no lo administra nadie desde el panel: si aparece
     * uno, se queda en manos del superadmin en vez de abrirse por descarte.
     */
    private function puede(User $user, string $accion, Model $registro): bool
    {
        $clave = Secciones::claveDelModelo($registro::class);

        return $clave === null
            ? $user->hasRole(User::ROL_SUPERADMIN)
            : $user->puedeEnLaSeccion($accion, $clave);
    }
}
