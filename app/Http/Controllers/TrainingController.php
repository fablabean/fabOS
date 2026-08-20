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
