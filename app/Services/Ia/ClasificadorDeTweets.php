<?php

namespace App\Services\Ia;

use App\Services\TweetsEnVivo;
use Illuminate\Support\Facades\Http;

/**
 * La IA metida dentro del programa: recibe un mensaje corto y devuelve un
 * tono, un hashtag y una respuesta de una línea. Corre en el servidor de IA
 * del FabLab, con una respuesta en JSON que el programa puede leer.
 */
class ClasificadorDeTweets
{
    public const INSTRUCCIONES = <<<'TXT'
        Eres la IA que vive dentro del muro de mensajes del Bootcamp AGENTIC, en el FabLab de la Universidad Ean.
        Recibes un mensaje corto (como un tweet) de una persona del bootcamp.
        Responde SOLO con un objeto JSON, sin texto antes ni después, con estas tres claves:
        - "tono": una de estas palabras exactas: entusiasmo, curiosidad, duda, preocupación, humor, otro.
        - "hashtag": un hashtag inventado y corto, en español, en CamelCase, que resuma el mensaje (por ejemplo "#CaféYCódigo").
        - "respuesta": una frase de máximo 15 palabras, cálida y con humor ligero, que le contestarías a esa persona.
        Reglas: nunca te burles de nadie, no inventes datos sobre la persona y no uses emojis.
        Si el mensaje es ofensivo o no tiene sentido, usa "otro" y responde con amabilidad y neutralidad.
        TXT;

    public function clasificar(string $texto): ?array
    {
        if (! config('fabos.servidor_fablab.activo') || blank(config('fabos.servidor_fablab.url'))) {
            return null;
        }

        $r = Http::baseUrl((string) config('fabos.servidor_fablab.url'))
            ->timeout(60)
            ->post('/api/chat', [
                'model' => (string) config('fabos.servidor_fablab.modelo_asistente', 'qwen3.5:9b'),
                'stream' => false,
                'think' => false,
                'format' => 'json',
                'options' => ['temperature' => 0.7],
                'messages' => [
                    ['role' => 'system', 'content' => self::INSTRUCCIONES],
                    ['role' => 'user', 'content' => $texto],
                ],
            ]);
        if (! $r->successful()) {
            return null;
        }

        return self::limpiar(json_decode((string) $r->json('message.content'), true));
    }

    /** Lo que devolvió el modelo, revisado: nada fuera de lo esperado llega a las pantallas. */
    public static function limpiar(mixed $j): ?array
    {
        if (! is_array($j)) {
            return null;
        }
        $tono = mb_strtolower(trim((string) ($j['tono'] ?? '')));
        $tono = $tono === 'preocupacion' ? 'preocupación' : $tono;
        $hashtag = preg_replace('/[^\p{L}\p{N}]/u', '', (string) ($j['hashtag'] ?? ''));
        $respuesta = trim(strip_tags((string) ($j['respuesta'] ?? '')));

        return [
            'tono' => in_array($tono, TweetsEnVivo::TONOS, true) ? $tono : 'otro',
            'hashtag' => $hashtag !== '' ? '#' . mb_substr($hashtag, 0, 30) : '',
            'respuesta' => mb_substr($respuesta, 0, 160),
        ];
    }
}
