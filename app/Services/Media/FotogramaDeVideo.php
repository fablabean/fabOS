<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Un fotograma de un video, como imagen (§19).
 *
 * El banner de video necesita una imagen fija: es lo que se ve mientras el
 * video carga y lo único que se ve con el ahorro de datos activado. Pedirla
 * aparte era pedir que alguien sacara una captura a mano, y el campo se
 * quedaba vacío. Se saca del propio video, con ffmpeg, que está en el
 * contenedor.
 *
 * Nunca rompe nada: si ffmpeg falla o tarda, devuelve null y el banner se
 * guarda igual, sin imagen de carga.
 */
class FotogramaDeVideo
{
    /** Prefijo de los generados aquí: se reconocen para rehacerlos si cambia el video. */
    public const PREFIJO = 'fotograma-';

    public function extraer(string $video, string $disco = 'public', string $carpeta = 'banners'): ?string
    {
        $almacen = Storage::disk($disco);

        if (! $almacen->exists($video)) {
            return null;
        }

        $destino = $carpeta . '/' . self::PREFIJO . Str::random(12) . '.jpg';

        // El segundo 1 suele ser más representativo que el primer cuadro,
        // que en muchos videos es negro; si el video es más corto, el 0.
        foreach (['1', '0'] as $segundo) {
            $proceso = new Process([
                'ffmpeg', '-y', '-loglevel', 'error',
                '-ss', $segundo, '-i', $almacen->path($video),
                '-frames:v', '1', '-vf', 'scale=min(1920\,iw):-2', '-q:v', '4',
                $almacen->path($destino),
            ]);
            $proceso->setTimeout(30);

            try {
                $proceso->run();
            } catch (\Throwable $e) {
                report($e);

                return null;
            }

            if ($proceso->isSuccessful() && $almacen->exists($destino) && $almacen->size($destino) > 0) {
                return $destino;
            }
        }

        return null;
    }

    public static function esGenerado(?string $ruta): bool
    {
        return $ruta !== null && str_contains($ruta, '/' . self::PREFIJO);
    }
}
