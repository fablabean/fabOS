<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Evidencia;
use App\Models\User;
use App\Services\Projects\CostingService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * El tablero de un proyecto: Kanban y Gantt (§11).
 *
 * Los dos salen de la misma tabla de tareas. Se sirve como página propia y no
 * dentro del backoffice porque es lo que se mira en una reunión de equipo,
 * proyectado o en una tablet, sin el resto de la administración alrededor.
 */
class ProjectBoardController extends Controller
{
    public function __construct(
        private ProjectService $proyectos,
        private CostingService $costeo,
    ) {}

    public function show(Request $request, Project $project)
    {
        /*
         * Su equipo entra, aunque el rol no le abra la seccion de proyectos.
         *
         * Y al reves: tener rol de backoffice ya no basta para abrir el
         * tablero de cualquier proyecto. Un tablero lleva el cliente, lo
         * acordado y las tareas con nombres; eso no es de todo el que pase.
         */
        abort_unless($this->puedeVer($request->user(), $project), 403);

        return view('proyectos.tablero', [
            'proyecto'   => $project->load([
                'lead', 'members.user', 'documents', 'deliverables.task',
                'assets', 'producciones.reservable',
            ]),
            'tablero'    => $this->proyectos->tablero($project),
            'cronograma' => $this->proyectos->cronograma($project),
            'falta'      => $this->proyectos->queFalta($project),
            'siguiente'  => $this->proyectos->siguienteEtapa($project),
            'costeo'     => $this->costeo->costear($project),
            'evidencias' => $this->proyectos->evidencias($project),
        ]);
    }

    /**
     * El cronograma de todos los proyectos a la vez.
     *
     * El Gantt de un proyecto responde «¿vamos a tiempo?». Este responde la
     * otra pregunta, la que decide si se acepta el siguiente encargo: «¿qué se
     * nos junta en marzo?». Sin verlos superpuestos, cada proyecto parece
     * holgado por separado y el laboratorio se compromete de más.
     */
    /** Quien puede mirar un proyecto: su equipo, o quien tiene la seccion. */
    private function puedeVer(?User $quien, Project $proyecto): bool
    {
        return $quien?->can('view', $proyecto) ?? false;
    }

    public function cronogramaGeneral(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);

        $todos = $request->boolean('todos');

        $proyectos = Project::query()
            ->with('lead')
            // Quien entra por su equipo ve los suyos, no el año entero del
            // laboratorio: el cronograma general es un mapa de la carga de
            // trabajo, y esa es una conversacion de quien coordina.
            ->when(
                ! $request->user()->puedeVerLaSeccion('project'),
                fn ($query) => $query->deAlguien($request->user()),
            )
            // Lo pausado tambien: sigue vivo, y verlo parado al lado de lo que
            // avanza es justo lo que recuerda que hay que volver a el.
            ->when(! $todos, fn ($q) => $q->whereIn('status', ['activo', 'ganado', 'pausado']))
            ->orderByRaw('starts_on is null, starts_on')
            ->orderBy('code')
            ->get();

        [$conFechas, $sinFechas] = $proyectos->partition(
            fn (Project $p) => $p->starts_on || $p->due_on,
        );

        return view('proyectos.cronograma', [
            'conFechas'  => $conFechas->values(),
            'sinFechas'  => $sinFechas->values(),
            'desde'      => $conFechas->min(fn (Project $p) => $p->starts_on ?? $p->due_on),
            'hasta'      => $conFechas->max(fn (Project $p) => $p->due_on ?? $p->starts_on),
            'todos'      => $todos,
        ]);
    }

    /**
     * Sirve una foto de evidencia comprobando quién la pide.
     *
     * Las fotos del trabajo de un cliente viven en el disco **privado**: en el
     * público quedarían en una URL adivinable que cualquiera puede pedir sin
     * haber iniciado sesión. Este rodeo es el precio de que no las vea quien no
     * debe, y es barato.
     */
    public function evidencia(Request $request, Evidencia $evidencia)
    {
        // El enlace firmado del correo también abre: quien llega a la propuesta
        // sin haber entrado tiene que poder ver las imágenes que la explican,
        // o la propuesta le llega a medias.
        abort_unless(
            $request->hasValidSignature() || $this->puedeVerla($request->user(), $evidencia),
            403,
        );
        abort_unless(filled($evidencia->file_path), 404);

        $disco = Storage::disk('local');

        abort_unless($disco->exists($evidencia->file_path), 404);

        return $disco->response($evidencia->file_path, $evidencia->original_name, [
            'Cache-Control' => 'private, max-age=600',
            // Nada se abre dentro del navegador salvo las imagenes. Un archivo
            // subido por cualquiera desde un formulario publico, servido en
            // linea, es una pagina que se ejecuta en nuestro dominio.
            'Content-Disposition' => $evidencia->esImagen()
                ? 'inline'
                : 'attachment; filename="' . addslashes($evidencia->original_name ?: 'archivo') . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Quien la subió también tiene derecho a verla.
     *
     * El backoffice, siempre. Y quien pidió el proyecto, la suya: adjuntó esos
     * archivos, y no poder abrirlos después sería absurdo.
     */
    private function puedeVerla(?User $quien, Evidencia $evidencia): bool
    {
        if (! $quien) {
            return false;
        }

        if ($quien->hasAnyRole(User::ROLES_BACKOFFICE)) {
            return true;
        }

        $duenio = $evidencia->evidenciable;

        // Cuelga del proyecto, o de una de sus propuestas: en los dos casos,
        // de quien lo pidió.
        $proyecto = match (true) {
            $duenio instanceof Project => $duenio,
            $duenio instanceof \App\Models\ProjectProposal => $duenio->project,
            default => null,
        };

        return $proyecto?->requested_by === $quien->id;
    }

    /** Mover una tarjeta de columna. Un clic, sin salir del tablero. */
    public function moverTarea(Request $request, ProjectTask $task)
    {
        // Mover una tarea es cambiar el proyecto, no mirarlo.
        abort_unless(
            $request->user()?->can('update', $task->project) ?? false,
            403,
        );

        $datos = $request->validate([
            'estado' => ['required', 'string'],
        ]);

        try {
            $this->proyectos->moverTarea($task, $datos['estado']);
        } catch (ProjectException $e) {
            return back()->withErrors(['tarea' => $e->getMessage()]);
        }

        return back()->with('status', 'Tarea movida a ' . mb_strtolower(ProjectTask::ESTADOS[$datos['estado']]) . '.');
    }
}
