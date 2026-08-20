<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\CostingService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;

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
            'proyecto'   => $project->load(['lead', 'members.user', 'documents']),
            'tablero'    => $this->proyectos->tablero($project),
            'cronograma' => $this->proyectos->cronograma($project),
            'falta'      => $this->proyectos->queFalta($project),
            'siguiente'  => $this->proyectos->siguienteEtapa($project),
            'costeo'     => $this->costeo->costear($project),
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
