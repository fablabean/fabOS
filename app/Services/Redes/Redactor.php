<?php

namespace App\Services\Redes;

/**
 * Quien escribe el borrador de una publicación (§21).
 *
 * Hoy lo escribe `RedactorConPlantillas`, con frases armadas a partir de los
 * datos del laboratorio. Es el punto donde se conecta la IA propia del
 * laboratorio: una clase que cumpla este contrato, registrada en
 * `config('fabos.redes.redactor')`, y las sugerencias pasan a redactarse con
 * ella sin tocar nada más.
 *
 * Lo que devuelve es siempre un **borrador**: lo lee una persona, lo corrige
 * y lo manda a aprobación. Nada sale publicado porque lo escribió una máquina.
 */
interface Redactor
{
    /**
     * @param  string  $tipo  la clase de sugerencia: «anuncio», «cierre», «proyecto»…
     * @param  array<string,mixed>  $datos  lo que se sabe: nombre, fecha, enlace…
     */
    public function borrador(string $tipo, array $datos): string;

    /** Para decir en pantalla quién lo escribió: «plantillas», «IA del laboratorio». */
    public function nombre(): string;
}
