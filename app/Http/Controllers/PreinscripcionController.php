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
    public function fabAcademy(Request $request)
    {
        $curso = Course::deFabAcademy();

        abort_unless($curso !== null, 404);

        // «No soy yo»: se olvida el correo y se vuelve a preguntar.
        if ($request->boolean('otro')) {
            $request->session()->forget(self::CORREO);

            return redirect()->to(route('fab-academy') . '#preinscripcion');
        }

        return $this->show($curso);
    }

    /** Dónde se recuerda el correo que alguien escribió sin sesión. */
    private const CORREO = 'fab_academy.correo';

    /**
     * Con la cohorte abierta, lo primero es el correo: si ya está en la lista,
     * lo que sigue es el registro oficial; si no, la preinscripción.
     *
     * Se recuerda en la sesión y nada más: decir si un correo está en la
     * lista es lo único que se revela, y el enlace al que lleva es público.
     */
    public function correo(Request $request)
    {
        $datos = $request->validate(['correo' => ['required', 'email', 'max:160']], [
            'correo.required' => 'Escribe tu correo.',
            'correo.email'    => 'Ese correo no parece completo.',
        ]);

        $request->session()->put(self::CORREO, mb_strtolower(trim($datos['correo'])));

        return redirect()->to(route('fab-academy') . '#preinscripcion');
    }

    public function show(Course $course)
    {
        $this->debeSerVisible($course);

        // La dirección larga de Fab Academy lleva a la corta: es la que se
        // comparte, y la larga quedó con una vista previa vieja en WhatsApp.
        if (request()->routeIs('preinscripcion') && $course->urlDePreinscripcion() !== route('preinscripcion', $course)) {
            return redirect()->to($course->urlDePreinscripcion(), 301);
        }

        $cohorte = $course->cohortePorAbrir();

        $esFabAcademy = $course->level === 'tera';
        $pagina = $esFabAcademy ? \App\Support\PaginaFabAcademy::contenido() : null;

        // La que ya abrió, si la hay: a quien llega tarde se le manda a
        // inscribirse de verdad en vez de decirle que no hay nada.
        $abierta = $course->edicionesAbiertas()->first();

        // Salvo que el registro de verdad sea afuera (Fab Academy): entonces
        // la abierta sigue siendo la cohorte de esta página, con su conteo.
        $registro = null;

        if ($abierta?->conRegistroExterno()) {
            $cohorte = $abierta;
            $abierta = null;
            $registro = $this->registroExterno($cohorte, $pagina['registro']);
        }

        // Fab Academy tiene página propia, con lo que se carga en
        // Formación → Página Fab Academy; el resto usa la de siempre.
        return view($esFabAcademy ? 'formacion.fab-academy' : 'formacion.preinscripcion', [
            'pagina'   => $pagina,
            'curso'    => $course->load('riskFamilies'),
            'cohorte'  => $cohorte,
            'abierta'  => $abierta,
            'registro' => $registro,
            'esFabAcademy' => $esFabAcademy,
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
        $abierta = $course->edicionesAbiertas()->first();
        $conRegistroExterno = ! $cohorte && $abierta?->conRegistroExterno();

        if ($conRegistroExterno) {
            $cohorte = $abierta;
        }

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

        // Con la cohorte abierta se vuelve a la página: ahí dice si ya se
        // puede ir al registro oficial o cuánto falta.
        if ($conRegistroExterno) {
            $request->session()->put(self::CORREO, mb_strtolower(trim($datos['correo'])));

            return redirect()->to(route('fab-academy') . '#preinscripcion')
                ->with('status', 'Gracias, ' . trim($datos['nombre']) . ': quedaste preinscrito.');
        }

        return redirect()
            ->route('preinscripcion.gracias', $course)
            ->with('nombre', trim($datos['nombre']));
    }

    /**
     * Lo que necesita la página para el paso del registro oficial: de quién
     * es el correo, si ya está en la lista y si ya puede pasar.
     *
     * @param  array{url:string,condiciones:?string}  $config
     * @return array<string,mixed>
     */
    private function registroExterno(\App\Models\CourseEdition $cohorte, array $config): array
    {
        $yo = auth()->user();
        $correo = $yo ? mb_strtolower((string) $yo->email) : session(self::CORREO);

        $mia = $correo
            ? $cohorte->preenrollments()->vivos()
                ->where(fn ($q) => $q->where('email', $correo)->when($yo, fn ($q) => $q->orWhere('user_id', $yo->id)))
                ->first()
            : null;

        return [
            'url'         => $config['url'],
            'condiciones' => \App\Support\PaginaFabAcademy::lineas($config['condiciones'] ?? null),
            'correo'      => $correo,
            'conSesion'   => (bool) $yo,
            'mia'         => $mia,
            'habilitado'  => $mia && $cohorte->habilitaElRegistro($mia),
        ];
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
