<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Las cámaras del laboratorio: cuáles hay y su cuadro más reciente.
 *
 * El navegador no sabe leer RTSP, y la cámara no debe verse desde fuera. Así
 * que el servidor, que está en la misma red, le pide a la cámara un cuadro con
 * ffmpeg y se lo pasa al panel como una foto. Pidiendo una foto cada dos
 * segundos se ve casi en vivo sin montar un servidor de video.
 *
 * El cuadro se guarda un segundo y medio: si hay tres personas mirando, la
 * cámara recibe una conexión y no tres.
 */
class Camaras
{
    /** @return list<array{numero:int,nombre:string,url:string}> */
    public static function todas(): array
    {
        return config('fabos.camaras', []);
    }

    /** @return array{numero:int,nombre:string,url:string}|null */
    public static function una(int $numero): ?array
    {
        return collect(self::todas())->firstWhere('numero', $numero);
    }

    /** El JPEG del momento, o null si la cámara no responde. */
    public static function foto(int $numero): ?string
    {
        $camara = self::una($numero);

        if (! $camara) {
            return null;
        }

        $guardada = Cache::get("camara.{$numero}.foto");

        if ($guardada !== null) {
            return base64_decode($guardada);
        }

        // Un solo ffmpeg por cámara a la vez: el que llega mientras otro está
        // pidiendo, espera ese cuadro en vez de abrir otra conexión.
        $foto = Cache::lock("camara.{$numero}.pidiendo", 10)->block(8, function () use ($numero, $camara) {
            $guardada = Cache::get("camara.{$numero}.foto");

            if ($guardada !== null) {
                return base64_decode($guardada);
            }

            $foto = self::pedir($camara['url']);

            if ($foto !== null) {
                Cache::put("camara.{$numero}.foto", base64_encode($foto), now()->addMilliseconds(1500));
            }

            return $foto;
        });

        return $foto;
    }

    private static function pedir(string $url): ?string
    {
        $resultado = Process::timeout(8)->run([
            'ffmpeg', '-v', 'error', '-rtsp_transport', 'tcp', '-i', $url,
            '-frames:v', '1', '-q:v', '5', '-f', 'image2', '-c:v', 'mjpeg', 'pipe:1',
        ]);

        $foto = $resultado->output();

        return $resultado->successful() && str_starts_with($foto, "\xFF\xD8") ? $foto : null;
    }
}
