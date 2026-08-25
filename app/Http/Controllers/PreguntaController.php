<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Question;
use App\Models\User;
use App\Services\Ia\SugerenciaDeRespuesta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Preguntas del laboratorio (§20).
 *
 * Lo que hoy se resuelve en un pasillo se responde una vez y queda para quien
 * pregunte lo mismo dentro de un mes.
 *
 * Leer es público —el conocimiento del laboratorio no tiene por qué estar tras
 * una puerta— pero preguntar exige cuenta: sin eso, esto se convierte en un
 * buzón de spam en una semana.
 */
class PreguntaController extends Controller
{
    public function index(Request $request)
    {
        $busqueda = trim((string) $request->query('q', ''));

        return view('preguntas.index', [
            'preguntas' => Question::query()
                ->with(['user', 'area', 'asset'])
                ->withCount('respuestasPublicadas')
                ->when($busqueda !== '', fn ($q) => $q->buscar($busqueda))
                ->when($busqueda === '', fn ($q) => $q->latest())
                ->when($request->filled('area'), fn ($q) => $q->where('area_id', $request->integer('area')))
                ->when($request->query('estado') === 'sin_responder',
                    fn ($q) => $q->where('status', 'abierta'))
                ->paginate(20)
                ->withQueryString(),
            'busqueda' => $busqueda,
            'areas'    => Area::orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, Question $question)
    {
        // Contar sin tocar `updated_at`: una visita no es una edición.
        DB::table('questions')->where('id', $question->id)->increment('vistas');

        return view('preguntas.show', [
            'pregunta'  => $question->load(['user', 'area', 'asset']),
            'respuestas' => $question->respuestasPublicadas()->with('user')->get(),
            // Los borradores solo los ve quien puede aprobarlos.
            'borradores' => $this->puedeResponder($request)
                ? $question->answers()->where('publicada', false)->with('user')->get()
                : collect(),
            'parecidas' => Question::parecidas($question->title, 4, $question->id),
            'ia'         => app(SugerenciaDeRespuesta::class),
        ]);
    }

    public function create(Request $request)
    {
        return view('preguntas.create', [
            'areas'  => Area::orderBy('name')->get(),
            'equipos' => Asset::where('is_public', true)->orderBy('name')->get(),
            // Mientras escribe el título ya se le enseña lo parecido: casi
            // siempre la duda está resuelta, y verlo antes de publicar ahorra
            // responderla otra vez.
            'parecidas' => $request->filled('titulo')
                ? Question::parecidas((string) $request->query('titulo'))
                : collect(),
            'titulo' => (string) $request->query('titulo', ''),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'title'    => ['required', 'string', 'min:10', 'max:200'],
            'body'     => ['required', 'string', 'min:20', 'max:5000'],
            'area_id'  => ['nullable', 'exists:areas,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
        ]);

        $pregunta = Question::create($datos + ['user_id' => $request->user()->id]);

        return redirect()->route('preguntas.show', $pregunta)->with(
            'status',
            'Tu pregunta quedó publicada. Te avisamos cuando alguien del laboratorio la responda.'
        );
    }

    // ------------------------------------------------------------ responder

    public function responder(Request $request, Question $question)
    {
        abort_unless($this->puedeResponder($request), 403);

        $datos = $request->validate([
            'body'      => ['required', 'string', 'min:10', 'max:10000'],
            'borrador'  => ['nullable', 'integer', 'exists:answers,id'],
        ]);

        // Si venía de un borrador, se edita ese en vez de crear otro: así el
        // origen «ia» se conserva aunque el texto lo haya reescrito una persona.
        $respuesta = isset($datos['borrador'])
            ? $question->answers()->findOrFail($datos['borrador'])
            : $question->answers()->make(['origen' => Answer::PERSONA]);

        $respuesta->fill([
            'body'         => $datos['body'],
            'user_id'      => $respuesta->user_id ?? $request->user()->id,
            'publicada'    => true,
            'publicada_at' => now(),
            'aprobada_por' => $request->user()->id,
        ])->save();

        $question->update(['status' => 'respondida']);

        return back()->with('status', 'Respuesta publicada.');
    }

    /**
     * Pide un borrador a la IA.
     *
     * A peticion y no automatico: se gasta solo en las preguntas que alguien va
     * a responder de verdad, y quien pregunta no espera diez segundos al
     * publicar.
     */
    public function sugerir(Request $request, Question $question, SugerenciaDeRespuesta $ia)
    {
        abort_unless($this->puedeResponder($request), 403);

        if (! $ia->disponible()) {
            return back()->with('status', 'La sugerencia automatica esta apagada.');
        }

        if ($ia->quedanHoy() < 1) {
            return back()->with('status', 'Se agoto el cupo de sugerencias de hoy.');
        }

        $borrador = $ia->para($question);

        return back()->with('status', $borrador
            ? 'Borrador listo. Revisalo, corrigelo si hace falta, y publicalo.'
            : 'No se pudo redactar el borrador. Quedo anotado el motivo en la bitacora.');
    }

    private function puedeResponder(Request $request): bool
    {
        return $request->user()?->hasAnyRole([
            User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN,
        ]) ?? false;
    }
}
