<?php

namespace App\Policies;

use App\Models\Credencial;
use App\Models\User;

/**
 * Quién puede tocar una credencial (§5, §19).
 *
 * Se aparta de la política del backoffice a propósito. Allí, quien tiene el
 * permiso de una sección lo tiene sobre **todas** sus filas, y eso es lo
 * correcto para insumos o reservas. Aquí no: una sección donde todo el que
 * administra ve todas las claves del laboratorio es un tablón de contraseñas
 * con una puerta, y basta con que una de esas cuentas se pierda para perderlo
 * todo a la vez.
 *
 * La regla es la de la casa:
 *
 *  · **Crear**, quien administre la sección. Anotar una cuenta nueva no es un
 *    privilegio: lo que hay que cuidar es leerla, no escribirla.
 *  · **Ver, editar y borrar**, solo su dueño — y el superadmin, que las ve
 *    todas porque alguien tiene que poder recuperar el acceso cuando la
 *    persona que la guardó ya no está. Esa es justo la situación que hace que
 *    esta sección exista.
 *
 * El permiso de la matriz sigue contando: sin `ver.credencial` no se entra a
 * la sección, aunque seas dueño de algo. Ser dueño no abre la puerta, decide
 * qué hay dentro.
 */
class CredencialPolicy extends BackofficePolicy
{
    public function view(User $user, $registro): bool
    {
        return $registro instanceof Credencial
            && $registro->laPuedeVer($user)
            && parent::view($user, $registro);
    }

    public function update(User $user, $registro): bool
    {
        return $registro instanceof Credencial
            && $registro->laPuedeVer($user)
            && parent::update($user, $registro);
    }

    public function delete(User $user, $registro): bool
    {
        return $registro instanceof Credencial
            && $registro->laPuedeVer($user)
            && parent::delete($user, $registro);
    }

    /**
     * Revelar el secreto es su propio permiso.
     *
     * Hoy coincide con poder verla, y aun así se pregunta aparte: mirar la
     * ficha —a qué servicio es, quién la tiene, cuándo se miró por última
     * vez— y sacar la contraseña a la pantalla son dos gestos distintos, y
     * tenerlos separados permite estrechar el segundo sin cerrar el primero.
     */
    public function revelar(User $user, Credencial $registro): bool
    {
        return $this->view($user, $registro);
    }
}
