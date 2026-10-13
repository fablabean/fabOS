<?php

namespace App\Support;

use App\Models\Setting;

/**
 * A quién se le envían las requisiciones de compra (§13).
 *
 * Se cambia desde Compras → Solicitudes de compra → Configuración: el día que
 * cambie la persona que las tramita no debería hacer falta un despliegue.
 */
class ConfiguracionDeCompras
{
    public const POR_DEFECTO = [
        'correo' => 'scpaguay@universidadean.edu.co',
        'nombre' => 'CAROLINA PAGUAY PARDO',
    ];

    /** @return array{correo:string,nombre:string} */
    public static function todo(): array
    {
        $guardado = Setting::get('compras.configuracion');

        return array_merge(self::POR_DEFECTO, array_filter(is_array($guardado) ? $guardado : [], 'filled'));
    }

    public static function correo(): string
    {
        return self::todo()['correo'];
    }

    public static function nombre(): string
    {
        return self::todo()['nombre'];
    }

    /** @param array<string,?string> $datos */
    public static function guardar(array $datos): void
    {
        Setting::put(
            'compras.configuracion',
            array_map(fn ($v) => trim((string) $v), array_intersect_key($datos, self::POR_DEFECTO)),
            'compras',
        );
    }
}
