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
    /** Donde queda el favicon generado: nginx lo sirve en /favicon.ico. */
    public const FAVICON = 'marca/generadas/favicon.ico';

    /**
     * @param  string  $origen  ruta en el disco público (SVG, PNG o JPG)
     * @param  int     $lado    lado del cuadrado, en píxeles
     * @return string|null ruta del PNG en el disco público, o null si no se pudo
     */
    public function cuadrado(string $origen, int $lado, string $nombre): ?string
    {
        $png = $this->dibujar($origen, $lado, aire: 0.12, blanco: true);

        if ($png === null) {
            return null;
        }

        // Con una marca del contenido en el nombre: si la imagen cambia,
        // cambia la dirección, y quien la tenía guardada pide la nueva.
        $destino = 'marca/generadas/' . $nombre . '-' . substr(md5($png), 0, 10) . '.png';
        Storage::disk('public')->put($destino, $png, 'public');

        return $destino;
    }

    /**
     * El /favicon.ico, sacado de la marca.
     *
     * Es lo que pide el navegador cuando abre algo que no es una página —una
     * foto, un PDF— y no tiene etiqueta de icono que leer; y lo último a lo
     * que acude WhatsApp. Era un archivo fijo con la marca vieja. Un ICO con
     * dos PNG dentro (32 y 64 px), que entienden todos los navegadores desde
     * hace años. Si el icono subido ya es un .ico, se usa tal cual.
     */
    public function favicon(string $origen): ?string
    {
        $disco = Storage::disk('public');

        if (str_ends_with(mb_strtolower($origen), '.ico') && $disco->exists($origen)) {
            $disco->put(self::FAVICON, $disco->get($origen), 'public');

            return self::FAVICON;
        }

        $imagenes = [];
        foreach ([32, 64] as $lado) {
            $png = $this->dibujar($origen, $lado, aire: 0.04, blanco: false);
            if ($png === null) {
                return null;
            }
            $imagenes[$lado] = $png;
        }

        // Cabecera ICO: reservado, tipo 1 (icono), cuántas imágenes.
        $ico = pack('vvv', 0, 1, count($imagenes));
        $desplazamiento = 6 + 16 * count($imagenes);
        $datos = '';

        foreach ($imagenes as $lado => $png) {
            // Ancho, alto, colores, reservado, planos, bits, tamaño, dónde empieza.
            $ico .= pack('CCCCvvVV', $lado % 256, $lado % 256, 0, 0, 1, 32, strlen($png), $desplazamiento);
            $desplazamiento += strlen($png);
            $datos .= $png;
        }

        $disco->put(self::FAVICON, $ico . $datos, 'public');

        return self::FAVICON;
    }

    /** La marca centrada en un cuadrado, como PNG. Null si no se pudo. */
    private function dibujar(string $origen, int $lado, float $aire, bool $blanco): ?string
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

            $lienzo = imagecreatetruecolor($lado, $lado);

            if ($blanco) {
                imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
            } else {
                // Transparente: la pestaña del navegador pone su propio fondo.
                imagealphablending($lienzo, false);
                imagesavealpha($lienzo, true);
                imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 0, 0, 0, 127));
            }

            $util = (int) round($lado * (1 - 2 * $aire));
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

            return $png;
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
