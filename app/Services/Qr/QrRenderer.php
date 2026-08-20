<?php

namespace App\Services\Qr;

use BaconQrCode\Encoder\Encoder;

/**
 * Genera el QR como SVG en línea.
 *
 * SVG y no PNG a propósito: escala sin perder nitidez al imprimir en cualquier
 * tamaño de etiqueta, no necesita extensiones de imagen en PHP, y va embebido
 * en el HTML sin escribir archivos ni servir peticiones extra.
 */
class QrRenderer
{
    public function svg(string $texto, int $tamano = 120, string $color = '#111111'): string
    {
        // Corrección de errores media: una etiqueta pegada a una máquina se
        // raya y se ensucia; conviene que siga leyéndose con daño parcial.
        $matriz = Encoder::encode($texto, \BaconQrCode\Common\ErrorCorrectionLevel::M())
            ->getMatrix();

        $ancho = $matriz->getWidth();
        $borde = 2;                          // zona tranquila, exigida por el estándar
        $lado  = $ancho + $borde * 2;

        $modulos = '';

        for ($y = 0; $y < $ancho; $y++) {
            for ($x = 0; $x < $ancho; $x++) {
                if ($matriz->get($x, $y) === 1) {
                    $modulos .= sprintf('M%d %dh1v1h-1z', $x + $borde, $y + $borde);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="Código QR">'
            . '<rect width="%2$d" height="%2$d" fill="#ffffff"/>'
            . '<path d="%3$s" fill="%4$s"/>'
            . '</svg>',
            $tamano,
            $lado,
            $modulos,
            $color,
        );
    }
}
