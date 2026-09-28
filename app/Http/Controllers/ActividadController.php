<?php

namespace App\Http\Controllers;

use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\RegistrationQuestion;
use App\Models\User;
use App\Services\Projects\SoportesDeSolicitud;
use App\Services\Training\Actividades;
use App\Services\Training\TrainingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * La página de un curso, taller o evento, y su inscripción (§9).
 *
 * Como la de Fab Academy: la información y el formulario en la misma página,
 * sin cuenta. Lo que cambia es que aquí sí hay cupo, y por eso la página dice
 * cuántos quedan y, cuando se acaban, ofrece la lista de espera en vez de
 * cerrar la puerta.
 */
class ActividadController extends Controller
{
    public function __construct(private Actividades $actividades) {}

    public function show(Request $request, CourseEdition $edition)
    {
        $vistaPrevia = $this->debeSerVisible($request, $edition);

        $edition->load(['course.registrationQuestions', 'space']);

        return view('formacion.actividad', [
            'edicion'     => $edition,
            'curso'       => $edition->course,
            'vistaPrevia' => $vistaPrevia,
            'libres'      => $edition->cuposLibres(),
            'enEspera'    => $edition->enEspera(),
            'tipos'       => $edition->tiposDeParticipante(),
        ]);
    }

    public function store(Request $request, CourseEdition $edition)
    {
        abort_unless($edition->estaPublicada(), 404);

        $edition->load('course.registrationQuestions');
        $tipos = array_keys($edition->tiposDeParticipante());
        $tipo = (string) $request->input('tipo');

        [$reglas, $mensajes, $nombres] = $this->reglasDePreguntas($edition, $tipo);

        $datos = $request->validate(array_merge([
            'nombre'    => ['required', 'string', 'min:3', 'max:160'],
            'correo'    => ['required', 'email', 'max:160'],
            'programa'  => ['required', 'string', 'max:160'],
            'tipo'      => ['required', Rule::in($tipos)],
            'acepta'    => ['accepted'],
            'sitio_web' => ['prohibited'],
        ], $reglas), array_merge([
            'nombre.required'   => 'Escribe tu nombre y apellidos.',
            'programa.required' => 'Dinos tu programa académico, dependencia o área.',
            'tipo.required'     => 'Elige qué tipo de participante eres.',
            'tipo.in'           => 'Esta actividad no está abierta a ese tipo de participante.',
            'acepta.accepted'   => 'Para inscribirte tienes que aceptar las condiciones de inscripción y cancelación.',
            'sitio_web.prohibited' => 'No pudimos procesar el formulario.',
        ], $mensajes), $nombres);

        // Quien dice ser de la Universidad se identifica con su correo de la
        // Universidad: es lo que evita que el cupo reservado a la comunidad lo
        // tome cualquiera marcando una casilla.
        if (in_array($tipo, Enrollment::CON_CORREO_INSTITUCIONAL, true)
            && filled(config('fabos.identity.institutional_domain'))
            && ! User::correoInstitucional($datos['correo'])) {
            return back()->withInput()->withErrors([
                'correo' => 'Como ' . mb_strtolower(Enrollment::TIPOS_DE_PARTICIPANTE[$tipo]['nombre'])
                    . ', inscríbete con tu correo institucional (@' . config('fabos.identity.institutional_domain') . ').',
            ]);
        }

        $respuestas = [];

        foreach ($edition->course->registrationQuestions as $p) {
            $respuestas[$p->id] = $p->type === 'archivo'
                ? $request->file('p' . $p->id)
                : $request->input('p' . $p->id);
        }

        try {
            $inscripcion = $this->actividades->inscribir($edition, [
                'nombre'   => $datos['nombre'],
                'correo'   => $datos['correo'],
                'programa' => $datos['programa'],
                'tipo'     => $tipo,
            ], $respuestas, $request->user());
        } catch (TrainingException $e) {
            return back()->withInput()->withErrors(['actividad' => $e->getMessage()]);
        }

        return redirect()
            ->route('actividad.listo', $edition->code)
            ->with('inscripcion', $inscripcion->id);
    }

    /** Quedó inscrito o en lista de espera, dicho sin ambigüedad. */
    public function listo(CourseEdition $edition)
    {
        $inscripcion = session('inscripcion') ? Enrollment::find(session('inscripcion')) : null;

        if (! $inscripcion || $inscripcion->course_edition_id !== $edition->id) {
            return redirect()->route('actividad', $edition->code);
        }

        $edition->load(['course', 'space']);

        return view('formacion.actividad-listo', [
            'edicion'     => $edition,
            'inscripcion' => $inscripcion,
            'posicion'    => $inscripcion->enEspera() ? $this->actividades->posicionEnEspera($inscripcion) : null,
        ]);
    }

    public function cancelar(Enrollment $enrollment)
    {
        $enrollment->load(['edition.course', 'edition.space', 'user']);

        return view('formacion.actividad-cancelar', [
            'inscripcion' => $enrollment,
            'edicion'     => $enrollment->edition,
            'accion'      => URL::signedRoute('inscripcion.cancelar.confirmar', ['enrollment' => $enrollment->id]),
        ]);
    }

    public function confirmarCancelacion(Request $request, Enrollment $enrollment)
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        try {
            $this->actividades->cancelarInscripcion($enrollment, $datos['motivo'] ?? null);
        } catch (TrainingException $e) {
            return back()->withErrors(['motivo' => $e->getMessage()]);
        }

        return back()->with('cancelada', true);
    }

    // ------------------------------------------------------------ por dentro

    /**
     * Publicada, o vista previa para quien la está preparando en el panel.
     *
     * @return bool si es vista previa
     */
    private function debeSerVisible(Request $request, CourseEdition $edition): bool
    {
        if ($edition->estaPublicada() && $edition->course?->is_active) {
            return false;
        }

        $quien = $request->user();

        abort_unless(
            $quien && $quien->status === 'activo' && $quien->puedeEnLaSeccion('ver', \App\Support\Secciones::claveDe(\App\Filament\Resources\CourseEditions\CourseEditionResource::class)),
            404,
        );

        return true;
    }

    /**
     * Las reglas de las preguntas de la actividad, solo las que le tocan a
     * ese tipo de participante: a un externo no se le exige el código de
     * estudiante.
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>, 2: array<string,string>}
     */
    private function reglasDePreguntas(CourseEdition $edition, string $tipo): array
    {
        $reglas = [];
        $mensajes = [];
        $nombres = [];

        /** @var RegistrationQuestion $p */
        foreach ($edition->course->registrationQuestions as $p) {
            $campo = 'p' . $p->id;
            $nombres[$campo] = '«' . $p->label . '»';

            if (! $p->aplicaA($tipo)) {
                continue;
            }

            $base = $p->required ? ['required'] : ['nullable'];

            $reglas[$campo] = match ($p->type) {
                'parrafo'    => [...$base, 'string', 'max:3000'],
                'seleccion'  => [...$base, Rule::in($p->opciones())],
                'multiple'   => [...$base, 'array'],
                'aceptacion' => $p->required ? ['accepted'] : ['nullable'],
                'archivo'    => [...$base, 'file', 'max:' . SoportesDeSolicitud::TAMANO_MAXIMO, 'extensions:' . implode(',', RegistrationQuestion::EXTENSIONES)],
                'numero'     => [...$base, 'numeric'],
                'fecha'      => [...$base, 'date'],
                'enlace'     => [...$base, 'url', 'max:500'],
                default      => [...$base, 'string', 'max:500'],
            };

            if ($p->type === 'multiple') {
                $reglas[$campo . '.*'] = [Rule::in($p->opciones())];
            }

            $mensajes[$campo . '.required'] = match ($p->type) {
                'archivo' => 'Adjunta ' . lcfirst($p->label) . ': es obligatorio para inscribirse.',
                default   => 'Falta responder: «' . $p->label . '».',
            };
            $mensajes[$campo . '.accepted'] = 'Para inscribirte tienes que aceptar: «' . $p->label . '».';
            $mensajes[$campo . '.extensions'] = 'El archivo de «' . $p->label . '» debe ser uno de estos formatos: ' . implode(', ', RegistrationQuestion::EXTENSIONES) . '.';
        }

        return [$reglas, $mensajes, $nombres];
    }
}
