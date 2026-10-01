<?php

namespace App\Services\Recorrido;

use App\Models\Recorrido\Estacion;
use Illuminate\Support\Str;

/**
 * Decide si la respuesta de un equipo es correcta.
 *
 * Cada tipo guarda en `datos_respuesta` lo que necesita:
 *
 *  · texto   → `aceptadas`: las respuestas válidas. Se compara sin mayúsculas,
 *              tildes ni espacios de más: «Cortadora Láser» vale lo mismo que
 *              «cortadora laser». Escribir con el celular ya es bastante.
 *  · opcion  → `opciones`: [{texto, correcta}], una o más correctas.
 *  · ubicar  → `imagen`, y el blanco: `x`, `y` y `radio`, en porcentaje del
 *              ancho y el alto de la imagen, para que no dependa del tamaño
 *              de la pantalla.
 *  · enlazar → `pares`: [{izquierda, derecha}]. Se responde con, por cada
 *              elemento de la izquierda, el índice de la derecha elegido.
 */
class Corrector
{
    public function esCorrecta(Estacion $estacion, mixed $respuesta): bool
    {
        $datos = $estacion->datos_respuesta ?? [];

        return match ($estacion->tipo_respuesta) {
            'texto'   => $this->texto($datos, $respuesta),
            'opcion'  => $this->opcion($datos, $respuesta),
            'ubicar'  => $this->ubicar($datos, $respuesta),
            'enlazar' => $this->enlazar($datos, $respuesta),
            default   => false,
        };
    }

    public static function normalizar(mixed $texto): string
    {
        return (string) Str::of((string) $texto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();
    }

    private function texto(array $datos, mixed $respuesta): bool
    {
        $dada = self::normalizar($respuesta);

        return $dada !== '' && collect($datos['aceptadas'] ?? [])
            ->map(fn ($a) => self::normalizar(is_array($a) ? ($a['texto'] ?? '') : $a))
            ->contains($dada);
    }

    private function opcion(array $datos, mixed $respuesta): bool
    {
        $opciones = array_values($datos['opciones'] ?? []);

        return is_numeric($respuesta)
            && ! empty($opciones[(int) $respuesta]['correcta']);
    }

    private function ubicar(array $datos, mixed $respuesta): bool
    {
        if (! is_array($respuesta) || ! is_numeric($respuesta['x'] ?? null) || ! is_numeric($respuesta['y'] ?? null)) {
            return false;
        }

        $dx = (float) $respuesta['x'] - (float) ($datos['x'] ?? -100);
        $dy = (float) $respuesta['y'] - (float) ($datos['y'] ?? -100);

        return sqrt($dx * $dx + $dy * $dy) <= (float) ($datos['radio'] ?? 10);
    }

    private function enlazar(array $datos, mixed $respuesta): bool
    {
        $pares = array_values($datos['pares'] ?? []);

        if ($pares === [] || ! is_array($respuesta)) {
            return false;
        }

        foreach (array_keys($pares) as $i) {
            if (! isset($respuesta[$i]) || (int) $respuesta[$i] !== $i) {
                return false;
            }
        }

        return true;
    }
}
