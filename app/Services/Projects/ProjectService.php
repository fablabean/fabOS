<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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
    public function __construct(private \App\Services\Notifications\NotificationService $avisos) {}

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
     * Una solicitud que llegó por la web.
     *
     * Aquí sí se crea cuenta, y es una diferencia deliberada con el proyecto
     * que anota el laboratorio: quien escribe por la web va a querer **seguir**
     * su proyecto, y sin cuenta no hay dónde seguirlo. Quien llama por teléfono
     * sigue sin necesitarla —esa puerta se queda abierta—.
     *
     * La cuenta nace como invitada, sin permiso de reservar. Rellenar un
     * formulario público no puede ser la forma de conseguir acceso a las
     * máquinas; para eso está el certifab. Si la persona resulta ser
     * estudiante, la categoría se le cambia desde el backoffice.
     *
     * Y el proyecto queda en **idea**: es una solicitud, no un compromiso. Lo
     * que sigue —mirar si cabe, cotizarlo, mandar propuesta— lo decide alguien.
     *
     * @param  array{nombre:string,correo:string,telefono?:?string,organizacion?:?string,titulo:string,resumen:string,entregables?:?string,para_cuando?:?string}  $datos
     */
    public function solicitarDesdeLaWeb(array $datos): Project
    {
        return DB::transaction(function () use ($datos) {
            $correo = mb_strtolower(trim($datos['correo']));

            // Si ya tiene cuenta se reutiliza: dos cuentas con el mismo correo
            // partirían su historial en dos y ninguna de las dos lo tendría
            // completo. No se le toca nada de lo que ya tenía.
            $persona = User::where('email', $correo)->first();

            if (! $persona) {
                $persona = User::create([
                    'name'             => trim($datos['nombre']),
                    'email'            => $correo,
                    'phone'            => $datos['telefono'] ?? null,
                    'status'           => 'activo',
                    'user_category_id' => UserCategory::where('slug', 'invitado')->value('id'),
                ]);
            }

            $proyecto = Project::create([
                'name'            => trim($datos['titulo']),
                'stage'           => 'idea',
                'status'          => 'activo',
                'source'          => 'formulario',
                'client_kind'     => $datos['cliente'] ?? 'externo',
                'summary'         => $datos['resumen'],
                'organization'    => $datos['organizacion'] ?? null,
                'contact_name'    => trim($datos['nombre']),
                'contact_email'   => $correo,
                'contact_phone'   => $datos['telefono'] ?? null,
                'requested_by'    => $persona->id,
                'due_on'          => $datos['para_cuando'] ?? null,
            ]);

            // Lo que la persona escribió como lista se guarda como lista: es lo
            // que después se compara con lo que se entregó.
            foreach ($this->enLineas($datos['entregables'] ?? null) as $posicion => $titulo) {
                $proyecto->deliverables()->create([
                    'title'    => mb_substr($titulo, 0, 255),
                    'position' => $posicion,
                ]);
            }

            return $proyecto->refresh();
        });
    }

    /** Un texto de varias líneas, limpio de viñetas escritas a mano. */
    private function enLineas(?string $texto): array
    {
        if (blank($texto)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $texto))
            ->map(fn (string $l) => trim(preg_replace('/^\s*[-*•·]\s*/u', '', $l)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Redacta y manda la propuesta.
     *
     * La propuesta **es el proyecto**: sus entregables, su valor y sus fechas.
     * No hay un documento aparte que redactar porque un documento aparte se
     * separaría de la ficha a la primera corrección, y entonces habría dos
     * versiones de lo que se prometió. Lo que se escribe aquí queda guardado en
     * el proyecto, y el correo solo lleva el enlace para verlo.
     *
     * Se manda a la cuenta de quien lo pidió si la hay, y si no al correo de
     * contacto: el laboratorio anota proyectos de quien no tiene cuenta, y
     * responderle es igual de necesario.
     *
     * @param  array{mensaje?:?string,estimated_value?:?int,starts_on?:?string,due_on?:?string,entregables?:array}  $datos
     *
     * @throws ProjectException si no hay a quién mandársela
     */
    public function enviarPropuesta(Project $proyecto, array $datos = []): Project
    {
        $correo = $proyecto->requestedBy?->email ?: $proyecto->contact_email;

        if (blank($correo)) {
            throw new ProjectException(
                'Este proyecto no tiene correo de contacto ni cuenta asociada: no hay a quién mandarle la propuesta.',
            );
        }

        return DB::transaction(function () use ($proyecto, $datos, $correo) {
            $proyecto->fill(array_filter([
                'estimated_value' => $datos['estimated_value'] ?? null,
                'starts_on'       => $datos['starts_on'] ?? null,
                'due_on'          => $datos['due_on'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''))->save();

            if (array_key_exists('entregables', $datos)) {
                $this->sincronizarEntregables($proyecto, $datos['entregables'] ?? []);
            }

            $proyecto->refresh()->load('deliverables');

            // Una version por envio: a la tercera, nadie recuerda que se
            // ofrecio la primera vez, y esa es justo la pregunta que llega
            // cuando alguien dice «pero ustedes habian dicho...».
            $version = $proyecto->proposals()->create([
                'version'         => (int) $proyecto->proposals()->max('version') + 1,
                'estimated_value' => (int) $proyecto->estimated_value,
                'starts_on'       => $proyecto->starts_on,
                'due_on'          => $proyecto->due_on,
                'message'         => $datos['mensaje'] ?? null,
                'deliverables'    => $proyecto->deliverables
                    ->map(fn ($e) => ['title' => $e->title, 'due_on' => $e->due_on?->toDateString()])
                    ->all(),
                'sent_at'         => now(),
                'sent_by'         => auth()->id(),
            ]);

            // Las imagenes cuelgan de la version, no del proyecto: las de la v1
            // explican la v1, y lo seguirian haciendo aunque la v2 proponga
            // otra cosa.
            foreach ($datos['imagenes'] ?? [] as $ruta) {
                if (blank($ruta)) {
                    continue;
                }

                $version->evidence()->create([
                    'kind'          => 'foto',
                    'file_path'     => $ruta,
                    'original_name' => basename($ruta),
                    'uploaded_by'   => auth()->id(),
                ]);
            }

            // Y la primera puede quedar como la cara del proyecto: casi siempre
            // es la misma imagen, y volver a subirla seria trabajo doble.
            if (($datos['usar_como_referencia'] ?? false) && filled($datos['imagenes'] ?? [])) {
                $proyecto->update(['reference_image_path' => $datos['imagenes'][0]]);
            }

            $variables = [
                'proyecto' => $proyecto->name,
                'codigo'   => $proyecto->code,
                'enlace'   => URL::temporarySignedRoute(
                    'proyectos.propuesta',
                    now()->addDays(60),
                    ['project' => $proyecto->id],
                ),
                'mensaje'  => $datos['mensaje'] ?? '',
                // En el asunto, para que dos correos seguidos no parezcan el
                // mismo: «Propuesta v2 para...».
                'version'  => $version->etiqueta(),
            ];

            if ($proyecto->requestedBy) {
                $this->avisos->enviar('proyecto.propuesta', $proyecto->requestedBy, $variables, $proyecto);
            } else {
                $this->avisos->enviarSinCuenta(
                    'proyecto.propuesta',
                    $correo,
                    $proyecto->contact_name ?: $proyecto->organization ?: 'Hola',
                    $variables,
                    $proyecto,
                );
            }

            $proyecto->update(['proposal_sent_at' => now()]);

            // Mandar la propuesta ES estar en la etapa de propuesta. No hace
            // falta pedirle además la compuerta: el hecho es la evidencia.
            $this->avanzarPorEvento($proyecto, 'propuesta');

            return $proyecto->refresh();
        });
    }

    /**
     * Deja dicho algo sobre la propuesta, y avisa a quien la lleva.
     *
     * El aviso es la mitad del asunto: un comentario que nadie lee es igual que
     * no haberlo escrito, y quien lo escribió se queda esperando.
     */
    public function comentar(
        Project $proyecto,
        string $texto,
        ?User $quien = null,
        ?string $nombreSuelto = null,
    ): ProjectComment {
        $delLaboratorio = $quien?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;

        $comentario = $proyecto->comments()->create([
            'user_id'     => $quien?->id,
            'author_name' => $quien?->name ?: $nombreSuelto,
            'side'        => $delLaboratorio ? 'laboratorio' : 'cliente',
            'body'        => $texto,
        ]);

        // Al laboratorio solo se le avisa de lo que dice el cliente: avisarle
        // de lo que escribió él mismo es ruido.
        if (! $delLaboratorio && $proyecto->lead) {
            $this->avisos->enviar('proyecto.comentario', $proyecto->lead, [
                'proyecto'    => $proyecto->name,
                'codigo'      => $proyecto->code,
                'quien'       => $comentario->quien(),
                'comentario'  => $texto,
                'enlace'      => route('proyectos.tablero', $proyecto),
            ], $proyecto);
        }

        return $comentario;
    }

    /**
     * Quien pidió el proyecto acepta la propuesta.
     *
     * Es el momento en que un intercambio de correos se vuelve un acuerdo. Sin
     * una fecha de «sí» el proyecto avanza sobre un acuerdo verbal, que es
     * justo lo que las compuertas documentales existen para evitar.
     *
     * Y aquí se bifurca el trámite. A un **área de la propia institución** hay
     * que explicarle el circuito de la venta interna —formulario, líder que
     * paga, líder que recibe, traslado de Planeación—, porque nada se fabrica
     * hasta que Planeación confirme. A un estudiante o a alguien de fuera esa
     * explicación le sobra y solo enturbia el mensaje.
     *
     * @throws ProjectException si ya estaba aceptado
     */
    public function aceptarPropuesta(Project $proyecto, ?User $quien = null, ?string $nota = null): Project
    {
        if ($proyecto->estaAceptado()) {
            throw new ProjectException('Esta propuesta ya estaba aceptada.');
        }

        if (! $proyecto->proposal_sent_at) {
            throw new ProjectException('Todavía no hay una propuesta que aceptar.');
        }

        return DB::transaction(function () use ($proyecto, $quien, $nota) {
            $proyecto->update([
                'accepted_at'     => now(),
                'accepted_by'     => $quien?->id,
                'acceptance_note' => $nota,
            ]);

            // Si aceptó diciendo algo, eso también es parte de la conversación:
            // guardarlo solo en `acceptance_note` lo escondería del hilo.
            if (filled($nota)) {
                $proyecto->comments()->create([
                    'user_id'     => $quien?->id,
                    'author_name' => $quien?->name ?: $proyecto->contact_name,
                    'side'        => 'cliente',
                    'body'        => $nota,
                ]);
            }

            $variables = [
                'proyecto'          => $proyecto->name,
                'codigo'            => $proyecto->code,
                'enlace'            => URL::temporarySignedRoute(
                    'proyectos.propuesta',
                    now()->addDays(60),
                    ['project' => $proyecto->id],
                ),
                'enlace_formulario' => (string) config('fabos.proyectos.formulario_venta_interna'),
            ];

            // Una propuesta aceptada es un acuerdo: lo que sigue es dejarlo
            // por escrito, y eso es la etapa de contrato.
            $this->avanzarPorEvento($proyecto, 'contrato');

            $clave = $proyecto->esClienteInterno()
                ? 'proyecto.venta_interna'
                : 'proyecto.aceptada';

            $correo = $proyecto->requestedBy?->email ?: $proyecto->contact_email;

            if ($proyecto->requestedBy) {
                $this->avisos->enviar($clave, $proyecto->requestedBy, $variables, $proyecto);
            } elseif (filled($correo)) {
                $this->avisos->enviarSinCuenta(
                    $clave,
                    $correo,
                    $proyecto->contact_name ?: $proyecto->organization ?: 'Hola',
                    $variables,
                    $proyecto,
                );
            }

            return $proyecto->refresh();
        });
    }

    /**
     * Deja la lista de entregables igual a la que se acaba de escribir.
     *
     * Los que ya existían se actualizan en vez de recrearse: si se borraran y
     * volvieran a crear, cada uno perdería su tarea en el tablero y lo que ya
     * se hubiera entregado volvería a aparecer pendiente.
     *
     * @param  array<int,array{id?:?int,title?:?string,due_on?:?string}>  $lineas
     */
    private function sincronizarEntregables(Project $proyecto, array $lineas): void
    {
        $vistos = [];
        $posicion = 0;

        foreach ($lineas as $linea) {
            $titulo = trim((string) ($linea['title'] ?? ''));

            if ($titulo === '') {
                continue;
            }

            $entregable = filled($linea['id'] ?? null)
                ? $proyecto->deliverables()->find($linea['id'])
                : null;

            $atributos = [
                'title'    => mb_substr($titulo, 0, 255),
                'due_on'   => $linea['due_on'] ?? null,
                'position' => $posicion++,
            ];

            $entregable
                ? $entregable->update($atributos)
                : $entregable = $proyecto->deliverables()->create($atributos);

            $vistos[] = $entregable->id;
        }

        // Lo que se quitó de la lista se quita de verdad: dejarlo colgando
        // haría que la propuesta enseñe una cosa y la ficha otra.
        $proyecto->deliverables()->whereNotIn('id', $vistos ?: [0])->delete();
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
    public function moverA(Project $proyecto, string $etapa, bool $exigirCompuertas = true): Project
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

        if ($exigirCompuertas) {
            foreach (array_slice($orden, $desde + 1, $hasta - $desde) as $paso) {
                $this->exigirCompuerta($proyecto, $paso);
            }
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

    /**
     * Mueve la etapa porque **pasó algo**, no porque alguien lo pidió.
     *
     * Mandar la propuesta, aceptarla, registrar el contrato: cada uno de esos
     * hechos *es* la evidencia de la etapa a la que lleva, así que exigirle
     * además la compuerta sería pedir dos veces lo mismo. Y dejar el embudo
     * atrás mientras los hechos avanzan hace que el listado mienta, que es peor
     * que cualquier compuerta saltada.
     *
     * Solo avanza: un hecho posterior no puede devolver un proyecto a una etapa
     * anterior. Retroceder sigue siendo una decisión de quien coordina.
     */
    public function avanzarPorEvento(Project $proyecto, string $etapa): Project
    {
        $orden = array_keys(Project::ETAPAS);
        $ahora = array_search($proyecto->stage, $orden, true);
        $destino = array_search($etapa, $orden, true);

        if ($destino === false || $ahora === false || $destino <= $ahora) {
            return $proyecto;
        }

        // Un proyecto descartado o perdido no avanza por inercia.
        if (in_array($proyecto->status, ['descartado', 'perdido'], true)) {
            return $proyecto;
        }

        return $this->moverA($proyecto, $etapa, exigirCompuertas: false);
    }

    /**
     * Qué etapa abre cada documento cuando se registra.
     *
     * Registrar el contrato firmado es aceptar el contrato, y lo que sigue a un
     * contrato aceptado es el brief. Igual con el brief y la ejecución, y con
     * el informe y el cierre. Antes había que acordarse de mover la etapa a
     * mano después de subir el papel, y nadie se acuerda.
     */
    public const ETAPA_QUE_ABRE_EL_DOCUMENTO = [
        'propuesta' => 'propuesta',
        'contrato'  => 'brief',
        'brief'     => 'ejecucion',
        'informe'   => 'cierre',
    ];

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

    /**
     * Pausar: parado, no muerto.
     *
     * Un proyecto que espera una firma, una pieza que no llega o el semestre
     * siguiente sigue vivo. Sin este estado, lo que se hace es descartarlo
     * para que deje de aparecer como atrasado, y entonces se pierde el rastro
     * de que hay que volver a el.
     *
     * No se toca `closed_at`: no se ha cerrado nada. El motivo va a las notas
     * de cierre porque es el mismo campo donde vive «que paso con esto», y
     * tener dos sitios para lo mismo acaba dejando uno vacio.
     */
    public function pausar(Project $proyecto, string $motivo): Project
    {
        $proyecto->update([
            'status'        => 'pausado',
            'closing_notes' => $motivo,
        ]);

        return $proyecto->refresh();
    }

    /** Y volver: se limpia el motivo, que ya no describe donde esta. */
    public function reanudar(Project $proyecto): Project
    {
        $proyecto->update(['status' => 'activo', 'closing_notes' => null]);

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
