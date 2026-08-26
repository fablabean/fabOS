<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SoportesDeSolicitud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
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
        private SoportesDeSolicitud $soportes,
    ) {}

    public function create(Request $request)
    {
        $usuario = $request->user();

        return view('proyectos.solicitar', [
            'usuario' => $usuario,
            // A quien ya entró no se le pregunta: su categoría ya lo dice, y
            // preguntárselo sería dejar que se equivoque en una respuesta que
            // el sistema ya tiene.
            'tramite' => $usuario?->category?->tramiteDeCliente(),
        ]);
    }

    public function store(Request $request)
    {
        // A quien ya entró no se le vuelve a preguntar quién es. Pedirle otra
        // vez el correo abre además la puerta a que escriba uno distinto y el
        // proyecto acabe colgando de una cuenta que no es la suya.
        $identificado = $request->user();

        $datos = $request->validate([
            'titulo'       => ['required', 'string', 'max:180'],
            'resumen'      => ['required', 'string', 'min:20', 'max:2000'],
            'entregables'  => ['nullable', 'string', 'max:2000'],
            'nombre'       => [Rule::requiredIf(! $identificado), 'nullable', 'string', 'max:120'],
            'correo'       => [Rule::requiredIf(! $identificado), 'nullable', 'email', 'max:180'],
            'telefono'     => ['nullable', 'string', 'max:40'],
            'organizacion' => ['nullable', 'string', 'max:160'],
            'cliente'      => [Rule::requiredIf(! $request->user()?->category), Rule::in(array_keys(Project::CLIENTES))],
            'para_cuando'  => ['nullable', 'date', 'after:today'],

            'soportes'     => ['nullable', 'array', 'max:' . SoportesDeSolicitud::MAXIMO],
            'soportes.*'   => [
                'file',
                'max:' . SoportesDeSolicitud::TAMANO_MAXIMO,
                'mimes:' . implode(',', SoportesDeSolicitud::TIPOS),
            ],
            'dibujo'       => ['nullable', 'string'],

            // Trampa para robots: un campo que nadie ve y nadie debería llenar.
            'sitio_web'    => ['prohibited'],
        ], [
            'resumen.min'          => 'Cuéntanos un poco más: con dos líneas no podemos evaluarlo.',
            'para_cuando.after'    => 'Esa fecha ya pasó.',
            'soportes.max'         => 'Como mucho ' . SoportesDeSolicitud::MAXIMO . ' archivos.',
            'soportes.*.mimes'     => 'Ese tipo de archivo no lo aceptamos. Imágenes, PDF, planos o documentos de oficina.',
            'soportes.*.max'       => 'Cada archivo puede pesar hasta 10 MB.',
            'sitio_web.prohibited' => 'No pudimos procesar el formulario.',
        ]);

        // La categoría manda sobre lo que diga el formulario: quien ya entró no
        // elige su propio trámite.
        if ($tramite = $identificado?->category?->tramiteDeCliente()) {
            $datos['cliente'] = $tramite;
        }

        $datos['cliente'] ??= 'externo';

        // Un encargo de un área de la propia institución no se paga: se mueve
        // por la venta interna, un circuito de cuatro manos -formulario, líder
        // que paga, líder que recibe, traslado de Planeación- que no se corre
        // en tres días. Prometer una fecha más cercana sería prometer algo que
        // el trámite no puede cumplir, y el «no» llegaría tarde y peor.
        $dias = (int) config('fabos.proyectos.dias_minimos_interno');

        if ($datos['cliente'] === 'interno'
            && filled($datos['para_cuando'] ?? null)
            && \Illuminate\Support\Carbon::parse($datos['para_cuando'])->lt(now()->addDays($dias)->startOfDay())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'para_cuando' => "Un encargo de un área de la Universidad necesita al menos {$dias} días "
                    . 'calendario: su traslado presupuestal pasa por el formulario de pedido, el visto bueno '
                    . 'de dos líderes y Planeación. Si es urgente, escríbenos y lo miramos.',
            ]);
        }

        if ($identificado) {
            $datos['nombre'] = $identificado->name;
            $datos['correo'] = $identificado->email;
            $datos['telefono'] = ($datos['telefono'] ?? null) ?: $identificado->phone;
        }

        $proyecto = $this->proyectos->solicitarDesdeLaWeb($datos);

        // Los soportes van después de crear el proyecto: si algo falla al
        // guardarlos, la solicitud ya está anotada. Perder la idea por un
        // archivo sería el peor de los dos males.
        $this->soportes->guardar($proyecto, $request->file('soportes', []));
        $this->soportes->guardarDibujo($proyecto, $request->input('dibujo'));

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

        $firmado = $request->hasValidSignature();

        return view('proyectos.propuesta', [
            'proyecto' => $project->load(['deliverables', 'lead', 'area', 'documents', 'evidence', 'comments.user']),
            'firmado'  => $firmado,

            // El backoffice mira, no acepta en nombre de nadie.
            'puedeAceptar' => $firmado || $request->user()?->id === $project->requested_by,

            // Quien llega por el correo acepta con un enlace firmado también:
            // sin sesión, el POST no tendría cómo demostrar quién es.
            'urlAceptar' => URL::temporarySignedRoute(
                'proyectos.aceptar',
                now()->addDays(60),
                ['project' => $project->id],
            ),
        ]);
    }

    /**
     * Quien pidió el proyecto acepta la propuesta.
     *
     * Se acepta desde la misma página donde se lee, con el enlace del correo o
     * con la sesión. Obligar a responder el correo para decir que sí dejaría la
     * aceptación fuera del sistema, que es donde no sirve.
     */
    public function aceptar(Request $request, Project $project)
    {
        abort_unless($this->puedeVerla($request, $project), 403);

        // El backoffice mira, no acepta en nombre de nadie.
        abort_if(
            ! $request->hasValidSignature() && $request->user()?->id !== $project->requested_by,
            403,
            'La propuesta la acepta quien la pidió.',
        );

        $datos = $request->validate([
            'nota' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->proyectos->aceptarPropuesta($project, $request->user(), $datos['nota'] ?? null);
        } catch (ProjectException $e) {
            return back()->withErrors(['aceptar' => $e->getMessage()]);
        }

        return back()->with('aceptada', true);
    }

    /**
     * Un comentario sobre la propuesta, sin aceptarla.
     *
     * «Casi, pero cambia la fecha» es la respuesta más común a una propuesta, y
     * sin un sitio donde decirla acaba en un chat donde nadie la vuelve a
     * encontrar.
     */
    public function comentar(Request $request, Project $project)
    {
        abort_unless($this->puedeVerla($request, $project), 403);

        // Aceptada ya no se discute por aquí. Quitar el formulario de la
        // pantalla no basta: el enlace seguiría aceptando el envío, y lo que
        // se ajuste después del sí tiene que quedar en el contrato.
        if ($project->estaAceptado() && ! $request->user()?->hasAnyRole(User::ROLES_BACKOFFICE)) {
            return back()->withErrors([
                'aceptar' => 'La propuesta ya está aceptada. Si algo cambió, escríbele a quien lleva el proyecto.',
            ]);
        }

        $datos = $request->validate([
            'body' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->proyectos->comentar(
            $project,
            $datos['body'],
            $request->user(),
            $project->contact_name,
        );

        return back()->with('comentado', true);
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
