<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SoportesDeSolicitud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            // A quien no, se le ofrecen las categorias de verdad —estudiante,
            // profesor, colaborador, externo—: de ahi sale el tramite, y la
            // cuenta nace con esa categoria. «Invitado» no es una opcion: es
            // lo que queda cuando nadie eligio.
            'categorias' => self::categoriasParaElegir(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int,UserCategory> */
    public static function categoriasParaElegir(): \Illuminate\Support\Collection
    {
        return UserCategory::query()
            ->where('slug', '<>', 'invitado')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
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
            'telefono_indicativo' => ['nullable', 'string', 'max:6'],
            'organizacion' => ['nullable', 'string', 'max:160'],

            // Quien firma: lo que el contrato necesita, pedido de una vez.
            'persona'        => ['nullable', Rule::in(array_keys(Project::PERSONAS))],
            'documento_tipo' => ['nullable', Rule::in(array_keys(Project::DOCUMENTOS))],
            'documento'      => ['nullable', 'string', 'max:40'],
            'razon_social'   => ['nullable', 'string', 'max:180', Rule::requiredIf(fn () => $request->input('persona') === 'juridica')],
            'representante'  => ['nullable', 'string', 'max:120'],
            'direccion'      => ['nullable', 'string', 'max:200'],
            // Sin sesion se elige la categoria, y de ella sale el tramite. El
            // tramite suelto se sigue aceptando por si algo viejo lo manda.
            'categoria'    => [
                Rule::requiredIf(! $identificado && ! $request->filled('cliente')),
                'nullable',
                Rule::exists('user_categories', 'slug')->where(fn ($q) => $q->where('slug', '<>', 'invitado')),
            ],
            'cliente'      => ['nullable', Rule::in(array_keys(Project::CLIENTES))],
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
            'soportes.*.max'       => 'Cada archivo puede pesar hasta ' . intdiv(SoportesDeSolicitud::TAMANO_MAXIMO, 1024) . ' MB.',
            'sitio_web.prohibited' => 'No pudimos procesar el formulario.',
        ]);

        // El telefono llega en dos partes y se guarda como una: «+57 3001234567».
        $datos['telefono'] = \App\Support\Telefono::componer($datos['telefono_indicativo'] ?? null, $datos['telefono'] ?? null);

        // La categoría manda sobre lo que diga el formulario: quien ya entró no
        // elige su propio trámite.
        if ($tramite = $identificado?->category?->tramiteDeCliente()) {
            $datos['cliente'] = $tramite;
        }

        // Sin sesion, la categoria elegida dice el tramite y con ella nace la
        // cuenta, pendiente de que alguien del laboratorio la confirme.
        if (! $identificado && filled($datos['categoria'] ?? null)) {
            $categoria = UserCategory::where('slug', $datos['categoria'])->firstOrFail();
            $datos['cliente'] = $categoria->tramiteDeCliente();
            $datos['categoria_id'] = $categoria->id;
        }

        $datos['cliente'] ??= 'externo';

        // Un encargo de un área de la propia institución no se paga: se mueve
        // por la venta interna, un circuito de cuatro manos -formulario, líder
        // que paga, líder que recibe, traslado de Planeación- que no se corre
        // en tres días. Prometer una fecha más cercana sería prometer algo que
        // el trámite no puede cumplir, y el «no» llegaría tarde y peor.
        //
        // Pero no todo encargo interno mueve presupuesto, asi que a un area no
        // se le exige un minimo: se le avisa. A uno de fuera si —dos semanas:
        // cotizacion, contrato, compra de material— y a un estudiante, tres
        // dias. Cada tipo tiene su plazo en la configuracion.
        $minimos = (array) config('fabos.proyectos.dias_minimos', []);
        $dias = (int) ($minimos[$datos['cliente']] ?? 0);
        $fecha = filled($datos['para_cuando'] ?? null)
            ? \Illuminate\Support\Carbon::parse($datos['para_cuando'])
            : null;

        if ($dias > 0 && $fecha && $fecha->lt(now()->addDays($dias)->startOfDay())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'para_cuando' => $datos['cliente'] === 'externo'
                    ? "Un encargo de fuera necesita al menos {$dias} días calendario: hay cotización, "
                        . 'contrato y compra de material. Si es urgente, escríbenos y lo miramos.'
                    : "Necesitamos al menos {$dias} días calendario para poder cumplir. "
                        . 'Si es urgente, escríbenos y lo miramos.',
            ]);
        }

        $presupuesto = (int) config('fabos.proyectos.dias_presupuesto');

        $avisoPresupuesto = $datos['cliente'] === 'interno' && $fecha
            && $fecha->lt(now()->addDays($presupuesto)->startOfDay())
            ? 'Si el proyecto exige presupuesto, hay que cumplir los tiempos de la Universidad: el '
                . "traslado presupuestal necesita al menos {$presupuesto} días calendario y pasa por el "
                . 'formulario de pedido, dos líderes y Planeación. Sin presupuesto de por medio, la fecha puede mantenerse.'
            : null;

        if ($identificado) {
            $datos['nombre'] = $identificado->name;
            $datos['correo'] = $identificado->email;
            $datos['telefono'] = ($datos['telefono'] ?? null) ?: $identificado->phone;
        }

        $proyecto = $this->proyectos->solicitarDesdeLaWeb($datos);

        // El aviso queda en la ficha: quien evalue el encargo tiene que ver
        // que la fecha pedida no da para un traslado presupuestal.
        if ($avisoPresupuesto) {
            $proyecto->update(['notes' => trim(
                ($proyecto->notes ? $proyecto->notes . "\n\n" : '')
                . 'Pedido para el ' . $fecha->format('d/m/Y') . ', con menos de ' . $presupuesto
                . ' días: no da para un traslado presupuestal. Se le avisó al pedir.',
            )]);
        }

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
            ->with('recibido', $proyecto->code)
            ->with('aviso', $avisoPresupuesto);
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
            'proyecto' => $project->load([
                'deliverables', 'lead', 'area', 'documents', 'evidence', 'comments.user',
                'proposals.evidence',
            ]),

            // Quien llega por el correo no tiene sesión: la portada también va
            // firmada, o le llegaría rota.
            'portada' => $project->reference_image_path
                ? ($firmado
                    ? URL::temporarySignedRoute('proyectos.imagen', now()->addDays(60), ['project' => $project->id])
                    : route('proyectos.imagen', $project))
                : null,
            'firmado'  => $firmado,

            // La regla vive en el modelo: la misma pregunta la hace el POST de
            // aceptar, y dos respuestas para la misma pregunta acaban
            // contradiciendose -la pantalla escondia el boton y la direccion
            // seguia aceptando, o al reves-.
            'puedeAceptar' => $project->loPuedeAceptar($request->user(), $firmado),

            // Quien llega por el correo acepta con un enlace firmado también:
            // sin sesión, el POST no tendría cómo demostrar quién es.
            // Responder tambien funciona desde el enlace del correo: sin
            // sesion, el POST necesita la firma igual que aceptar.
            'urlComentar' => $firmado
                ? URL::temporarySignedRoute('proyectos.comentar', now()->addDays(30), ['project' => $project->id])
                : route('proyectos.comentar', $project),

            // El pago que espera algo, con la direccion para responderle.
            'pago' => $pago = $project->pagoPendiente(),
            'urlPagar' => $pago
                ? ($firmado
                    ? URL::temporarySignedRoute('proyectos.pagar', now()->addDays(60), ['project' => $project->id, 'payment' => $pago->id])
                    : route('proyectos.pagar', ['project' => $project, 'payment' => $pago]))
                : null,
            'qrDePagos' => \App\Support\Settings::qrDePagos() ? route('pagos.qr') : null,
            'pagosValidados' => $project->payments()->where('status', \App\Models\ProjectPayment::VALIDADO)->get(),

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

        // El backoffice mira, no acepta en nombre de nadie. Y «quien la pidió»
        // no es siempre `requested_by`: un proyecto que anota el propio
        // laboratorio figura a nombre del colaborador que lo anotó.
        abort_unless(
            $project->loPuedeAceptar($request->user(), $request->hasValidSignature()),
            403,
            'La propuesta la acepta el cliente.',
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
    /**
     * El cliente responde a un pago pedido: comprobante, nombre y documento.
     * Por el enlace firmado del correo o con su sesion, como el resto.
     */
    public function pagar(Request $request, Project $project, \App\Models\ProjectPayment $payment)
    {
        abort_unless($this->puedeVerla($request, $project), 403);
        abort_unless($payment->project_id === $project->id, 404);

        $datos = $request->validate([
            'comprobante' => ['required', 'file', 'max:' . SoportesDeSolicitud::TAMANO_MAXIMO, 'mimes:jpg,jpeg,png,webp,heic,pdf'],
            'nombre'      => ['required', 'string', 'min:3', 'max:160'],
            'documento'   => ['required', 'string', 'min:4', 'max:40'],
        ], [
            'comprobante.required' => 'Adjunta la captura o el comprobante del pago.',
            'comprobante.mimes'    => 'El comprobante puede ser una imagen o un PDF.',
            'nombre.required'      => 'Escribe tu nombre completo.',
            'documento.required'   => 'Escribe tu número de documento.',
        ]);

        try {
            app(\App\Services\Projects\PagosDeProyecto::class)->enviarComprobante(
                $payment, $request->file('comprobante'), $datos['nombre'], $datos['documento'], $request->user(),
            );
        } catch (\App\Services\Projects\ProjectException $e) {
            return back()->withErrors(['comprobante' => $e->getMessage()]);
        }

        return back()->with('comentado', true)->with('status', 'Recibimos tu comprobante. Te avisamos cuando lo validemos.');
    }

    public function comentar(Request $request, Project $project)
    {
        abort_unless($this->puedeVerla($request, $project), 403);

        // Un proyecto cerrado o descartado ya no conversa: lo que haya que
        // decir es otro proyecto. Aceptado si: el laboratorio sigue haciendo
        // preguntas durante la ejecucion —«mandanos el vectorial»— y esta es
        // la unica puerta por la que el cliente responde con archivos. Lo
        // que cambie el acuerdo va al contrato; una respuesta no lo cambia.
        if ($project->estaCerrado() && ! $request->user()?->hasAnyRole(User::ROLES_BACKOFFICE)) {
            return back()->withErrors([
                'aceptar' => 'Este proyecto ya está cerrado. Si necesitas algo más, pídelo como un proyecto nuevo.',
            ]);
        }

        $datos = $request->validate([
            // Con archivos, el texto puede faltar: mandar el plano ya dice algo.
            'body'       => [Rule::requiredIf(! $request->hasFile('soportes')), 'nullable', 'string', 'min:3', 'max:2000'],
            'soportes'   => ['nullable', 'array', 'max:' . SoportesDeSolicitud::MAXIMO],
            'soportes.*' => [
                'file',
                'max:' . SoportesDeSolicitud::TAMANO_MAXIMO,
                'mimes:' . implode(',', SoportesDeSolicitud::TIPOS),
            ],
        ], [
            'body.required'    => 'Escribe algo o adjunta un archivo.',
            'soportes.max'     => 'Como mucho ' . SoportesDeSolicitud::MAXIMO . ' archivos por respuesta.',
            'soportes.*.mimes' => 'Ese tipo de archivo no lo aceptamos. Imágenes, PDF, planos, modelos o comprimidos.',
            'soportes.*.max'   => 'Cada archivo puede pesar hasta ' . intdiv(SoportesDeSolicitud::TAMANO_MAXIMO, 1024) . ' MB.',
        ]);

        /*
         * Lo adjunto se suma a los soportes del proyecto, no se queda pegado
         * al comentario: es lo que el laboratorio necesita para trabajar, y
         * desde la ficha se convierte en documento del proyecto con un clic.
         * En el hilo queda dicho que archivos llegaron con esta respuesta.
         */
        $archivos = collect($request->file('soportes', []))
            ->filter(fn ($a) => $a instanceof \Illuminate\Http\UploadedFile && $a->isValid())
            ->take(SoportesDeSolicitud::MAXIMO);

        $texto = trim((string) ($datos['body'] ?? ''));

        if ($archivos->isNotEmpty()) {
            $nombres = $archivos->map(fn ($a) => $a->getClientOriginalName())->implode(', ');
            $texto = trim($texto . "\n\nAdjuntó: " . $nombres . '.');
        }

        $comentario = $this->proyectos->comentar(
            $project,
            $texto,
            $request->user(),
            $project->contact_name,
        );

        // Y pegados a la respuesta, para que se vean debajo de lo que dijo.
        if ($archivos->isNotEmpty()) {
            $this->soportes->guardar($project, $archivos->all(), $comentario, $request->user()?->id);
        }

        return back()->with('comentado', true);
    }

    /**
     * La imagen de referencia del proyecto.
     *
     * Mismas puertas que la propuesta: el enlace firmado del correo, la sesión
     * de quien lo pidió, o el backoffice. Va por aquí y no por /storage porque
     * es material de alguien de fuera, y una URL adivinable lo dejaría a la
     * vista de cualquiera.
     */
    public function imagen(Request $request, Project $project)
    {
        abort_unless($this->puedeVerla($request, $project), 403);
        abort_unless(filled($project->reference_image_path), 404);

        $disco = Storage::disk('local');

        abort_unless($disco->exists($project->reference_image_path), 404);

        return $disco->response($project->reference_image_path, null, [
            'Cache-Control'          => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Un documento del proyecto -el contrato, casi siempre- para quien lo pidió.
     *
     * Se sirve desde aquí y no por /storage: los documentos viven en el disco
     * privado, y con razón. Quien llega por el correo trae el enlace firmado;
     * quien entra con su cuenta, su sesión.
     */
    public function documento(Request $request, Project $project, \App\Models\ProjectDocument $document)
    {
        abort_unless($this->puedeVerla($request, $project), 403);
        abort_unless($document->project_id === $project->id, 404);

        if ($document->url) {
            return redirect()->away($document->url);
        }

        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        abort_unless($document->file_path && $disco->exists($document->file_path), 404);

        return $disco->response($document->file_path, basename($document->file_path), [
            'X-Content-Type-Options' => 'nosniff',
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

        // El cliente ve la suya, venga el proyecto de la web o lo haya anotado
        // el laboratorio: en el segundo caso su cuenta no figura como «quien lo
        // pidió» y se quedaba fuera de su propia propuesta.
        return $project->loPuedeAceptar($quien)
            || $quien->id === $project->requested_by
            || $quien->hasAnyRole(User::ROLES_BACKOFFICE);
    }
}
