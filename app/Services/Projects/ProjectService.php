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
     * La evidencia propia de cada etapa.
     *
     * Cada etapa deja algo escrito, y ese algo se sostiene solo: la idea en dos
     * frases, la propuesta que se mandó, el contrato firmado, el brief que fija
     * el alcance, el trabajo hecho, el informe de cierre. Se pueden ir llenando
     * en el orden que la realidad imponga —a veces el soporte del contrato
     * llega días después de la firma—; lo que no se puede es **avanzar** sin
     * ellas, y de eso se encargan las compuertas de abajo.
     *
     * Esta tabla es la única fuente: el documento que exige cada compuerta se
     * deriva de ella, así que cambiar aquí qué sostiene una etapa cambia
     * también qué se pide para pasarla. Antes eran dos listas, y dos listas
     * acaban diciendo cosas distintas.
     *
     * @var array<string,array{documento:?string,campo:?string,que:string,porque:string,como:string}>
     */
    public const EVIDENCIAS = [
        'idea' => [
            'documento' => null,
            'campo'     => 'summary',
            'que'       => 'La idea en dos frases',
            'porque'    => 'Lo primero es que quede anotada. Una idea que solo existe en una conversación se pierde.',
            'como'      => 'Se escribe en la ficha del proyecto.',
        ],
        'propuesta' => [
            'documento' => 'propuesta',
            'campo'     => null,
            'propio'    => 'entregables',
            'que'       => 'A qué nos comprometemos, entregable por entregable, y la propuesta que se mandó',
            'porque'    => 'Es lo que la otra parte va a aceptar o rechazar. En lista y no en párrafo, porque al cerrar hay que poder decir cuál se cumplió y cuál no.',
            'como'      => 'Los entregables van en la ficha; la propuesta se sube en Documentos.',
        ],
        'contrato' => [
            'documento' => 'contrato',
            'campo'     => null,
            'que'       => 'El respaldo: contrato u orden de servicio',
            'porque'    => 'Es lo que convierte un acuerdo verbal en algo exigible por las dos partes.',
            'como'      => 'Se sube en Documentos, con su fecha de firma.',
        ],
        'brief' => [
            'documento' => 'brief',
            'campo'     => null,
            'que'       => 'El brief: el contrato traducido a trabajo',
            'porque'    => 'Es el insumo de la ejecución. Fija qué se entrega, y es lo que se mira cuando alguien pide «un cambio pequeño».',
            'como'      => 'Se sube en Documentos.',
        ],
        'ejecucion' => [
            'documento' => null,
            'campo'     => null,
            'propio'    => 'tareas',
            'que'       => 'El trabajo, repartido en tareas',
            'porque'    => 'Las tareas son las que dan el avance y el cronograma. Sin ellas el proyecto avanza a ojo.',
            'como'      => 'Se crean en Tareas y se mueven en el tablero.',
        ],
        'cierre' => [
            'documento' => 'informe',
            'campo'     => 'closing_notes',
            'que'       => 'El informe de cierre y qué quedó aprendido',
            'porque'    => 'Cerrar sin informe deja el proyecto sin memoria: dentro de un año nadie sabrá qué se entregó.',
            'como'      => 'El informe se sube en Documentos; las notas van en la ficha.',
        ],
    ];

    /**
     * Qué hace falta para entrar a cada etapa. El documento que se exige sale
     * de EVIDENCIAS; aquí solo vive el porqué, que es lo que se le dice a quien
     * se topa con la compuerta.
     *
     * @var array<string,array{explicacion:string}>
     */
    private const COMPUERTAS = [
        'propuesta' => [
            'explicacion' => 'Hace falta asignar el responsable antes de hacer una propuesta.',
        ],
        'contrato' => [
            'explicacion' => 'No se firma un contrato sin una propuesta escrita: es lo que se está aceptando.',
        ],
        'brief' => [
            'explicacion' => 'Sin contrato u orden de servicio no debería empezar el detalle del trabajo.',
        ],
        'ejecucion' => [
            'explicacion' => 'El brief es lo que fija qué se entrega. Fabricar sin él es fabricar a ciegas.',
        ],
        'cierre' => [
            'explicacion' => 'Cerrar sin informe deja el proyecto sin memoria: dentro de un año nadie sabrá qué se entregó.',
        ],
    ];

    /**
     * El documento que exige entrar a una etapa: el de la evidencia de la
     * etapa anterior. El cierre es la excepción —pide el informe, que es
     * evidencia del cierre mismo y no de lo que vino antes—.
     */
    private function documentoQueExige(string $etapa): ?string
    {
        if ($etapa === 'cierre') {
            return self::EVIDENCIAS['cierre']['documento'];
        }

        $orden = array_keys(Project::ETAPAS);
        $anterior = $orden[array_search($etapa, $orden, true) - 1] ?? null;

        return $anterior ? self::EVIDENCIAS[$anterior]['documento'] : null;
    }

    /**
     * El estado de la evidencia de cada etapa, para poder verlas juntas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function evidencias(Project $proyecto): array
    {
        $orden = array_keys(Project::ETAPAS);
        $actual = array_search($proyecto->stage, $orden, true);
        $filas = [];

        foreach (self::EVIDENCIAS as $etapa => $e) {
            $piezas = [];

            if ($e['documento']) {
                $piezas[] = $proyecto->tieneDocumento($e['documento']);
            }

            if ($e['campo']) {
                $piezas[] = filled($proyecto->{$e['campo']});
            }

            // Hay evidencia que no es ni documento ni campo de la ficha: la
            // propuesta se sostiene en sus entregables y la ejecución en sus
            // tareas.
            $piezas[] = match ($e['propio'] ?? null) {
                'entregables' => $proyecto->deliverables()->exists(),
                'tareas'      => $proyecto->tasks()->exists(),
                default       => null,
            };

            $piezas = array_filter($piezas, fn ($v) => $v !== null);

            $filas[] = [
                'etapa'     => $etapa,
                'nombre'    => Project::ETAPAS[$etapa],
                'que'       => $e['que'],
                'porque'    => $e['porque'],
                'como'      => $e['como'],
                'documento' => $e['documento'],
                'listo'     => $piezas !== [] && ! in_array(false, $piezas, true),
                'detalle'   => $this->detalleDeEvidencia($proyecto, $etapa, $e),
                'actual'    => $etapa === $proyecto->stage,
                'pasada'    => array_search($etapa, $orden, true) < $actual,
            ];
        }

        return $filas;
    }

    private function detalleDeEvidencia(Project $proyecto, string $etapa, array $e): ?string
    {
        if ($etapa === 'ejecucion') {
            $n = $proyecto->tasks()->count();

            return $n ? sprintf('%d tarea%s · %d%% de avance', $n, $n === 1 ? '' : 's', $proyecto->avance()) : null;
        }

        $partes = [];

        if (($e['propio'] ?? null) === 'entregables') {
            $entregables = $proyecto->deliverables;

            if ($entregables->isNotEmpty()) {
                $cumplidos = $entregables->filter->estaEntregado()->count();
                $partes[] = sprintf(
                    '%d entregable%s, %d cumplido%s: %s',
                    $entregables->count(),
                    $entregables->count() === 1 ? '' : 's',
                    $cumplidos,
                    $cumplidos === 1 ? '' : 's',
                    $entregables->pluck('title')->implode(' · '),
                );
            }
        }

        if ($e['documento']) {
            $doc = $proyecto->documents->firstWhere('kind', $e['documento']);

            if ($doc) {
                $partes[] = $doc->title
                    . ($doc->signed_on ? ' · firmado el ' . $doc->signed_on->format('d/m/Y') : '');
            }
        }

        if ($e['campo'] && filled($proyecto->{$e['campo']})) {
            $partes[] = str($proyecto->{$e['campo']})->limit(120)->value();
        }

        return $partes ? implode(' — ', $partes) : null;
    }

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

    /**
     * Lleva al tablero los entregables que todavía no son tarea.
     *
     * Se crean como **hitos**: un entregable es un compromiso con fecha, no una
     * actividad, y en el Gantt tiene que leerse como una marca y no como una
     * barra larga. Los que ya tienen tarea se saltan —correr esto dos veces no
     * duplica el tablero, que es justo lo que uno teme al pulsar un botón así—.
     *
     * @return int cuántas tareas se crearon
     */
    public function llevarEntregablesAlTablero(Project $proyecto): int
    {
        $pendientes = $proyecto->deliverables()->whereNull('task_id')->get();

        if ($pendientes->isEmpty()) {
            return 0;
        }

        $posicion = (int) $proyecto->tasks()->max('position');

        return DB::transaction(function () use ($proyecto, $pendientes, $posicion) {
            foreach ($pendientes as $entregable) {
                $tarea = $proyecto->tasks()->create([
                    'title'        => $entregable->title,
                    'description'  => $entregable->detail,
                    'status'       => 'por_hacer',
                    'is_milestone' => true,
                    // Si el entregable no trae fecha, hereda la del proyecto:
                    // un hito sin fecha no aparece en el cronograma, y ese es
                    // el sitio donde se mira si da tiempo.
                    'due_on'       => $entregable->due_on ?? $proyecto->due_on,
                    'starts_on'    => $entregable->due_on ?? $proyecto->due_on,
                    'position'     => ++$posicion,
                ]);

                $entregable->update(['task_id' => $tarea->id]);
            }

            return $pendientes->count();
        });
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
        $tareas = $proyecto->tasks()->with(['assignedTo', 'evidence'])->get();

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
    /** Se delega en el modelo: dos generadores acabarian dando codigos distintos. */
    public function siguienteCodigo(): string
    {
        return Project::siguienteCodigo();
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

        $documento = $this->documentoQueExige($etapa);

        if ($documento && ! $proyecto->tieneDocumento($documento)) {
            throw new ProjectException(sprintf(
                'Falta el documento «%s» para pasar a %s. %s',
                \App\Models\ProjectDocument::TIPOS[$documento] ?? $documento,
                mb_strtolower(Project::ETAPAS[$etapa]),
                $compuerta['explicacion'],
            ));
        }
    }
}
