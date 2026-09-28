<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Services\Training\EncuestaDeActividad;
use App\Services\Training\TrainingException;
use Illuminate\Http\Request;

/**
 * La encuesta de después, por el enlace firmado del correo (§9).
 *
 * Sin sesión: nadie entra a su cuenta para contestar cinco preguntas. El
 * enlace es de esa inscripción y de nadie más, y vence a los dos meses.
 */
class EncuestaController extends Controller
{
    public function __construct(private EncuestaDeActividad $encuestas) {}

    public function show(Request $request, Enrollment $enrollment)
    {
        $enrollment->load(['edition.course.surveyQuestions', 'user', 'surveyResponse']);

        return view('formacion.encuesta', [
            'inscripcion' => $enrollment,
            'edicion'     => $enrollment->edition,
            'preguntas'   => $enrollment->edition->course->surveyQuestions,
            'respondida'  => $enrollment->surveyResponse !== null,
            'asistio'     => $enrollment->asistio(),
            'accion'      => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, Enrollment $enrollment)
    {
        $enrollment->load('edition.course.surveyQuestions');

        try {
            $this->encuestas->responder($enrollment, (array) $request->input('r', []));
        } catch (TrainingException $e) {
            return back()->withInput()->withErrors(['encuesta' => $e->getMessage()]);
        }

        return back()->with('gracias', true);
    }
}
