<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Preenrollment;
use App\Services\Training\PreinscripcionService;
use App\Services\Training\TrainingException;
use App\Support\Telefono;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preinscribirse a una cohorte desde el sitio (§9).
 *
 * La página hace dos trabajos a la vez y por eso es una sola: **convence** —qué
 * es el programa, por qué aquí— y **cuenta** —cuántos somos, cuántos faltan—.
 * Separarlas deja el formulario a un clic de distancia de la razón para
 * llenarlo, y cada clic de más es gente que no llega.
 *
 * No exige cuenta, igual que postularse a una práctica (§5): decir «me
 * interesa» no debería costar un registro.
 */
class PreinscripcionController extends Controller
{
    public function __construct(private PreinscripcionService $preinscripciones) {}

    /**
     * `/fab-academy`: la dirección que se dice en voz alta y cabe en un afiche.
     * Lleva al curso tera que entra por preinscripción; si no hay ninguno
     * publicado, no hay página que enseñar.
     */
    public function fabAcademy()
    {
        $curso = Course::query()
            ->where('level', 'tera')
            ->where('by_preenrollment', true)
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('id')
            ->first();

        abort_unless($curso !== null, 404);

        return $this->show($curso);
    }

    public function show(Course $course)
    {
        $this->debeSerVisible($course);

        $cohorte = $course->cohortePorAbrir();

        return view('formacion.preinscripcion', [
            'curso'    => $course->load('riskFamilies'),
            'cohorte'  => $cohorte,
            // La que ya abrió, si la hay: a quien llega tarde se le manda a
            // inscribirse de verdad en vez de decirle que no hay nada.
            'abierta'  => $course->edicionesAbiertas()->first(),
            'esFabAcademy' => $course->level === 'tera',
            'yaEstoy'  => $cohorte && auth()->check()
                ? $cohorte->preenrollments()->vivos()
                    ->where(fn ($q) => $q->where('user_id', auth()->id())
                        ->orWhere('email', mb_strtolower((string) auth()->user()->email)))
                    ->exists()
                : false,
        ]);
    }

    public function store(Request $request, Course $course)
    {
        $this->debeSerVisible($course);

        $datos = $request->validate([
            'nombre'       => ['required', 'string', 'max:160'],
            'correo'       => ['required', 'email', 'max:160'],
            'telefono'     => ['nullable', 'string', 'max:40'],
            'telefono_indicativo' => ['nullable', 'string', 'max:6'],
            'ciudad'       => ['required', 'string', 'max:120'],
            'ocupacion'    => ['required', 'string', 'max:160'],
            'institucion'  => ['nullable', 'string', 'max:160'],
            'motivacion'   => ['required', 'string', 'min:20', 'max:2000'],
            'portafolio'   => ['nullable', 'url', 'max:255'],
            'financiacion' => ['required', Rule::in(array_keys(Preenrollment::FINANCIACION))],

            // Ley 1581 de 2012: sin autorización no se guarda nada de esto.
            'autoriza'     => ['accepted'],

            // Trampa para robots: un campo que nadie ve y nadie debería llenar.
            'sitio_web'    => ['prohibited'],
        ], [
            'ciudad.required'       => 'Dinos desde dónde vendrías: el programa es presencial en el laboratorio.',
            'ocupacion.required'    => 'Cuéntanos a qué te dedicas.',
            'motivacion.required'   => 'Cuéntanos por qué quieres hacer el programa.',
            'motivacion.min'        => 'Cuéntanos un poco más: con dos líneas no sabemos qué buscas.',
            'financiacion.required' => 'Dinos cómo piensas financiarlo: es lo que nos dice si la cohorte es viable.',
            'autoriza.accepted'     => 'Necesitamos tu autorización para guardar tus datos y escribirte.',
            'sitio_web.prohibited'  => 'No pudimos procesar el formulario.',
        ]);

        $cohorte = $course->cohortePorAbrir();

        if (! $cohorte) {
            return back()->withInput()->withErrors([
                'cohorte' => 'Ahora mismo no hay una cohorte recibiendo preinscripciones.',
            ]);
        }

        try {
            $this->preinscripciones->preinscribir($cohorte, [
                'name'          => trim($datos['nombre']),
                'email'         => $datos['correo'],
                'phone'         => Telefono::componer($datos['telefono_indicativo'] ?? null, $datos['telefono'] ?? null),
                'city'          => trim($datos['ciudad']),
                'occupation'    => trim($datos['ocupacion']),
                'institution'   => $datos['institucion'] ?? null,
                'motivation'    => $datos['motivacion'],
                'portfolio_url' => $datos['portafolio'] ?? null,
                'funding'       => $datos['financiacion'],
            ], cuenta: $this->cuentaDe($request, $datos['correo']));
        } catch (TrainingException $e) {
            return back()->withInput()->withErrors(['cohorte' => $e->getMessage()]);
        }

        return redirect()
            ->route('preinscripcion.gracias', $course)
            ->with('nombre', trim($datos['nombre']));
    }

    public function gracias(Course $course)
    {
        $this->debeSerVisible($course);

        return view('formacion.preinscripcion-gracias', [
            'curso'   => $course,
            'cohorte' => $course->cohortePorAbrir(),
        ]);
    }

    /** Solo lo que está en la vitrina y entra por preinscripción. */
    private function debeSerVisible(Course $course): void
    {
        abort_unless($course->is_active && $course->is_public && $course->by_preenrollment, 404);
    }

    /**
     * La sesión solo cuenta si el correo es el suyo: quien, con su sesión
     * abierta, preinscribe a un compañero no debe quedarse con la
     * preinscripción del otro colgada de su cuenta.
     */
    private function cuentaDe(Request $request, string $correo): ?\App\Models\User
    {
        $yo = $request->user();

        return $yo && mb_strtolower((string) $yo->email) === mb_strtolower(trim($correo)) ? $yo : null;
    }
}
