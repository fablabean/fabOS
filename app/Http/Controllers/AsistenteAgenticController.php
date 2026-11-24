<?php

namespace App\Http\Controllers;

use App\Services\Ia\AsistenteDelBootcamp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El asistente del Bootcamp AGENTIC: una página pública y el chat que la
 * alimenta. La conversación vive en el navegador; aquí no se guarda.
 */
class AsistenteAgenticController extends Controller
{
    public function pagina(AsistenteDelBootcamp $asistente)
    {
        return view('asistente.agentic', [
            'instrucciones' => AsistenteDelBootcamp::INSTRUCCIONES,
            'modelo' => $asistente->modelo(),
        ]);
    }

    public function mensaje(Request $request, AsistenteDelBootcamp $asistente): StreamedResponse
    {
        $datos = $request->validate([
            'mensajes' => ['required', 'array', 'min:1', 'max:40'],
            'mensajes.*.role' => ['required', 'in:user,assistant'],
            'mensajes.*.content' => ['required', 'string', 'max:8000'],
            'nivel' => ['nullable', 'in:basico,inter'],
        ]);

        // Lo más viejo se suelta primero: el modelo trabaja con unas 8 mil
        // palabras de contexto y la pregunta de ahora es la que importa.
        $mensajes = collect($datos['mensajes'])
            ->map(fn (array $m) => ['role' => $m['role'], 'content' => AsistenteDelBootcamp::taparLlaves($m['content'])])
            ->values();
        while ($mensajes->count() > 1 && $mensajes->sum(fn ($m) => mb_strlen($m['content'])) > 20000) {
            $mensajes->shift();
        }
        abort_unless($mensajes->last()['role'] === 'user', 422);

        return new StreamedResponse(function () use ($asistente, $mensajes, $datos) {
            $enviar = function (array $evento) {
                echo 'data: ' . json_encode($evento, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            if (! $asistente->configurado()) {
                $enviar(['error' => 'El servidor de IA del FabLab no está disponible en este momento.']);

                return;
            }

            // Que el navegador sepa enseguida que alguien está pensando.
            $enviar(['estado' => 'pensando']);

            try {
                $asistente->conversar($mensajes->all(), $datos['nivel'] ?? 'basico', fn (string $t) => $enviar(['texto' => $t]));
                $enviar(['fin' => true]);
            } catch (\Throwable $e) {
                Log::warning('Asistente AGENTIC: ' . $e->getMessage());
                $enviar(['error' => 'El servidor de IA del FabLab no respondió. Intenta de nuevo en un momento.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
