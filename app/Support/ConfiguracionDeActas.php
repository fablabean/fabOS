<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Lo que las actas de movimiento necesitan saber y no está en el inventario:
 * a quién se le envían, quién las autoriza, y el proceso y la sede que van
 * por defecto en las casillas de origen y destino.
 *
 * Se cambia desde Laboratorio → Actas de movimiento → Configuración: el día
 * que cambie quien autoriza en Planta Física no debería hacer falta un
 * despliegue.
 */
class ConfiguracionDeActas
{
    public const GRUPO = 'actas';

    public const POR_DEFECTO = [
        'correo_planta'  => 'wopalencia@universidadean.edu.co',
        'autoriza_nombre' => 'Wilmer Palencia',
        'autoriza_cargo' => 'Infraestructura y Planta Física',
        'proceso'        => 'Unidad de desarrollo FABLAB',
        'sede'           => 'EL NOGAL',
        'cargo_entrega'  => 'Coordinador FABLAB',
    ];

    /** @return array<string,string> */
    public static function todo(): array
    {
        $guardado = Setting::get('actas.configuracion');

        return array_merge(self::POR_DEFECTO, array_filter(is_array($guardado) ? $guardado : [], 'filled'));
    }

    public static function valor(string $clave): string
    {
        return (string) (self::todo()[$clave] ?? '');
    }

    /** @param array<string,?string> $datos */
    public static function guardar(array $datos): void
    {
        Setting::put(
            'actas.configuracion',
            array_map(fn ($v) => trim((string) $v), array_intersect_key($datos, self::POR_DEFECTO)),
            self::GRUPO,
        );
    }
}
