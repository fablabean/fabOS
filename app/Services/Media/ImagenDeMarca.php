<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Una versión de la marca como PNG cuadrado, para quien no entiende SVG (§19).
 *
 * WhatsApp, Facebook y el icono de iOS no muestran SVG, y el logo del
 * laboratorio es SVG. Sin esto, la vista previa de un enlace tomaba el único
 * PNG que encontraba —el icono viejo del sistema— y el enlace salía con otra
 * marca. Aquí se dibuja la versión elegida, centrada y con aire, sobre un
 * cuadrado blanco: es lo que esos sitios recortan igual en cualquier tamaño.
 */
class ImagenDeMarca
{
    /**
     * @param  string  $origen  ruta en el disco público (SVG, PNG o JPG)
     * @param  int     $lado    lado del cuadrado, en píxeles
     * @return string|null ruta del PNG en el disco público, o null si no se pudo
     */
    public function cuadrado(string $origen, int $lado, string $nombre): ?string
    {
        $disco = Storage::disk('public');

        if (! $disco->exists($origen)) {
            return null;
        }

        $temporal = null;

        try {
            $ruta = $disco->path($origen);

            // El SVG se pasa a PNG primero, al doble de lo que hará falta.
            if (str_ends_with(mb_strtolower($origen), '.svg')) {
                $temporal = tempnam(sys_get_temp_dir(), 'marca') . '.png';
                $proceso = new Process(['rsvg-convert', '-w', (string) ($lado * 2), '-h', (string) ($lado * 2), '--keep-aspect-ratio', '-o', $temporal, $ruta]);
                $proceso->setTimeout(20);
                $proceso->run();

                if (! $proceso->isSuccessful()) {
                    return null;
                }

                $ruta = $temporal;
            }

            $imagen = @imagecreatefromstring((string) file_get_contents($ruta));

            if (! $imagen) {
                return null;
            }

            // Sobre blanco, con un 12 % de aire alrededor.
            $lienzo = imagecreatetruecolor($lado, $lado);
            imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));

            $util = (int) round($lado * 0.76);
            $ancho = imagesx($imagen);
            $alto = imagesy($imagen);
            $escala = min($util / $ancho, $util / $alto);
            $w = max(1, (int) round($ancho * $escala));
            $h = max(1, (int) round($alto * $escala));

            imagealphablending($lienzo, true);
            imagecopyresampled($lienzo, $imagen, intdiv($lado - $w, 2), intdiv($lado - $h, 2), 0, 0, $w, $h, $ancho, $alto);

            ob_start();
            imagepng($lienzo, null, 6);
            $png = (string) ob_get_clean();

            imagedestroy($imagen);
            imagedestroy($lienzo);

            // Con una marca del contenido en el nombre: si la imagen cambia,
            // cambia la dirección, y quien la tenía guardada pide la nueva.
            $destino = 'marca/generadas/' . $nombre . '-' . substr(md5($png), 0, 10) . '.png';
            $disco->put($destino, $png, 'public');

            return $destino;
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            if ($temporal && is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }
}
