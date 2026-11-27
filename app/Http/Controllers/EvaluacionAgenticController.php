<?php

namespace App\Http\Controllers;

use App\Models\EvaluacionBootcamp;
use App\Models\ParticipanteEnVivo;
use App\Support\EvaluacionAgentic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La evaluación del Bootcamp AGENTIC: enlace libre, sin cuenta. Se escribe el
 * nombre (y el ID del bootcamp, si se tiene), se responde y se ve el resultado
 * con la explicación de cada pregunta. Cada envío queda guardado.
 */
class EvaluacionAgenticController extends Controller
{
    public function pagina()
    {
        return view('asistente.evaluacion', ['preguntas' => EvaluacionAgentic::paraLaPagina()]);
    }

    public function responder(Request $request): JsonResponse
    {
        $total = count(EvaluacionAgentic::preguntas());
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:80'],
            'codigo' => ['nullable', 'string', 'max:10'],
            'respuestas' => ['required', 'array', 'max:' . $total],
            'respuestas.*' => ['nullable', 'string', 'in:a,b,c,d'],
        ], [
            'nombre.required' => 'Escribe tu nombre.',
            'nombre.min' => 'Escribe tu nombre.',
            'respuestas.required' => 'Responde las preguntas antes de enviar.',
        ]);

        // El ID solo se guarda si existe: así el resultado queda con la persona.
        $codigo = ParticipanteEnVivo::limpiarCodigo($datos['codigo'] ?? null);
        if ($codigo && ! ParticipanteEnVivo::where('codigo', $codigo)->exists()) {
            $codigo = null;
        }

        $respuestas = array_map(fn ($i) => $datos['respuestas'][$i] ?? null, range(0, $total - 1));
        $resultado = EvaluacionAgentic::calificar($respuestas);

        $e = EvaluacionBootcamp::create([
            'grupo' => EvaluacionAgentic::GRUPO,
            'nombre' => trim(preg_replace('/\s+/', ' ', $datos['nombre'])),
            'codigo' => $codigo,
            'respuestas' => $respuestas,
            'aciertos' => $resultado['aciertos'],
            'total' => $resultado['total'],
        ]);

        return response()->json($resultado + ['nota' => $e->nota(), 'codigo' => $codigo]);
    }
}
