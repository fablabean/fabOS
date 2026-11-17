<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * A dónde va el reporte de horas extras y quién lo firma como jefe (§5).
 *
 * Se cambia desde Jornadas → Reportes de nómina → Configuración: si cambia el
 * buzón de nómina o quien coordina, no hace falta un despliegue.
 */
class ConfiguracionDeNomina
{
    public const GRUPO = 'nomina';

    public const POR_DEFECTO = [
        'correo'         => 'novedadesnomina@universidadean.edu.co',
        'destinatario'   => 'Novedades de nómina',
        'area'           => 'Unidad de Desarrollo',
        'coordinador_id' => '',
    ];

    /** @return array<string,string> */
    public static function todo(): array
    {
        $guardado = Setting::get('nomina.configuracion');

        return array_merge(self::POR_DEFECTO, array_filter(is_array($guardado) ? $guardado : [], 'filled'));
    }

    public static function valor(string $clave): string
    {
        return (string) (self::todo()[$clave] ?? '');
    }

    public static function coordinador(): ?User
    {
        $id = (int) self::valor('coordinador_id');

        return $id ? User::find($id) : null;
    }

    /** @param array<string,?string> $datos */
    public static function guardar(array $datos): void
    {
        Setting::put(
            'nomina.configuracion',
            array_map(fn ($v) => trim((string) $v), array_intersect_key($datos, self::POR_DEFECTO)),
            self::GRUPO,
        );
    }

    /**
     * Quienes pueden firmar como jefe inmediato: el equipo del laboratorio que
     * tiene correo, porque el enlace para firmar le llega por ahí.
     *
     * @return array<int,string> id => nombre
     */
    public static function opcionesDeCoordinador(?int $sin = null): array
    {
        return User::query()
            ->role(User::rolesDelEquipo())
            ->whereNotNull('email')
            ->when($sin, fn ($q) => $q->whereKeyNot($sin))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
