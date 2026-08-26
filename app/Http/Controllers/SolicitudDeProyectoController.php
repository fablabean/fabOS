<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedir un proyecto desde la web, y ver la propuesta que se responde (§11).
 *
 * Lo que se pierde hoy no son los proyectos grandes: son las ideas que llegan
 * un domingo por WhatsApp y nunca se anotan. Un formulario público las anota,
 * y de paso crea la cuenta con la que quien pide podrá seguirlas.
 */
class SolicitudDeProyectoController extends Controller
{
    public function __construct(
        private ProjectService $proyectos,
        private NotificationService $avisos,
    ) {}

    public function create()
    {
        return view('proyectos.solicitar');
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'titulo'       => ['required', 'string', 'max:180'],
            'resumen'      => ['required', 'string', 'min:20', 'max:2000'],
            'entregables'  => ['nullable', 'string', 'max:2000'],
            'nombre'       => ['required', 'string', 'max:120'],
            'correo'       => ['required', 'email', 'max:180'],
            'telefono'     => ['nullable', 'string', 'max:40'],
            'organizacion' => ['nullable', 'string', 'max:160'],
            'para_cuando'  => ['nullable', 'date', 'after:today'],
            // Trampa para robots: un campo que nadie ve y nadie debería llenar.
            'sitio_web'    => ['prohibited'],
        ], [
            'resumen.min'        => 'Cuéntanos un poco más: con dos líneas no podemos evaluarlo.',
            'para_cuando.after'  => 'Esa fecha ya pasó.',
            'sitio_web.prohibited' => 'No pudimos procesar el formulario.',
        ]);

        $proyecto = $this->proyectos->solicitarDesdeLaWeb($datos);

        // Que quede constancia de que llegó. El silencio después de escribir es
        // lo que hace que la gente vuelva a escribir por otro canal.
        $this->avisos->enviar('proyecto.recibido', $proyecto->requestedBy, [
            'proyecto' => $proyecto->name,
            'codigo'   => $proyecto->code,
        ], $proyecto);

        return redirect()
            ->route('proyectos.solicitar')
            ->with('recibido', $proyecto->code);
    }

    /**
     * La propuesta, tal como la ve quien la pidió.
     *
     * Se llega por el enlace firmado del correo —que funciona sin haber
     * entrado— o con la sesión de quien pidió el proyecto. Las dos puertas
     * hacen falta: la primera para que el correo sirva de inmediato, la segunda
     * para que siga sirviendo cuando el correo se pierda.
     */
    public function propuesta(Request $request, Project $project)
    {
        abort_unless($this->puedeVerla($request, $project), 403);

        return view('proyectos.propuesta', [
            'proyecto' => $project->load(['deliverables', 'lead', 'area', 'documents']),
            'firmado'  => $request->hasValidSignature(),
        ]);
    }

    private function puedeVerla(Request $request, Project $project): bool
    {
        if ($request->hasValidSignature()) {
            return true;
        }

        $quien = $request->user();

        if (! $quien) {
            return false;
        }

        return $quien->id === $project->requested_by
            || $quien->hasAnyRole(User::ROLES_BACKOFFICE);
    }
}
