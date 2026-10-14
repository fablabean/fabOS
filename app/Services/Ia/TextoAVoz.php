<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El servidor de texto a voz del FabLab (§3, anuncios con voz).
 *
 * Un servidor con GPU en la red del laboratorio, con la API de voz de OpenAI:
 * se le manda el texto y la voz, y responde con el MP3. No pide clave desde
 * dentro, y desde fuera no se ve: por eso esto solo funciona con fabOS en la
 * red del FabLab.
 *
 * A diferencia de las ilustraciones, aquí un fallo SÍ se lanza: quien llama es
 * un trabajo de la cola, que reintenta y anota el motivo en el anuncio. Un
 * `null` callado dejaría el anuncio esperando un audio que nadie va a pedir.
 */
class TextoAVoz
{
    public function estaActivo(): bool
    {
        return filled(config('fabos.voz.url'));
    }

    /**
     * El MP3 de este texto, en bytes.
     *
     * @throws RuntimeException si el servidor no contesta o no devuelve audio.
     */
    public function mp3(string $texto, string $voz): string
    {
        if (! $this->estaActivo()) {
            throw new RuntimeException('No está configurado el servidor de voz (FABLAB_VOZ_URL).');
        }

        $respuesta = Http::timeout((int) config('fabos.voz.timeout'))
            ->connectTimeout(10)
            ->post(rtrim(config('fabos.voz.url'), '/') . '/v1/audio/speech', [
                'input' => $texto,
                'voice' => $voz,
                'response_format' => 'mp3',
            ]);

        if ($respuesta->failed()) {
            throw new RuntimeException('El servidor de voz respondió ' . $respuesta->status() . '.');
        }

        $audio = $respuesta->body();

        // Un 200 con una página de error dentro —un proxy, un portal cautivo de
        // la red— se guardaría como un MP3 que no suena. Se mira el tipo.
        if ($audio === '' || ! str_starts_with((string) $respuesta->header('Content-Type'), 'audio/')) {
            throw new RuntimeException('El servidor de voz no devolvió audio.');
        }

        return $audio;
    }
}
