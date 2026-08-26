<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskEvidence;
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
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);

        return view('proyectos.tablero', [
            'proyecto'   => $project->load(['lead', 'members.user', 'documents', 'deliverables.task']),
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
    public function cronogramaGeneral(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);

        $todos = $request->boolean('todos');

        $proyectos = Project::query()
            ->with('lead')
            ->when(! $todos, fn ($q) => $q->whereIn('status', ['activo', 'ganado']))
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
    public function evidencia(Request $request, ProjectTaskEvidence $evidencia)
    {
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);
        abort_unless(filled($evidencia->file_path), 404);

        $disco = Storage::disk('local');

        abort_unless($disco->exists($evidencia->file_path), 404);

        return $disco->response($evidencia->file_path, null, [
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /** Mover una tarjeta de columna. Un clic, sin salir del tablero. */
    public function moverTarea(Request $request, ProjectTask $task)
    {
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);

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
