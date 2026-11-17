<?php

namespace App\Services\Ia\Inventario;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La foto, lista para mandarla y para guardarla.
 *
 * Un celular saca fotos de 12 megapíxeles: mandarlas tal cual tarda, cuesta
 * más y el modelo las reduce de todos modos. Se dejan en 1568 píxeles por el
 * lado largo —lo que el modelo aprovecha— y en JPEG, que es lo que todos los
 * navegadores y la API entienden.
 *
 * La copia se guarda en el disco **privado**: es una foto del laboratorio,
 * a veces con una persona o una pantalla al fondo, y no se publica.
 */
class FotoParaLaIa
{
    public const LADO_MAXIMO = 1568;

    public const LADO_MINIATURA = 320;

    /** La copia para recortar objetos: lo bastante para leer una etiqueta. */
    public const LADO_GRANDE = 3200;

    /** Dónde está la copia grande de una foto (puede no existir si la foto era chica). */
    public static function rutaGrande(string $ruta): string
    {
        return preg_replace('/\.jpg$/', '.grande.jpg', $ruta);
    }

    /**
     * Guarda la miniatura al lado de la foto y devuelve su enlace.
     *
     * Antes la miniatura iba dentro de la página, en base64: con tres fotos
     * llenas eran treinta y tantos recortes y la página pasaba de 1 MB, el
     * límite de Livewire. El enlace pesa unos bytes, va firmado y vence en un
     * día; además pide sesión iniciada.
     */
    public static function guardarMiniatura(string $ruta, string $jpeg): string
    {
        $mini = preg_replace('/\.jpg$/', '.mini.jpg', $ruta);
        Storage::disk('local')->put($mini, $jpeg);

        // Relativo: sin «http://localhost», para que cargue igual desde el celular
        // o desde otra dirección por la que se abra la página.
        return URL::temporarySignedRoute('activo-ia.foto', now()->addDay(), ['ruta' => $mini], absolute: false);
    }

    /**
     * @return array{media_type:string,data:string,miniatura:string,ruta:string}
     */
    public function preparar(string $rutaTemporal): array
    {
        $contenido = @file_get_contents($rutaTemporal);
        $imagen = $contenido !== false ? @imagecreatefromstring($contenido) : false;

        if (! $imagen) {
            throw new RuntimeException('No pude leer la foto. Prueba con un JPG o PNG.');
        }

        $imagen = $this->enderezar($imagen, $contenido);

        $grande = $this->reducir($imagen, self::LADO_MAXIMO);
        $jpeg = $this->comoJpeg($grande, 85);

        $miniatura = $this->comoJpeg($this->reducir($imagen, self::LADO_MINIATURA), 70);

        $ruta = 'activo-ia/' . now()->format('Y-m') . '/' . Str::uuid() . '.jpg';
        Storage::disk('local')->put($ruta, $jpeg);

        /*
         * Y una copia grande, al lado, para recortar de ahí.
         *
         * Con varios objetos en la foto, cada uno se recorta. Recortado de la
         * versión de 1568 píxeles, la etiqueta de un bote de resina quedaba en
         * unas decenas de píxeles y nadie leía «Blue Low Odor Resin». La
         * original del celular trae cuatro veces más detalle.
         */
        if (max(imagesx($imagen), imagesy($imagen)) > self::LADO_MAXIMO) {
            Storage::disk('local')->put(self::rutaGrande($ruta), $this->comoJpeg($this->reducir($imagen, self::LADO_GRANDE), 90));
        }

        return [
            'media_type' => 'image/jpeg',
            'data' => base64_encode($jpeg),
            'miniatura' => self::guardarMiniatura($ruta, $miniatura),
            'ruta' => $ruta,
        ];
    }

    /** Las fotos del celular vienen giradas y lo dicen en el EXIF. */
    private function enderezar(\GdImage $imagen, string $contenido): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contenido, "\xFF\xD8")) {
            return $imagen;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contenido));
        $giro = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $giro === 0 ? $imagen : (imagerotate($imagen, $giro, 0) ?: $imagen);
    }

    private function reducir(\GdImage $imagen, int $lado): \GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $escala = min(1, $lado / max($ancho, $alto));

        if ($escala >= 1) {
            return $imagen;
        }

        $nueva = imagecreatetruecolor((int) round($ancho * $escala), (int) round($alto * $escala));
        imagecopyresampled($nueva, $imagen, 0, 0, 0, 0, imagesx($nueva), imagesy($nueva), $ancho, $alto);

        return $nueva;
    }

    private function comoJpeg(\GdImage $imagen, int $calidad): string
    {
        // Un PNG con transparencia saldría con el fondo negro: se pinta blanco.
        $lienzo = imagecreatetruecolor(imagesx($imagen), imagesy($imagen));
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $imagen, 0, 0, 0, 0, imagesx($imagen), imagesy($imagen));

        ob_start();
        imagejpeg($lienzo, null, $calidad);

        return (string) ob_get_clean();
    }
}
