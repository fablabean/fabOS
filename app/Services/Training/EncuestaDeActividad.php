<?php

namespace App\Services\Training;

use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use Illuminate\Support\Collection;

/**
 * La encuesta de después: responder y leer los resultados (§9).
 *
 * Las preguntas son del curso y las respuestas de cada edición. Así se lee un
 * grupo solo —«el del sábado salió peor»— o la actividad entera a lo largo de
 * sus grupos, que es lo que dice si hay que cambiar algo del curso.
 */
class EncuestaDeActividad
{
    /**
     * Guarda lo que respondió una persona. Una sola vez: el segundo envío no
     * suma, porque una persona que responde dos veces pesa doble.
     *
     * @param  array<int|string, mixed>  $valores  id de la pregunta => valor
     *
     * @throws TrainingException
     */
    public function responder(Enrollment $inscripcion, array $valores): SurveyResponse
    {
        if ($inscripcion->surveyResponse()->exists()) {
            throw new TrainingException('Ya respondiste esta encuesta. ¡Gracias!');
        }

        if (! $inscripcion->asistio()) {
            throw new TrainingException('La encuesta es para quienes asistieron a la actividad.');
        }

        $preguntas = $inscripcion->edition->course->surveyQuestions;
        $respuestas = [];

        /** @var SurveyQuestion $p */
        foreach ($preguntas as $p) {
            $valor = $valores[$p->id] ?? null;
            $valor = is_string($valor) ? trim($valor) : $valor;

            if ($p->required && ($valor === null || $valor === '')) {
                throw new TrainingException('Falta responder: «' . $p->label . '».');
            }

            if ($p->type === 'escala' && $valor !== null && $valor !== '') {
                $valor = (int) $valor;

                if ($valor < 1 || $valor > 5) {
                    throw new TrainingException('La escala va de 1 a 5.');
                }
            }

            $respuestas[(string) $p->id] = ['pregunta' => $p->label, 'tipo' => $p->type, 'valor' => $valor === '' ? null : $valor];
        }

        return SurveyResponse::create([
            'course_edition_id' => $inscripcion->course_edition_id,
            'enrollment_id'     => $inscripcion->id,
            'answers'           => $respuestas,
            'submitted_at'      => now(),
        ]);
    }

    /**
     * Los resultados, pregunta por pregunta.
     *
     * Escala: promedio y reparto de 1 a 5. Selección y sí/no: cuántos por
     * opción. Abiertas: las respuestas, tal cual.
     *
     * @return array{respuestas: int, asistentes: int, preguntas: list<array<string,mixed>>, satisfaccion: ?float}
     */
    public function resultados(Course $curso, ?CourseEdition $edicion = null): array
    {
        $respuestas = SurveyResponse::query()
            ->when($edicion, fn ($q) => $q->where('course_edition_id', $edicion->id))
            ->when(! $edicion, fn ($q) => $q->whereIn('course_edition_id', $curso->editions()->select('id')))
            ->get();

        $asistentes = Enrollment::query()
            ->when($edicion, fn ($q) => $q->where('course_edition_id', $edicion->id))
            ->when(! $edicion, fn ($q) => $q->whereIn('course_edition_id', $curso->editions()->select('id')))
            ->whereHas('attendances', fn ($q) => $q->where('status', 'asistio'))
            ->count();

        $preguntas = [];
        $promediosDeEscala = [];

        foreach ($curso->surveyQuestions as $p) {
            $valores = $respuestas
                ->map(fn (SurveyResponse $r) => $r->answers[(string) $p->id]['valor'] ?? null)
                ->filter(fn ($v) => $v !== null && $v !== '');

            $fila = ['id' => $p->id, 'pregunta' => $p->label, 'tipo' => $p->type, 'respondieron' => $valores->count()];

            if ($p->type === 'escala') {
                $fila['promedio'] = $valores->isEmpty() ? null : round($valores->avg(), 2);
                $fila['reparto'] = collect(range(1, 5))->mapWithKeys(fn ($n) => [$n => $valores->filter(fn ($v) => (int) $v === $n)->count()])->all();

                if ($fila['promedio'] !== null) {
                    $promediosDeEscala[] = $fila['promedio'];
                }
            } elseif ($p->type === 'texto') {
                $fila['textos'] = $valores->values()->all();
            } else {
                $fila['reparto'] = $this->conteo($p->opciones(), $valores);
            }

            $preguntas[] = $fila;
        }

        return [
            'respuestas'   => $respuestas->count(),
            'asistentes'   => $asistentes,
            'preguntas'    => $preguntas,
            // La satisfacción en una cifra: el promedio de las escalas.
            'satisfaccion' => $promediosDeEscala ? round(array_sum($promediosDeEscala) / count($promediosDeEscala), 2) : null,
        ];
    }

    /** @return array<string,int> */
    private function conteo(array $opciones, Collection $valores): array
    {
        $conteo = array_fill_keys($opciones, 0);

        foreach ($valores as $v) {
            $v = (string) $v;
            $conteo[$v] = ($conteo[$v] ?? 0) + 1;
        }

        return $conteo;
    }
}
