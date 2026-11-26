<?php

namespace App\Http\Controllers;

use App\Services\Ia\AccesoAlAsistente;
use App\Services\Ia\AsistenteDelBootcamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El asistente del Bootcamp AGENTIC: una página y el chat que la alimenta.
 * La conversación vive en el navegador; aquí no se guarda.
 *
 * Entra quien tenga su ID del bootcamp, con una hora de trabajo al día
 * (ver AccesoAlAsistente). El ID queda en la sesión del navegador.
 */
class AsistenteAgenticController extends Controller
{
    public const SESION = 'asistente_agentic_codigo';

    public function pagina(Request $request, AsistenteDelBootcamp $asistente, AccesoAlAsistente $acceso)
    {
        $p = $acceso->participante($request->session()->get(self::SESION));

        return view('asistente.agentic', [
            'instrucciones' => AsistenteDelBootcamp::INSTRUCCIONES,
            'modelo' => $asistente->modelo(),
            'participante' => $p,
            'hoy' => $p ? $acceso->hoy($p->codigo) : null,
            'minutos' => $acceso->minutosPorDia(),
            'activo' => $acceso->activo(),
        ]);
    }

    /** Entrar con el ID del bootcamp. */
    public function entrar(Request $request, AccesoAlAsistente $acceso): RedirectResponse
    {
        $p = $acceso->participante((string) $request->input('codigo'));
        if (! $p) {
            return back()->withInput()->with('error_id', 'No encontré ese ID. Son 4 letras o números: los recibiste al entrar a la presentación del bootcamp.');
        }
        $request->session()->regenerate();
        $request->session()->put(self::SESION, $p->codigo);

        return redirect()->route('asistente.agentic');
    }

    public function salir(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESION);

        return redirect()->route('asistente.agentic');
    }

    public function mensaje(Request $request, AsistenteDelBootcamp $asistente, AccesoAlAsistente $acceso): StreamedResponse|JsonResponse
    {
        // Antes de gastar el servidor de IA: quién es, si está prendido y si le queda tiempo.
        $p = $acceso->participante($request->session()->get(self::SESION));
        if (! $p) {
            return response()->json(['error' => 'Entra con tu ID del bootcamp para usar el asistente.', 'sin_id' => true], 403);
        }
        if (! $acceso->activo()) {
            return response()->json(['error' => 'El asistente está apagado por ahora. Quien dicta el bootcamp lo vuelve a prender.'], 403);
        }
        $hoy = $acceso->hoy($p->codigo);
        if ($hoy['agotado']) {
            return response()->json(['error' => 'Ya usaste tu hora de hoy (' . $acceso->minutosPorDia() . ' min). Mañana vuelves a tener tu tiempo.', 'agotado' => true], 403);
        }

        $datos = $request->validate([
            'mensajes' => ['required', 'array', 'min:1', 'max:40'],
            'mensajes.*.role' => ['required', 'in:user,assistant'],
            'mensajes.*.content' => ['required', 'string', 'max:8000'],
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
        $acceso->anotar($p->codigo);
        $quedan = $acceso->hoy($p->codigo)['quedan'];

        return new StreamedResponse(function () use ($asistente, $mensajes, $quedan) {
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
            $enviar(['estado' => 'pensando', 'quedan' => $quedan]);

            try {
                $asistente->conversar($mensajes->all(), fn (string $t) => $enviar(['texto' => $t]));
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
