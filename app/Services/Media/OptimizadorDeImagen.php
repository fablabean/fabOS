<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Aceptar la foto que traiga la gente y guardarla ligera (§7).
 *
 * El problema real no es el tope de tamaño: es que una foto de teléfono son
 * doce megapíxeles y cuatro megas, y en el catálogo se ve en una tarjeta de
 * 400 píxeles. Guardarla tal cual desperdicia disco, ancho de banda y la
 * paciencia de quien abre el catálogo desde una conexión mala.
 *
 * Así que en vez de rechazar lo pesado, se reduce: **el límite deja de ser un
 * problema de quien sube y pasa a ser trabajo del sistema.**
 *
 * Tres cosas que hace y que se notan:
 *
 *  · **Endereza.** Las fotos de teléfono vienen giradas con una marca EXIF que
 *    muchos visores respetan y los navegadores no siempre. Sin corregirlo, la
 *    foto sale tumbada y nadie entiende por qué.
 *  · **Reduce a lo que se va a ver.** Más de 2000 píxeles no aporta nada en una
 *    ficha, y multiplica el peso.
 *  · **Reencoda a WebP**, que para una foto pesa la mitad que un JPEG
 *    equivalente y una fracción de un PNG.
 */
final class OptimizadorDeImagen
{
    /** Lado mayor, en píxeles. Por encima de esto no se gana nada visible. */
    public const LADO_MAXIMO = 2000;

    public const CALIDAD = 82;

    /**
     * Guarda la imagen optimizada y devuelve su ruta relativa.
     *
     * Si algo sale mal —un formato que GD no entiende, un archivo corrupto— se
     * guarda el original sin tocar. Perder la foto por no poder encogerla sería
     * peor que guardarla grande.
     */
    public function guardar(UploadedFile $archivo, string $directorio, string $disco = 'public'): string
    {
        $optimizada = $this->optimizar($archivo);

        $extension = $optimizada ? 'webp' : ($archivo->getClientOriginalExtension() ?: 'jpg');
        $ruta = trim($directorio, '/') . '/' . Str::ulid() . '.' . $extension;

        if ($optimizada === null) {
            Storage::disk($disco)->put($ruta, file_get_contents($archivo->getRealPath()));

            return $ruta;
        }

        Storage::disk($disco)->put($ruta, $optimizada);

        return $ruta;
    }

    /**
     * Los bytes ya optimizados, o null si no se pudo.
     */
    public function optimizar(UploadedFile $archivo): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $ruta = $archivo->getRealPath();

        if (! $ruta || ! is_file($ruta)) {
            return null;
        }

        $info = @getimagesize($ruta);

        if (! $info) {
            return null;
        }

        // Un GIF animado se convertiría en una imagen quieta, que no es lo que
        // subió quien lo subió.
        if ($info[2] === IMAGETYPE_GIF && $this->esAnimado($ruta)) {
            return null;
        }

        // Cota de cordura: una imagen de 100 megapíxeles agotaría la memoria
        // antes de que GD termine de descomprimirla.
        if (($info[0] * $info[1]) > 80_000_000) {
            Log::warning('Imagen demasiado grande para optimizar', ['pixeles' => $info[0] * $info[1]]);

            return null;
        }

        $imagen = @imagecreatefromstring(file_get_contents($ruta));

        if ($imagen === false) {
            return null;
        }

        try {
            $imagen = $this->enderezar($imagen, $ruta, $info[2]);
            $imagen = $this->reducir($imagen);

            ob_start();
            $ok = function_exists('imagewebp')
                ? imagewebp($imagen, null, self::CALIDAD)
                : imagejpeg($imagen, null, self::CALIDAD);
            $bytes = ob_get_clean();

            return $ok && $bytes !== '' ? $bytes : null;
        } finally {
            imagedestroy($imagen);
        }
    }

    /**
     * Aplica la orientación EXIF.
     *
     * Los teléfonos no giran los píxeles: guardan la foto como salió del sensor
     * y anotan «esto va girado 90 grados». Al reencodar se pierde esa nota, así
     * que hay que girarla de verdad antes.
     *
     * @param  \GdImage  $imagen
     * @return \GdImage
     */
    private function enderezar($imagen, string $ruta, int $tipo)
    {
        if ($tipo !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $imagen;
        }

        $exif = @exif_read_data($ruta);
        $orientacion = $exif['Orientation'] ?? 1;

        $grados = match ($orientacion) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($grados === 0) {
            return $imagen;
        }

        $girada = imagerotate($imagen, $grados, 0);

        if ($girada === false) {
            return $imagen;
        }

        imagedestroy($imagen);

        return $girada;
    }

    /**
     * @param  \GdImage  $imagen
     * @return \GdImage
     */
    private function reducir($imagen)
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $mayor = max($ancho, $alto);

        if ($mayor <= self::LADO_MAXIMO) {
            return $imagen;
        }

        $escala = self::LADO_MAXIMO / $mayor;

        $reducida = imagescale($imagen, (int) round($ancho * $escala), (int) round($alto * $escala));

        if ($reducida === false) {
            return $imagen;
        }

        imagedestroy($imagen);

        return $reducida;
    }

    private function esAnimado(string $ruta): bool
    {
        $contenido = file_get_contents($ruta);

        // Más de un bloque de control gráfico significa más de un fotograma.
        return substr_count((string) $contenido, "\x00\x21\xF9\x04") > 1;
    }
}
