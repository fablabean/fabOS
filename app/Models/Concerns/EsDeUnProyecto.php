<?php

namespace App\Models\Concerns;

use App\Models\Project;
use App\Models\User;

/**
 * Lo que cuelga de un proyecto: comentarios, documentos, costos, horas, tareas.
 *
 * Todo eso comparte tres preguntas, y de las tres depende quién puede tocarlo
 * cuando alguien entra al proyecto sin tener la sección de Proyectos abierta:
 *
 *   · ¿De qué proyecto es?
 *   · ¿Lo creó esta persona?
 *   · ¿Le toca a esta persona? —solo las tareas contestan que sí; una tarea
 *     asignada se ve aunque la haya escrito otro, o el encargo no se enteraría
 *     de que existe—.
 *
 * Cada modelo dice en `COLUMNA_AUTOR` dónde guarda a su autor, porque cada uno
 * lo llamó a su manera: `user_id`, `uploaded_by`, `registered_by`. Unificar las
 * columnas habría sido una migración de datos en cinco tablas para ahorrar una
 * constante.
 */
trait EsDeUnProyecto
{
    /** Dónde guarda este modelo a quien lo creó. */
    public function columnaDeAutor(): string
    {
        return defined(static::class . '::COLUMNA_AUTOR')
            ? static::COLUMNA_AUTOR
            : 'user_id';
    }

    public function proyecto(): ?Project
    {
        return $this->project_id ? Project::find($this->project_id) : null;
    }

    public function loCreo(?User $quien): bool
    {
        if ($quien === null) {
            return false;
        }

        $autor = $this->{$this->columnaDeAutor()};

        // Sin autor anotado no es de nadie. Es el caso de lo que ya existía
        // antes de que se guardara quién lo creó, y el fallo seguro es que solo
        // lo maneje quien tiene la sección: dar por suyo lo que no se sabe de
        // quién es abriria de par en par justo lo que esto viene a cerrar.
        return $autor !== null && (int) $autor === $quien->id;
    }

    /**
     * Si esto le está asignado. Solo las tareas tienen a quién asignarse; los
     * demás lo heredan en `false` y no tienen que decir nada.
     */
    public function leToca(?User $quien): bool
    {
        return false;
    }
}
