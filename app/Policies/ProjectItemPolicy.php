<?php

namespace App\Policies;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Support\Secciones;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que cuelga de un proyecto: quién lo toca (§5, §11).
 *
 * Un proyecto lo trabaja gente que no tiene la sección de Proyectos abierta: el
 * practicante que registra sus horas, quien sube una foto del avance, quien
 * anota un costo. Hasta ahora eso quedaba en uno de dos extremos, ninguno
 * bueno: o se le abría la sección entera —y veía todos los proyectos del
 * laboratorio, con sus clientes y sus valores—, o dentro del proyecto no podía
 * hacer nada y lo suyo lo tenía que registrar otro.
 *
 * La regla, para quien entra por su equipo:
 *
 *  · **Lo que creó** lo ve, lo edita y lo borra.
 *  · **Lo que tiene asignado** lo ve, aunque lo haya escrito otro. Una tarea
 *    que no puedes ver no te la pueden asignar.
 *  · **Lo demás del proyecto**, no. Los costos de un proyecto dicen a cuánto se
 *    vendió y con qué margen, y eso no es de todo el que pase por el equipo.
 *
 * Y por encima, sin quitar nada:
 *
 *  · Lo que dé la **matriz de accesos** para la sección de Proyectos. Se
 *    pregunta por la sección del PROYECTO y no por la de cada pieza, porque las
 *    piezas no tienen sección propia: preguntando por la suya, la respuesta era
 *    que solo el superadmin podía tocar una tarea, y el administrador se
 *    quedaba sin poder editarlas.
 *  · El **responsable** del proyecto maneja lo suyo entero. Responde por él, y
 *    un responsable que no puede corregir un costo mal tecleado en su propio
 *    proyecto acaba pidiéndoselo a alguien por chat.
 */
class ProjectItemPolicy extends BackofficePolicy
{
    public function view(User $user, Model $registro): bool
    {
        return $this->segunLaSeccion($user, 'ver')
            || $this->esDeSuProyecto($user, $registro)
            && ($registro->loCreo($user) || $registro->leToca($user) || $this->loLidera($user, $registro));
    }

    public function update(User $user, Model $registro): bool
    {
        return $this->segunLaSeccion($user, 'editar')
            || $this->esDeSuProyecto($user, $registro)
            && ($registro->loCreo($user) || $this->loLidera($user, $registro));
    }

    public function delete(User $user, Model $registro): bool
    {
        return $this->segunLaSeccion($user, 'borrar')
            || $this->esDeSuProyecto($user, $registro)
            && ($registro->loCreo($user) || $this->loLidera($user, $registro));
    }

    public function restore(User $user, Model $registro): bool
    {
        return $this->update($user, $registro);
    }

    public function forceDelete(User $user, Model $registro): bool
    {
        return $this->delete($user, $registro);
    }

    /**
     * Lo que la matriz diga para la SECCIÓN DE PROYECTOS.
     *
     * La clave se saca del recurso y no se escribe a mano: si se renombra, la
     * clave deja de encontrarse y esto queda cerrado para todos menos el
     * superadmin, que es el fallo seguro.
     */
    private function segunLaSeccion(User $user, string $accion): bool
    {
        return $user->puedeEnLaSeccion($accion, Secciones::claveDe(ProjectResource::class));
    }

    private function proyectoDe(Model $registro): ?Project
    {
        return method_exists($registro, 'proyecto') ? $registro->proyecto() : null;
    }

    private function esDeSuProyecto(User $user, Model $registro): bool
    {
        return $this->proyectoDe($registro)?->estaEnElEquipo($user) ?? false;
    }

    private function loLidera(User $user, Model $registro): bool
    {
        return $this->proyectoDe($registro)?->loLidera($user) ?? false;
    }
}
