<?php

namespace App\Services\Training;

use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\CourseEditionChange;
use App\Models\CourseSession;
use App\Models\Enrollment;
use App\Models\RegistrationQuestion;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * El ciclo de un curso, taller o evento, de la publicación a la encuesta (§9).
 *
 * Tres invariantes:
 *
 *  1. **Nadie ocupa una silla que no hay.** El cupo se mira con la edición
 *     bloqueada: dos personas enviando el formulario a la vez no toman las
 *     dos la última silla. La segunda queda en lista de espera, y se le dice.
 *  2. **Quien se queda sin cupo no se pierde.** La lista de espera no se
 *     borra al cerrar: es la demanda con la que se decide abrir otro grupo.
 *  3. **Todo cambio deja rastro**, con su motivo y a cuántos se les avisó.
 */
class Actividades
{
    public function __construct(private NotificationService $avisos) {}

    // ============================================================ inscripción

    /**
     * Alguien se inscribe desde el sitio.
     *
     * La cuenta no se le exige: si ya la tiene con ese correo se usa, y si no,
     * nace aquí —igual que al inscribir a un preinscrito de Fab Academy—. La
     * inscripción queda colgada de una persona y no de un correo suelto, que
     * es lo que permite ver su historial de asistencia de una actividad a otra.
     *
     * @param  array{nombre:string, correo:string, programa:?string, tipo:string}  $fijos
     * @param  array<int|string, mixed>  $respuestas  id de la pregunta => valor o archivo
     *
     * @throws TrainingException
     */
    public function inscribir(CourseEdition $edicion, array $fijos, array $respuestas = [], ?User $sesion = null): Enrollment
    {
        $edicion->loadMissing('course');

        if (! $edicion->recibeInscripciones()) {
            throw new TrainingException($this->porQueNoRecibe($edicion));
        }

        $correo = mb_strtolower(trim($fijos['correo']));
        $persona = $sesion && mb_strtolower((string) $sesion->email) === $correo
            ? $sesion
            : User::whereRaw('lower(email) = ?', [$correo])->first();

        [$inscripcion, $posicion] = DB::transaction(function () use ($edicion, $fijos, $respuestas, $correo, $persona) {
            $fresca = CourseEdition::whereKey($edicion->id)->lockForUpdate()->first();

            $persona ??= $this->crearPersona($fijos['nombre'], $correo, $fijos['tipo']);

            $previa = Enrollment::where('course_edition_id', $fresca->id)->where('user_id', $persona->id)->first();

            if ($previa && $previa->status !== 'retirado') {
                throw new TrainingException($previa->enEspera()
                    ? 'Ya estás en la lista de espera de esta actividad con ese correo. Te escribimos si se libera un cupo.'
                    : 'Ya estás inscrito en esta actividad con ese correo.');
            }

            $hayCupo = $fresca->cuposLibres() > 0;

            $campos = [
                'status'           => $hayCupo ? 'inscrito' : Enrollment::EN_ESPERA,
                'participant_type' => $fijos['tipo'],
                'program'          => trim((string) ($fijos['programa'] ?? '')) ?: null,
                'answers'          => $this->guardarRespuestas($fresca, $respuestas, $fijos['tipo']),
                'source'           => 'web',
                'consent_at'       => now(),
                'enrolled_at'      => now(),
                'waitlisted_at'    => $hayCupo ? null : now(),
                'promoted_at'      => null,
                'withdrawn_at'     => null,
                'feedback'         => null,
            ];

            // Quien se retiró y vuelve reusa su fila: el índice único no deja
            // crear otra, y su historial es el mismo.
            $inscripcion = $previa
                ? tap($previa)->update($campos)
                : Enrollment::create(array_merge($campos, [
                    'course_edition_id' => $fresca->id,
                    'user_id'           => $persona->id,
                ]));

            return [$inscripcion->refresh(), $hayCupo ? null : $this->posicionEnEspera($inscripcion)];
        });

        $inscripcion->setRelation('edition', $edicion);

        $posicion === null
            ? $this->avisar('actividad.inscrito', $inscripcion)
            : $this->avisar('actividad.lista_espera', $inscripcion, ['posicion' => (string) $posicion]);

        return $inscripcion;
    }

    /** Qué puesto ocupa en la lista de espera, contando desde 1. */
    public function posicionEnEspera(Enrollment $inscripcion): int
    {
        return Enrollment::where('course_edition_id', $inscripcion->course_edition_id)
            ->where('status', Enrollment::EN_ESPERA)
            ->where(fn ($q) => $q->where('waitlisted_at', '<', $inscripcion->waitlisted_at)
                ->orWhere(fn ($q) => $q->where('waitlisted_at', $inscripcion->waitlisted_at)->where('id', '<=', $inscripcion->id)))
            ->count();
    }

    /**
     * Le da a alguien de la lista de espera el cupo que se liberó.
     *
     * Lo decide una persona y no el orden de llegada: a veces el cupo es para
     * quien sí trae el diseño, o para quien ya se quedó fuera la vez pasada.
     *
     * @throws TrainingException
     */
    public function darCupo(Enrollment $inscripcion, ?User $porQuien = null): Enrollment
    {
        if (! $inscripcion->enEspera()) {
            throw new TrainingException('Solo se le da cupo a quien está en lista de espera.');
        }

        $inscripcion = DB::transaction(function () use ($inscripcion, $porQuien) {
            $edicion = CourseEdition::whereKey($inscripcion->course_edition_id)->lockForUpdate()->first();

            if ($edicion->cuposLibres() <= 0) {
                throw new TrainingException('No hay cupos libres. Sube el cupo de la edición o espera a que alguien se retire.');
            }

            $inscripcion->update(['status' => 'inscrito', 'promoted_at' => now()]);

            $this->anotar($edicion, 'cupo_asignado', $porQuien, reason: $inscripcion->user?->name, notified: 1);

            return $inscripcion->refresh();
        });

        $this->avisar('actividad.cupo_asignado', $inscripcion);

        return $inscripcion;
    }

    /**
     * La persona cancela su propia inscripción, desde el enlace del correo.
     * Libera la silla y el equipo ve que hay un cupo para la lista de espera.
     */
    public function cancelarInscripcion(Enrollment $inscripcion, ?string $motivo = null): Enrollment
    {
        if ($inscripcion->status === 'retirado') {
            return $inscripcion;
        }

        if ($inscripcion->aprobada()) {
            throw new TrainingException('Esta inscripción ya se aprobó y no se puede cancelar.');
        }

        $inscripcion->update([
            'status'       => 'retirado',
            'withdrawn_at' => now(),
            'feedback'     => trim((string) $motivo) ?: 'Canceló desde el enlace del correo.',
        ]);

        return $inscripcion->refresh();
    }

    // ============================================================= el estado

    /** @throws TrainingException */
    public function publicar(CourseEdition $edicion, ?User $porQuien = null): CourseEdition
    {
        if (! in_array($edicion->status, ['planeada', 'inscripciones_cerradas'], true)) {
            throw new TrainingException('Solo se publica una edición planeada o con inscripciones cerradas.');
        }

        $reabre = $edicion->status === 'inscripciones_cerradas';

        $edicion->update([
            'status'       => 'abierta',
            'published_at' => $edicion->published_at ?? now(),
        ]);

        $this->anotar($edicion, $reabre ? 'reabierta' : 'publicada', $porQuien);

        return $edicion->refresh();
    }

    /**
     * Deja de recibir inscripciones, y también lista de espera. La página
     * sigue publicada: lo que se cerró es el formulario, no la actividad.
     *
     * @throws TrainingException
     */
    public function cerrarInscripciones(CourseEdition $edicion, ?User $porQuien = null, ?string $motivo = null): CourseEdition
    {
        if ($edicion->status !== 'abierta') {
            throw new TrainingException('Solo se cierran las inscripciones de una edición que las tenga abiertas.');
        }

        $edicion->update(['status' => 'inscripciones_cerradas']);
        $this->anotar($edicion, 'inscripciones_cerradas', $porQuien, reason: $motivo);

        return $edicion->refresh();
    }

    /**
     * Mover la actividad a otra fecha, hora o lugar.
     *
     * Se guarda lo que había y lo que queda, con el motivo, y se avisa con las
     * dos cosas: «era el sábado 4, ahora es el 11» se entiende; «ahora es el
     * 11» a secas deja a la gente preguntándose si leyó mal la primera vez.
     *
     * @param  array<string,mixed>  $nuevo  starts_on, ends_on, start_time, end_time, location, space_id
     * @return array{cambio: CourseEditionChange, avisados: int}
     *
     * @throws TrainingException
     */
    public function reprogramar(
        CourseEdition $edicion,
        array $nuevo,
        string $causa,
        string $motivo,
        bool $avisar = true,
        ?User $porQuien = null,
    ): array {
        if (in_array($edicion->status, ['cancelada', 'cerrada'], true)) {
            throw new TrainingException('Una edición ' . mb_strtolower(CourseEdition::ESTADOS[$edicion->status]) . ' no se reprograma.');
        }

        if (trim($motivo) === '') {
            throw new TrainingException('Escribe el motivo: es lo primero que va a preguntar quien reciba el aviso.');
        }

        $campos = ['starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'space_id'];
        $antes = $this->resumenDeFechas($edicion);

        $cambios = collect($nuevo)->only($campos)->all();
        $viejas = collect($campos)->mapWithKeys(fn ($c) => [$c => $edicion->getOriginal($c)])->all();

        $edicion->update($cambios);
        $edicion->refresh()->load('space');

        // Con una sola sesión, la sesión es la actividad: se mueve con ella.
        // Con varias, cada una se ajusta a mano; adivinar cuál se movió sería
        // peor que no tocarlas.
        if ($edicion->sessions()->count() === 1) {
            $this->moverLaSesionUnica($edicion);
        }

        $despues = $this->resumenDeFechas($edicion);

        $detalle = "Antes: {$antes}\nAhora: {$despues}\n\nMotivo: " . trim($motivo);

        $avisados = $avisar
            ? $this->avisarATodos($edicion, 'Reprogramada', $detalle)
            : 0;

        $cambio = $this->anotar($edicion, 'reprogramada', $porQuien, $causa, trim($motivo), $viejas, $cambios, $avisados);

        return ['cambio' => $cambio, 'avisados' => $avisados];
    }

    /**
     * Cancelar la actividad. Queda en el sitio diciendo que se canceló, y
     * nadie se borra: saber cuántos se habían apuntado sirve para la próxima.
     *
     * @return array{cambio: CourseEditionChange, avisados: int}
     *
     * @throws TrainingException
     */
    public function cancelar(CourseEdition $edicion, string $causa, string $motivo, bool $avisar = true, ?User $porQuien = null): array
    {
        if (in_array($edicion->status, ['cancelada', 'cerrada'], true)) {
            throw new TrainingException('Esta edición ya está ' . mb_strtolower(CourseEdition::ESTADOS[$edicion->status]) . '.');
        }

        if (trim($motivo) === '') {
            throw new TrainingException('Escribe el motivo: va en el aviso a los inscritos.');
        }

        $antes = $edicion->status;
        $edicion->update(['status' => 'cancelada', 'cancelled_at' => now()]);

        $avisados = $avisar
            ? $this->avisarATodos($edicion, 'Cancelada', 'La actividad se canceló.' . "\n\nMotivo: " . trim($motivo))
            : 0;

        $cambio = $this->anotar($edicion, 'cancelada', $porQuien, $causa, trim($motivo), ['status' => $antes], ['status' => 'cancelada'], $avisados);

        return ['cambio' => $cambio, 'avisados' => $avisados];
    }

    /**
     * Un aviso sin cambiar la fecha: el aire acondicionado no funciona y hay
     * que traer abrigo, la entrada es por la otra puerta, llegar 15 minutos
     * antes. Va a todos los inscritos y queda en el historial.
     *
     * @return array{cambio: CourseEditionChange, avisados: int}
     */
    public function avisarNovedad(CourseEdition $edicion, string $mensaje, ?string $causa = null, bool $incluirEspera = false, ?User $porQuien = null): array
    {
        if (trim($mensaje) === '') {
            throw new TrainingException('Escribe la novedad.');
        }

        $avisados = $this->avisarATodos($edicion, 'Novedad', trim($mensaje), $incluirEspera);
        $cambio = $this->anotar($edicion, 'novedad', $porQuien, $causa, trim($mensaje), notified: $avisados);

        return ['cambio' => $cambio, 'avisados' => $avisados];
    }

    /**
     * Otra edición igual, para abrir un grupo más: mismo curso, cupo,
     * público, precio y lugar. Nace planeada, sin fechas ni gente.
     */
    public function duplicarEdicion(CourseEdition $original, ?User $porQuien = null): CourseEdition
    {
        $copia = $original->replicate([
            'code', 'status', 'published_at', 'cancelled_at', 'survey_sent_at',
            'starts_on', 'ends_on', 'preenroll_until',
        ]);

        $copia->code = app(TrainingService::class)->siguienteCodigo();
        $copia->status = 'planeada';
        // La fecha es obligatoria en la base: se deja la misma para que quien
        // la duplicó la cambie, y el estado planeada evita que salga así.
        $copia->starts_on = $original->starts_on;
        $copia->ends_on = $original->ends_on;
        $copia->save();

        $this->anotar($copia, 'duplicada', $porQuien, reason: 'Duplicada de ' . $original->code, before: ['desde' => $original->code]);

        return $copia;
    }

    /**
     * Otra actividad a partir de esta: contenido, formulario y encuesta. Sin
     * ediciones: las fechas son de cada grupo.
     */
    public function duplicarCurso(Course $original): Course
    {
        return DB::transaction(function () use ($original) {
            $copia = $original->replicate();
            $copia->name = $original->name . ' (copia)';
            $copia->slug = $this->slugLibre($original->slug . '-copia');
            $copia->is_public = false;
            $copia->save();

            foreach ($original->registrationQuestions as $p) {
                $copia->registrationQuestions()->create($p->only(['position', 'type', 'label', 'help', 'options', 'required', 'participant_types']));
            }

            foreach ($original->surveyQuestions as $p) {
                $copia->surveyQuestions()->create($p->only(['position', 'type', 'label', 'options', 'required']));
            }

            $copia->riskFamilies()->sync($original->riskFamilies()->pluck('risk_families.id'));

            return $copia;
        });
    }

    // ============================================================ encuesta

    /**
     * Manda la encuesta a quienes vinieron, y solo a ellos: la opinión de
     * quien no asistió no es sobre la actividad.
     *
     * @return int a cuántos se les mandó
     *
     * @throws TrainingException
     */
    public function enviarEncuesta(CourseEdition $edicion, ?User $porQuien = null): int
    {
        $edicion->loadMissing('course');

        if (! $edicion->course?->surveyQuestions()->exists()) {
            throw new TrainingException('La actividad no tiene preguntas de encuesta. Agrégalas en el curso, sección Encuesta.');
        }

        $asistentes = $edicion->enrollments()
            ->whereNotIn('status', Enrollment::SIN_CUPO)
            ->whereHas('attendances', fn ($q) => $q->where('status', 'asistio'))
            ->whereDoesntHave('surveyResponse')
            ->with('user')
            ->get();

        if ($asistentes->isEmpty()) {
            throw new TrainingException('Nadie tiene asistencia registrada, o ya respondieron todos. La encuesta va solo a quien vino.');
        }

        $enviados = 0;

        foreach ($asistentes as $inscripcion) {
            $inscripcion->setRelation('edition', $edicion);

            if ($this->avisar('actividad.encuesta', $inscripcion, ['enlace' => $this->enlaceDeEncuesta($inscripcion)])) {
                $enviados++;
            }
        }

        $edicion->update(['survey_sent_at' => now()]);
        $this->anotar($edicion, 'encuesta', $porQuien, notified: $enviados);

        return $enviados;
    }

    public function enlaceDeEncuesta(Enrollment $inscripcion): string
    {
        return URL::temporarySignedRoute('encuesta', now()->addDays(60), ['enrollment' => $inscripcion->id]);
    }

    public function enlaceParaCancelar(Enrollment $inscripcion): string
    {
        return URL::signedRoute('inscripcion.cancelar', ['enrollment' => $inscripcion->id]);
    }

    // ============================================================ por dentro

    private function porQueNoRecibe(CourseEdition $edicion): string
    {
        return match ($edicion->status) {
            'planeada'  => 'Esta actividad todavía no abre inscripciones.',
            'cancelada' => 'Esta actividad se canceló.',
            'inscripciones_cerradas' => 'Las inscripciones de esta actividad están cerradas.',
            default     => 'Esta actividad ya no recibe inscripciones.',
        };
    }

    private function crearPersona(string $nombre, string $correo, string $tipo): User
    {
        $slug = Enrollment::TIPOS_DE_PARTICIPANTE[$tipo]['categoria'] ?? 'externo';

        return User::create([
            'name'             => trim($nombre),
            'email'            => $correo,
            'status'           => 'activo',
            'user_category_id' => UserCategory::where('slug', $slug)->value('id')
                ?? UserCategory::where('slug', 'invitado')->value('id'),
        ]);
    }

    /**
     * Las respuestas, con la pregunta copiada y los archivos ya guardados en
     * el disco privado: son el trabajo de alguien y no van a una URL pública.
     *
     * @param  array<int|string, mixed>  $respuestas
     * @return array<string, array<string, mixed>>
     */
    private function guardarRespuestas(CourseEdition $edicion, array $respuestas, string $tipo): array
    {
        $guardadas = [];

        /** @var RegistrationQuestion $pregunta */
        foreach ($edicion->course->registrationQuestions as $pregunta) {
            if (! $pregunta->aplicaA($tipo)) {
                continue;
            }

            $valor = $respuestas[$pregunta->id] ?? null;
            $fila = ['pregunta' => $pregunta->label, 'tipo' => $pregunta->type, 'valor' => null];

            if ($valor instanceof UploadedFile) {
                $ruta = $valor->store('inscripciones/' . $edicion->id, 'local');
                $fila['valor'] = $valor->getClientOriginalName();
                $fila['archivo'] = ['ruta' => $ruta, 'nombre' => $valor->getClientOriginalName()];
            } elseif (is_array($valor)) {
                $fila['valor'] = array_values(array_filter($valor, fn ($v) => $v !== null && $v !== ''));
            } elseif ($pregunta->type === 'aceptacion') {
                $fila['valor'] = $valor ? 'Aceptó' : null;
            } else {
                $fila['valor'] = $valor === null ? null : trim((string) $valor);
            }

            $guardadas[(string) $pregunta->id] = $fila;
        }

        return $guardadas;
    }

    /**
     * A quienes tienen cupo —y, si se pide, a la lista de espera—, con la
     * novedad y cómo queda la actividad.
     */
    private function avisarATodos(CourseEdition $edicion, string $novedad, string $detalle, bool $incluirEspera = true): int
    {
        $estados = $incluirEspera ? ['inscrito', Enrollment::EN_ESPERA] : ['inscrito'];

        $avisados = 0;

        $edicion->enrollments()->whereIn('status', $estados)->with('user')->get()
            ->each(function (Enrollment $i) use ($edicion, $novedad, $detalle, &$avisados) {
                $i->setRelation('edition', $edicion);

                if ($this->avisar('actividad.novedad', $i, ['novedad' => $novedad, 'detalle' => $detalle])) {
                    $avisados++;
                }
            });

        return $avisados;
    }

    /** @return bool si salió */
    private function avisar(string $clave, Enrollment $inscripcion, array $datos = []): bool
    {
        $edicion = $inscripcion->edition()->with(['course', 'space'])->first() ?? $inscripcion->edition;
        $persona = $inscripcion->user;

        if (! $persona) {
            return false;
        }

        $curso = $edicion->course;

        $datos = array_merge([
            'actividad' => $edicion->nombre(),
            'tipo'      => mb_strtolower($curso?->tipoLegible() ?? 'actividad'),
            'fecha'     => $edicion->fechas() ?? 'por definir',
            'horario'   => $edicion->horario() ?? 'por definir',
            'lugar'     => $edicion->lugar() ?? 'por definir',
            'costo'     => $edicion->precioLegible()
                ? 'Valor: ' . $edicion->precioLegible() . ($edicion->payment_info ? "\n" . $edicion->payment_info : '')
                : '',
            'llevar'    => $curso?->materials_to_bring ? 'Qué traer: ' . $curso->materials_to_bring : '',
            'enlace'    => $edicion->url(),
            'cancelar'  => $this->enlaceParaCancelar($inscripcion),
            'posicion'  => '',
            'novedad'   => '',
            'detalle'   => '',
        ], $datos);

        $log = $this->avisos->enviar($clave, $persona, $datos, $inscripcion);

        return $log?->status === 'enviado';
    }

    /** @param  array<string,mixed>|null  $before */
    private function anotar(
        CourseEdition $edicion,
        string $tipo,
        ?User $porQuien = null,
        ?string $cause = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        int $notified = 0,
    ): CourseEditionChange {
        return $edicion->changes()->create([
            'user_id'  => $porQuien?->id ?? auth()->id(),
            'kind'     => $tipo,
            'cause'    => $cause,
            'reason'   => $reason,
            'before'   => $before,
            'after'    => $after,
            'notified' => $notified,
        ]);
    }

    /** «Sábado 4 de octubre de 2026, 09:00 a 12:00, en el Auditorio». */
    private function resumenDeFechas(CourseEdition $edicion): string
    {
        return collect([$edicion->fechas(), $edicion->horario(), $edicion->lugar() ? 'en ' . $edicion->lugar() : null])
            ->filter()
            ->implode(', ') ?: 'por definir';
    }

    private function moverLaSesionUnica(CourseEdition $edicion): void
    {
        if (! $edicion->starts_on) {
            return;
        }

        $tz = config('fabos.lab.timezone');
        $dia = $edicion->starts_on->format('Y-m-d');

        /** @var CourseSession $sesion */
        $sesion = $edicion->sessions()->first();
        $horaInicio = $edicion->start_time
            ? substr((string) $edicion->start_time, 0, 5)
            : $sesion->starts_at->copy()->timezone($tz)->format('H:i');

        $inicio = Carbon::parse($dia . ' ' . $horaInicio, $tz);
        $fin = $edicion->end_time ? Carbon::parse($dia . ' ' . substr((string) $edicion->end_time, 0, 5), $tz) : null;

        $sesion->update(['starts_at' => $inicio, 'ends_at' => $fin]);
    }

    private function slugLibre(string $base): string
    {
        $slug = Str::slug($base);
        $n = 1;

        while (Course::where('slug', $slug)->exists()) {
            $slug = Str::slug($base) . '-' . (++$n);
        }

        return $slug;
    }
}
