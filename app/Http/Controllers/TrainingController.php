<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Illuminate\Http\Request;

/**
 * La formación vista desde fuera (§9).
 *
 * El catálogo es público —es la vitrina de lo que enseña el laboratorio— pero
 * inscribirse exige sesión: un cupo se le asigna a alguien concreto.
 */
class TrainingController extends Controller
{
    public function __construct(private TrainingService $formacion) {}

    public function index()
    {
        $cursos = Course::query()
            ->with(['area', 'riskFamilies', 'edicionesAbiertas'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderByRaw("array_position(ARRAY['bit','byte','kilo','mega','giga','tera'], level)")
            ->orderBy('name')
            ->get();

        return view('publico.formacion', [
            'cursos' => $cursos,
            // Lo que la persona ya tiene, para no ofrecerle inscribirse dos veces.
            'misInscripciones' => auth()->check()
                ? Enrollment::where('user_id', auth()->id())
                    ->whereNot('status', 'retirado')
                    ->pluck('status', 'course_edition_id')
                : collect(),
        ]);
    }

    /**
     * La teoria de un curso, pantalla a pantalla.
     *
     * Se lee con la inscripcion hecha: no es secreto, pero el avance se guarda
     * contra una inscripcion, y sin ella no hay donde apuntar que esta persona
     * ya paso por aqui.
     */
    public function teoria(Request $request, Enrollment $enrollment, int $numero = 1)
    {
        abort_unless($enrollment->user_id === $request->user()->id, 403);

        $curso = $enrollment->edition?->course;
        $lecciones = $curso?->lessons ?? collect();

        abort_if($lecciones->isEmpty(), 404);

        // Fuera de rango se lleva a la primera en vez de reventar: una
        // direccion escrita a mano no deberia romper la pagina.
        $indice = max(1, min($numero, $lecciones->count()));

        return view('formacion.teoria', [
            'inscripcion' => $enrollment,
            'curso'       => $curso,
            'leccion'     => $lecciones[$indice - 1],
            'numero'      => $indice,
            'cuantas'     => $lecciones->count(),
        ]);
    }

    /** El examen: las preguntas, sin la respuesta correcta. */
    public function examen(Request $request, Enrollment $enrollment)
    {
        abort_unless($enrollment->user_id === $request->user()->id, 403);

        $curso = $enrollment->edition?->course;

        abort_unless($curso?->tieneExamen(), 404);

        return view('formacion.examen', [
            'inscripcion' => $enrollment,
            'curso'       => $curso,
            // Sin `correct` ni `explanation`: la respuesta buena no puede viajar
            // hasta la pantalla de quien se esta examinando.
            'preguntas'   => $curso->questions->map(fn ($p) => [
                'id'       => $p->id,
                'prompt'   => $p->prompt,
                'options'  => $p->options,
                'material' => $p->material(),
            ]),
        ]);
    }

    public function calificar(Request $request, Enrollment $enrollment)
    {
        abort_unless($enrollment->user_id === $request->user()->id, 403);

        $datos = $request->validate([
            'respuestas'   => ['required', 'array'],
            'respuestas.*' => ['nullable', 'integer'],
        ]);

        try {
            $resultado = $this->formacion->calificarExamen($enrollment, $datos['respuestas']);
        } catch (TrainingException $e) {
            return back()->withErrors(['examen' => $e->getMessage()]);
        }

        return view('formacion.resultado', [
            'inscripcion' => $enrollment->fresh(),
            'curso'       => $enrollment->edition?->course,
            'resultado'   => $resultado,
        ]);
    }

    public function inscribir(Request $request, CourseEdition $edition)
    {
        try {
            $this->formacion->inscribir($edition, $request->user());
        } catch (TrainingException $e) {
            return back()->withErrors(['inscripcion' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Quedaste inscrito. Te llegó un correo con la fecha y el horario.'
        );
    }

    public function retirar(Request $request, Enrollment $enrollment)
    {
        abort_unless($enrollment->user_id === $request->user()->id, 403);

        try {
            $this->formacion->retirar($enrollment, 'Retirado por la persona');
        } catch (TrainingException $e) {
            return back()->withErrors(['inscripcion' => $e->getMessage()]);
        }

        return back()->with('status', 'Liberaste tu cupo. Gracias por avisar.');
    }
}
