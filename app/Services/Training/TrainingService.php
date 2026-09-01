<?php

namespace App\Services\Training;

use App\Models\Certifab;
use App\Models\CourseQuestion;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Formación (§9).
 *
 * Aquí ocurre lo que conecta la formación con el resto del sistema: **aprobar
 * una edición otorga los certifabs del curso**. Hasta ahora la única vía de
 * habilitarse era una asesoría uno a uno, que no escala; un curso con quince
 * personas habilita a quince a la vez, y cada una queda con su certificado
 * verificable.
 *
 * Cuatro invariantes:
 *
 *  1. **El cupo se respeta.** Sobreinscribir no es un detalle administrativo:
 *     significa gente de pie en un taller con máquinas.
 *  2. **Nadie se inscribe dos veces** en la misma edición.
 *  3. **Aprobar es idempotente.** Volver a aprobar no emite otro certificado ni
 *     duplica certifabs.
 *  4. **Retirarse libera el cupo**, y no deja certificado.
 */
class TrainingService
{
    public function __construct(private NotificationService $avisos) {}

    /**
     * Inscribe a alguien en una edición.
     *
     * @throws TrainingException si no admite inscripciones o ya está inscrito
     */
    public function inscribir(CourseEdition $edicion, User $persona): Enrollment
    {
        $edicion->loadMissing('course');

        if ($edicion->status !== 'abierta') {
            throw new TrainingException(
                'Esta edición está ' . mb_strtolower(CourseEdition::ESTADOS[$edicion->status] ?? $edicion->status)
                . ' y no admite inscripciones.'
            );
        }

        return DB::transaction(function () use ($edicion, $persona) {
            // Se relee bloqueando: dos personas pulsando «inscribirme» a la vez
            // podrían leer el mismo cupo libre y tomar ambas la última silla.
            $fresca = CourseEdition::whereKey($edicion->id)->lockForUpdate()->first();

            if ($fresca->cuposLibres() <= 0) {
                throw new TrainingException('Ya no quedan cupos en esta edición.');
            }

            $previa = Enrollment::where('course_edition_id', $fresca->id)
                ->where('user_id', $persona->id)
                ->first();

            if ($previa && $previa->status !== 'retirado') {
                throw new TrainingException('Ya estás inscrito en esta edición.');
            }

            // Quien se retiró y vuelve reusa su inscripción: el índice único no
            // deja crear otra, y tampoco haría falta.
            $inscripcion = $previa
                ? tap($previa)->update(['status' => 'inscrito', 'enrolled_at' => now()])
                : Enrollment::create([
                    'course_edition_id' => $fresca->id,
                    'user_id'           => $persona->id,
                    'status'            => 'inscrito',
                    'enrolled_at'       => now(),
                ]);

            $this->avisos->enviar('curso.inscripcion', $persona, [
                'curso'   => $edicion->course?->name ?? 'el curso',
                'inicio'  => $fresca->starts_on?->format('d/m/Y') ?? 'por definir',
                'horario' => $fresca->schedule_note ?? '',
                'lugar'   => $fresca->space?->name ?? '',
            ], $inscripcion);

            return $inscripcion->refresh();
        });
    }

    /** Retirarse libera el cupo para otra persona. */
    public function retirar(Enrollment $inscripcion, ?string $motivo = null): Enrollment
    {
        if ($inscripcion->aprobada()) {
            throw new TrainingException('Una inscripción ya aprobada no se retira.');
        }

        $inscripcion->update(['status' => 'retirado', 'feedback' => $motivo]);

        return $inscripcion->refresh();
    }

    /**
     * Aprueba a alguien: emite su certificado y le otorga los certifabs.
     *
     * @throws TrainingException
     */
    public function aprobar(Enrollment $inscripcion, ?float $nota = null, ?User $porQuien = null): Enrollment
    {
        if ($inscripcion->status === 'retirado') {
            throw new TrainingException('Esa persona se retiró de la edición.');
        }

        /*
         * El certifab no se firma antes de tiempo.
         *
         * Dice que esa persona puede usar la maquina sin nadie al lado. Si se
         * pudiera otorgar saltandose el examen o la practica, la palabra
         * dejaria de significar eso —y quien la lea despues no tendria como
         * saber cuales si pasaron por ahi—.
         */
        if ($falta = $inscripcion->queFaltaParaAprobar()) {
            throw new TrainingException($falta);
        }

        if ($inscripcion->aprobada()) {
            return $inscripcion;   // ya estaba: volver a aprobar no emite otro
        }

        return DB::transaction(function () use ($inscripcion, $nota, $porQuien) {
            $inscripcion->update([
                'status'           => 'aprobado',
                'grade'            => $nota,
                'certificate_code' => $inscripcion->certificate_code ?? Enrollment::nuevoCodigo(),
                'completed_at'     => now(),
            ]);

            $inscripcion->refresh();
            $otorgados = $this->otorgarCertifabs($inscripcion, $porQuien);

            $this->avisos->enviar('curso.aprobado', $inscripcion->user, [
                'curso'    => $inscripcion->edition?->course?->name ?? 'el curso',
                'codigo'   => $inscripcion->certificate_code,
                'enlace'   => route('publico.verificar', $inscripcion->certificate_code),
                'habilita' => $otorgados->isEmpty()
                    ? ''
                    : 'Con esto quedas habilitado para usar: ' . $otorgados->implode(', ') . '.',
            ], $inscripcion);

            return $inscripcion;
        });
    }

    public function reprobar(Enrollment $inscripcion, ?float $nota = null, ?string $comentario = null): Enrollment
    {
        if ($inscripcion->aprobada()) {
            throw new TrainingException('Esa inscripción ya fue aprobada.');
        }

        $inscripcion->update([
            'status'   => 'reprobado',
            'grade'    => $nota,
            'feedback' => $comentario,
        ]);

        return $inscripcion->refresh();
    }

    /**
     * Aprueba de una vez a quien siga inscrito y cierra la edición.
     *
     * Es el caso normal de un taller corto: todo el mundo asistió y pasó. Quien
     * no debía aprobar se marca antes, uno por uno.
     */
    public function cerrarEdicion(CourseEdition $edicion, ?User $porQuien = null): int
    {
        $pendientes = $edicion->enrollments()->where('status', 'inscrito')->get();

        foreach ($pendientes as $inscripcion) {
            $this->aprobar($inscripcion, porQuien: $porQuien);
        }

        $edicion->update(['status' => 'cerrada']);

        return $pendientes->count();
    }

    /**
     * Otorga los certifabs del curso, sin duplicar los que ya tenga.
     *
     * @return \Illuminate\Support\Collection<int,string> nombres de lo habilitado
     */
    private function otorgarCertifabs(Enrollment $inscripcion, ?User $porQuien): \Illuminate\Support\Collection
    {
        $curso = $inscripcion->edition?->course;

        if (! $curso) {
            return collect();
        }

        $otorgados = collect();

        foreach ($curso->riskFamilies as $familia) {
            $vigente = Certifab::where('user_id', $inscripcion->user_id)
                ->where('risk_family_id', $familia->id)
                ->whereNull('revoked_at')
                ->first();

            // Ya habilitado: si el curso da más nivel, se sube; si no, se deja.
            if ($vigente) {
                if ($this->nivelMayor($curso->level, $vigente->level)) {
                    $vigente->update(['level' => $curso->level, 'granted_via' => 'curso']);
                    $otorgados->push($familia->name);
                }

                continue;
            }

            Certifab::create([
                'user_id'        => $inscripcion->user_id,
                'risk_family_id' => $familia->id,
                'level'          => $curso->level,
                'granted_by'     => $porQuien?->id ?? $inscripcion->edition?->instructor_id,
                'granted_via'    => 'curso',
                'granted_at'     => now(),
                'notes'          => 'Otorgado al aprobar ' . $curso->name
                    . ' (' . $inscripcion->edition?->code . ')',
            ]);

            $otorgados->push($familia->name);
        }

        return $otorgados;
    }

    /** Compara dos niveles según la escalera bit → tera. */
    private function nivelMayor(string $candidato, string $actual): bool
    {
        $escalera = Certifab::NIVELES;

        return array_search($candidato, $escalera, true) > array_search($actual, $escalera, true);
    }

    /** FOR-2026-0001: legible por teléfono y ordenable. */
    public function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = CourseEdition::where('code', 'like', "FOR-{$ano}-%")->max('code');

        return sprintf('FOR-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
    }

    /**
     * Corrige el examen teorico y guarda el resultado.
     *
     * Se corrige aqui y no en el navegador: la respuesta correcta no puede
     * viajar hasta la pantalla de quien esta examinandose.
     *
     * @param  array<int,int>  $respuestas  id de la pregunta => opcion elegida
     * @return array{nota:int, aprobado:bool, fallos:\Illuminate\Support\Collection}
     */
    public function calificarExamen(Enrollment $inscripcion, array $respuestas): array
    {
        $curso = $inscripcion->edition?->course;

        if (! $curso || ! $curso->tieneExamen()) {
            throw new TrainingException('Este curso no tiene examen.');
        }

        if ($inscripcion->status === 'retirado') {
            throw new TrainingException('Esa persona se retiró de la edición.');
        }

        $preguntas = $curso->questions;

        $aciertos = $preguntas->filter(
            fn (CourseQuestion $p) => $p->esCorrecta($respuestas[$p->id] ?? null),
        );

        $nota = (int) round($aciertos->count() / max(1, $preguntas->count()) * 100);
        $aprobado = $nota >= (int) $curso->passing_score;

        $inscripcion->update([
            'theory_score'     => $nota,
            'theory_attempts'  => $inscripcion->theory_attempts + 1,
            // La fecha se guarda solo al aprobar, y no se pisa: es la de la vez
            // que se logro, no la del ultimo intento.
            'theory_passed_at' => $inscripcion->theory_passed_at
                ?? ($aprobado ? now() : null),
        ]);

        return [
            'nota'     => $nota,
            'aprobado' => $aprobado,
            // Lo que fallo, con su explicacion: un examen que solo dice «mal»
            // enseña a adivinar, no a operar la maquina.
            'fallos'   => $preguntas->reject(
                fn (CourseQuestion $p) => $p->esCorrecta($respuestas[$p->id] ?? null),
            )->values(),
        ];
    }

    /**
     * Firma la evaluacion presencial.
     *
     * La hace una persona, delante de la maquina: una pantalla no puede ver si
     * alguien nivela una cama o si sabe parar la impresion cuando algo va mal.
     */
    public function registrarPractica(
        Enrollment $inscripcion,
        User $quienEvalua,
        ?string $notas = null,
    ): Enrollment {
        if ($inscripcion->status === 'retirado') {
            throw new TrainingException('Esa persona se retiró de la edición.');
        }

        $curso = $inscripcion->edition?->course;

        // El orden no es ceremonia: la practica se evalua sobre lo que la
        // teoria ya explico, y firmarla antes deja al evaluador improvisando
        // que preguntar.
        if ($curso?->tieneExamen() && ! $inscripcion->teoriaAprobada()) {
            throw new TrainingException(
                'Todavía no ha aprobado el examen teórico: la práctica se evalúa sobre eso.'
            );
        }

        $inscripcion->update([
            'practical_passed_at' => now(),
            'practical_by'        => $quienEvalua->id,
            'practical_notes'     => $notas,
        ]);

        return $inscripcion->refresh();
    }

    /** Familias de riesgo que una persona tendría habilitadas al aprobar. */
    public function habilitaria(CourseEdition $edicion): \Illuminate\Support\Collection
    {
        return $edicion->course?->riskFamilies->pluck('name') ?? collect();
    }
}
