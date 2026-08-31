<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Un proyecto lo ve su equipo, aunque el rol no abra la seccion (§11).
 *
 * Hasta ahora, para que alguien viera el proyecto en el que trabaja habia que
 * abrirle la seccion entera: veia los suyos y los de todos, con sus clientes,
 * sus valores y sus propuestas. La alternativa era no dejarle ver ninguno y
 * contarle por WhatsApp en que va lo suyo.
 *
 * Aqui se añade lo que falta, sin quitar nada:
 *
 *  · **Del equipo** → lo ve. Solo el suyo.
 *  · **Responsable** → ademas lo maneja. Responde por el; pedirle a un
 *    administrador que mueva de etapa un proyecto propio es justo lo que hace
 *    que las etapas dejen de estar al dia.
 *
 * Lo que da el rol se conserva: esto solo suma. Y borrar no entra: un proyecto
 * se descarta, y borrarlo de verdad sigue siendo del superadmin.
 */
class ProjectPolicy extends BackofficePolicy
{
    public function view(User $user, Model $registro): bool
    {
        return parent::view($user, $registro)
            || ($registro instanceof Project && $registro->estaEnElEquipo($user));
    }

    public function update(User $user, Model $registro): bool
    {
        return parent::update($user, $registro)
            || ($registro instanceof Project && $registro->loLidera($user));
    }
}
