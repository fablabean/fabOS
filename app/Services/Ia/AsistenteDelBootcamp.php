<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El tutor del Bootcamp AGENTIC, en el servidor de IA del FabLab.
 *
 * Sirve para el trabajo autónomo: acompaña sin hacer el trabajo, y deja ver
 * por qué la IA asiste y no reemplaza. Corre en el PGX del laboratorio (no en
 * un servicio de afuera) y no guarda las conversaciones: el navegador manda
 * la conversación entera en cada mensaje y aquí no queda nada escrito.
 */
class AsistenteDelBootcamp
{
    /**
     * Las instrucciones del asistente. La página las muestra tal cual: parte
     * de lo que se aprende es ver qué se le pidió a la IA.
     */
    public const INSTRUCCIONES = <<<'TXT'
Eres el asistente del Bootcamp AGENTIC del FabLab EAN (Universidad Ean, Bogotá). Acompañas el trabajo autónomo de personas que están llevando un problema real de su trabajo o su vida a una solución con IA. Algunas solo han chateado con una IA; otras ya programan un poco.

Lo que ya vieron en el bootcamp:
- Día 1: reconocer bien el problema, un objetivo principal, separar lo imprescindible de lo deseable, y su archivo PROYECTO.md hecho con la IA del editor (VS Code con Claude Code, Gemini o Codex).
- Día 2: el ciclo Planear → Pedir → Revisar → Probar → Guardar, y retos en paralelo.
- Día 3 (mañana o ya pasó): llamar la IA por API con una llave en .env, y el concepto de agente (modelo + herramientas + bucle + objetivo).

Cómo ayudas:
1. No haces el trabajo por la persona. Preguntas, ayudas a pensar, das pistas, ejemplos cortos y revisas lo que ella hizo. Si pide el programa completo, primero pregúntale qué intentó o qué quiere lograr, y propón el siguiente paso pequeño que puede pedirle a la IA de su editor.
2. Respuestas cortas: máximo 120 palabras, salvo que te pidan revisar algo largo. Una idea a la vez. Termina casi siempre con una pregunta o con el siguiente paso concreto.
3. Cuando revises un error, explica qué significa en palabras simples y qué revisar primero. No adivines: si falta información, pide el mensaje exacto.
4. Ayuda a escribir buenos pedidos (prompts) para la IA del editor: con contexto, objetivo, límites y cómo probar.
5. Cuando venga al caso, haz ver qué le toca a la persona y qué a la IA: ella conoce el problema y a las personas, decide qué es correcto, prueba con datos reales y responde por el resultado; la IA acelera, propone borradores, explica y revisa, pero se equivoca con seguridad y no sabe lo que no le cuentan.
6. Seguridad: si alguien pega una llave de API, una contraseña o datos personales de otras personas, dile que no lo haga, que la llave va solo en el archivo .env y que si ya la compartió la revoque y cree otra.
7. Si te preguntan algo fuera del bootcamp, responde breve y vuelve a su proyecto.
8. Escribe en español de Colombia, cercano y claro, sin jerga innecesaria. Si usas un término técnico, explícalo en una frase.
TXT;

    public function configurado(): bool
    {
        return (bool) config('fabos.servidor_fablab.activo') && filled(config('fabos.servidor_fablab.url'));
    }

    public function modelo(): string
    {
        return (string) config('fabos.servidor_fablab.modelo_asistente', 'qwen3.5:9b');
    }

    /**
     * Le pasa la conversación al modelo y entrega el texto a pedazos, según
     * llega: así quien pregunta ve la respuesta formarse y Cloudflare no corta
     * una petición que tarda.
     *
     * @param  list<array{role:string,content:string}>  $mensajes
     * @param  callable(string):void  $alRecibir
     */
    public function conversar(array $mensajes, string $nivel, callable $alRecibir): void
    {
        $sistema = self::INSTRUCCIONES . "\n\nNivel que eligió la persona: "
            . ($nivel === 'inter' ? 'intermedio (ya programa un poco: puedes ser más directo y técnico).' : 'básico (explica todo con palabras simples y pasos clic a clic).');

        $cliente = Http::baseUrl((string) config('fabos.servidor_fablab.url'))
            ->timeout((int) config('fabos.servidor_fablab.timeout', 120))
            ->withOptions(['stream' => true]);

        if (filled($clave = config('fabos.servidor_fablab.clave'))) {
            $cliente = $cliente->withToken($clave);
        }

        $r = $cliente->post('/api/chat', [
            'model' => $this->modelo(),
            'stream' => true,
            'think' => false,
            'keep_alive' => config('fabos.servidor_fablab.mantener_cargado', '30m'),
            'options' => ['temperature' => 0.4, 'num_ctx' => 8192],
            'messages' => array_merge([['role' => 'system', 'content' => $sistema]], $mensajes),
        ]);

        if (! $r->successful()) {
            throw new RuntimeException('El servidor de IA respondió ' . $r->status() . '.');
        }

        $cuerpo = $r->toPsrResponse()->getBody();
        $pendiente = '';

        while (! $cuerpo->eof()) {
            $pendiente .= $cuerpo->read(1024);

            while (($corte = strpos($pendiente, "\n")) !== false) {
                $linea = trim(substr($pendiente, 0, $corte));
                $pendiente = substr($pendiente, $corte + 1);
                $this->leerLinea($linea, $alRecibir);
            }
        }

        $this->leerLinea(trim($pendiente), $alRecibir);
    }

    private function leerLinea(string $linea, callable $alRecibir): void
    {
        if ($linea === '') {
            return;
        }

        $dato = json_decode($linea, true);
        if (! is_array($dato)) {
            return;
        }
        if (isset($dato['error'])) {
            throw new RuntimeException((string) $dato['error']);
        }
        if (filled($texto = $dato['message']['content'] ?? null)) {
            $alRecibir($texto);
        }
    }

    /**
     * Tapa lo que parece una llave de API antes de que salga del navegador
     * hacia el modelo: si alguien la pega por error, no viaja.
     */
    public static function taparLlaves(string $texto): string
    {
        return preg_replace(
            '/\b(sk-ant-[A-Za-z0-9_\-]{10,}|sk-(proj-)?[A-Za-z0-9_\-]{20,}|AIza[0-9A-Za-z_\-]{30,})\b/',
            '[LLAVE TAPADA]',
            $texto,
        ) ?? $texto;
    }

    public static function pareceLlave(string $texto): bool
    {
        return self::taparLlaves($texto) !== $texto;
    }
}
