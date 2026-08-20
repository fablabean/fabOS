<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El embudo de proyectos (§11).
 *
 *   idea → propuesta → contrato → brief → ejecución → cierre
 *
 * Cada paso tiene una **compuerta**: algo que debe existir antes de avanzar.
 * No es burocracia, es lo que evita el patrón que mata proyectos en los
 * laboratorios —empezar a fabricar sobre un acuerdo verbal y descubrir a mitad
 * de camino que cada quien entendió una cosa distinta—. La compuerta convierte
 * ese descubrimiento en una conversación de la semana uno.
 *
 * Y una regla que no es documental: **sin responsable no se avanza**. El
 * laboratorio responde como institución, pero siempre recae en una persona.
 */
class ProjectService
{
    /**
     * Qué hace falta para entrar a cada etapa.
     *
     * @var array<string,array{documento:?string,explicacion:string}>
     */
    private const COMPUERTAS = [
        'propuesta' => [
            'documento'   => null,
            'explicacion' => 'Hace falta asignar el responsable antes de hacer una propuesta.',
        ],
        'contrato' => [
            'documento'   => 'propuesta',
            'explicacion' => 'No se firma un contrato sin una propuesta escrita: es lo que se está aceptando.',
        ],
        'brief' => [
            'documento'   => 'contrato',
            'explicacion' => 'Sin contrato u orden de servicio no debería empezar el detalle del trabajo.',
        ],
        'ejecucion' => [
            'documento'   => 'brief',
            'explicacion' => 'El brief es lo que fija qué se entrega. Fabricar sin él es fabricar a ciegas.',
        ],
        'cierre' => [
            'documento'   => 'informe',
            'explicacion' => 'Cerrar sin informe deja el proyecto sin memoria: dentro de un año nadie sabrá qué se entregó.',
        ],
    ];

    /** Anota una idea. Es el paso más importante y el que más se pierde. */
    public function registrarIdea(array $datos, ?User $quienRegistra = null): Project
    {
        return Project::create(array_merge([
            'code'   => $this->siguienteCodigo(),
            'stage'  => 'idea',
            'status' => 'activo',
            'source' => 'correo',
        ], $datos, [
            'requested_by' => $datos['requested_by'] ?? $quienRegistra?->id,
        ]));
    }

    /**
     * Avanza a la siguiente etapa, si la compuerta lo permite.
     *
     * @throws ProjectException con el motivo concreto de lo que falta
     */
    public function avanzar(Project $proyecto): Project
    {
        $siguiente = $this->siguienteEtapa($proyecto);

        if (! $siguiente) {
            throw new ProjectException('Este proyecto ya está en la última etapa.');
        }

        return $this->moverA($proyecto, $siguiente);
    }

    /**
     * Mueve el proyecto a una etapa concreta, comprobando todas las compuertas
     * intermedias. Saltarse una etapa es saltarse su documento.
     *
     * @throws ProjectException
     */
    public function moverA(Project $proyecto, string $etapa): Project
    {
        if (! isset(Project::ETAPAS[$etapa])) {
            throw new ProjectException('Esa etapa no existe.');
        }

        $orden = array_keys(Project::ETAPAS);
        $desde = array_search($proyecto->stage, $orden, true);
        $hasta = array_search($etapa, $orden, true);

        if ($hasta <= $desde) {
            // Retroceder es legítimo: una propuesta puede volver a revisarse.
            // Lo que no se permite es avanzar sin lo que sostiene la etapa.
            $proyecto->update(['stage' => $etapa]);

            return $proyecto->refresh();
        }

        foreach (array_slice($orden, $desde + 1, $hasta - $desde) as $paso) {
            $this->exigirCompuerta($proyecto, $paso);
        }

        $datos = ['stage' => $etapa];

        if ($etapa === 'ejecucion' && ! $proyecto->starts_on) {
            $datos['starts_on'] = now(config('fabos.lab.timezone'))->toDateString();
        }

        if ($etapa === 'cierre') {
            $datos['closed_at'] = now();
            $datos['status'] = 'cerrado';
        }

        $proyecto->update($datos);

        return $proyecto->refresh();
    }

    /** Qué falta para avanzar. Devuelve null si se puede. */
    public function queFalta(Project $proyecto): ?string
    {
        $siguiente = $this->siguienteEtapa($proyecto);

        if (! $siguiente) {
            return null;
        }

        try {
            $this->exigirCompuerta($proyecto, $siguiente);
        } catch (ProjectException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function siguienteEtapa(Project $proyecto): ?string
    {
        $orden = array_keys(Project::ETAPAS);
        $actual = array_search($proyecto->stage, $orden, true);

        return $orden[$actual + 1] ?? null;
    }

    /** Descarta o marca perdido, sin borrar: el histórico enseña. */
    public function descartar(Project $proyecto, string $motivo, string $estado = 'descartado'): Project
    {
        $proyecto->update([
            'status'        => $estado,
            'closed_at'     => now(),
            'closing_notes' => $motivo,
        ]);

        return $proyecto->refresh();
    }

    public function reabrir(Project $proyecto): Project
    {
        $proyecto->update(['status' => 'activo', 'closed_at' => null]);

        return $proyecto->refresh();
    }

    /** Añade a alguien al equipo, con cuenta o sin ella. */
    public function agregarMiembro(Project $proyecto, array $datos): ProjectMember
    {
        $miembro = $proyecto->members()->create($datos);

        // El responsable del proyecto es uno solo: asignarlo por aquí también
        // lo deja en la ficha, para que las dos vistas no se contradigan.
        if (($datos['role'] ?? null) === 'responsable' && ! empty($datos['user_id'])) {
            $proyecto->update(['lead_id' => $datos['user_id']]);
        }

        return $miembro;
    }

    /** Mueve una tarea de columna en el tablero. */
    public function moverTarea(ProjectTask $tarea, string $estado): ProjectTask
    {
        if (! isset(ProjectTask::ESTADOS[$estado])) {
            throw new ProjectException('Esa columna no existe.');
        }

        $tarea->update([
            'status'       => $estado,
            'progress'     => $estado === 'hecha' ? 100 : $tarea->progress,
            'completed_at' => $estado === 'hecha' ? now() : null,
        ]);

        return $tarea->refresh();
    }

    /**
     * Las tareas agrupadas por columna, para el tablero.
     *
     * @return array<string,\Illuminate\Support\Collection<int,ProjectTask>>
     */
    public function tablero(Project $proyecto): array
    {
        $tareas = $proyecto->tasks()->with('assignedTo')->get();

        $columnas = [];

        foreach (array_keys(ProjectTask::ESTADOS) as $estado) {
            $columnas[$estado] = $tareas->where('status', $estado)->values();
        }

        return $columnas;
    }

    /**
     * Las tareas con fechas, para el Gantt, y el rango que abarcan.
     *
     * @return array{tareas:\Illuminate\Support\Collection,desde:?\Illuminate\Support\Carbon,hasta:?\Illuminate\Support\Carbon}
     */
    public function cronograma(Project $proyecto): array
    {
        $tareas = $proyecto->tasks()
            ->with('assignedTo')
            ->whereNotNull('starts_on')
            ->orderBy('starts_on')
            ->get();

        return [
            'tareas' => $tareas,
            'desde'  => $tareas->min('starts_on'),
            'hasta'  => $tareas->max(fn (ProjectTask $t) => $t->due_on ?? $t->starts_on),
        ];
    }

    /** PRY-2026-0001: legible por teléfono y ordenable. */
    public function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = Project::where('code', 'like', "PRY-{$ano}-%")->max('code');

        return sprintf('PRY-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
    }

    /** @throws ProjectException */
    private function exigirCompuerta(Project $proyecto, string $etapa): void
    {
        $compuerta = self::COMPUERTAS[$etapa] ?? null;

        if (! $compuerta) {
            return;
        }

        // Sin responsable no se avanza de idea, y tampoco después.
        if (! $proyecto->lead_id) {
            throw new ProjectException(self::COMPUERTAS['propuesta']['explicacion']);
        }

        if ($compuerta['documento'] && ! $proyecto->tieneDocumento($compuerta['documento'])) {
            throw new ProjectException(sprintf(
                'Falta el documento «%s» para pasar a %s. %s',
                \App\Models\ProjectDocument::TIPOS[$compuerta['documento']] ?? $compuerta['documento'],
                mb_strtolower(Project::ETAPAS[$etapa]),
                $compuerta['explicacion'],
            ));
        }
    }
}
