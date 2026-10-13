<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * A quién se le envían las requisiciones de compra, y quién va en copia (§13).
 *
 * Se cambia desde Compras → Solicitudes de compra → Configuración: el día que
 * cambie la persona que las tramita no debería hacer falta un despliegue.
 */
class ConfiguracionDeCompras
{
    public const POR_DEFECTO = [
        'correo' => 'scpaguay@universidadean.edu.co',
        'nombre' => 'CAROLINA PAGUAY PARDO',
        // Quien coordina el laboratorio se entera de lo que sale a compras
        // sin tener que preguntarlo: por defecto, en copia.
        'copiar_al_equipo' => true,
        'copias' => '',
    ];

    /** @return array{correo:string,nombre:string,copiar_al_equipo:bool,copias:string} */
    public static function todo(): array
    {
        $guardado = Setting::get('compras.configuracion');
        $guardado = is_array($guardado) ? $guardado : [];

        return [
            'correo' => filled($guardado['correo'] ?? null) ? $guardado['correo'] : self::POR_DEFECTO['correo'],
            'nombre' => filled($guardado['nombre'] ?? null) ? $guardado['nombre'] : self::POR_DEFECTO['nombre'],
            // Un «no» guardado es un no: no se confunde con «sin decidir».
            'copiar_al_equipo' => array_key_exists('copiar_al_equipo', $guardado)
                ? (bool) $guardado['copiar_al_equipo']
                : self::POR_DEFECTO['copiar_al_equipo'],
            'copias' => (string) ($guardado['copias'] ?? ''),
        ];
    }

    public static function correo(): string
    {
        return self::todo()['correo'];
    }

    public static function nombre(): string
    {
        return self::todo()['nombre'];
    }

    /**
     * Los correos que van en copia: superadmins y administradores, si está
     * encendido, más los que se escribieron a mano.
     *
     * Sin repetir, y sin el destinatario principal: mandarle su propia copia
     * a quien ya lo recibe solo ensucia.
     *
     * @return list<string>
     */
    public static function copias(): array
    {
        $config = self::todo();

        $delEquipo = $config['copiar_al_equipo']
            ? self::elEquipoQueCoordina()->whereNotNull('email')->pluck('email')->all()
            : [];

        return collect($delEquipo)
            ->merge(self::correosDe($config['copias']))
            ->map(fn ($c) => mb_strtolower(trim((string) $c)))
            ->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($c) => $c === mb_strtolower(trim($config['correo'])))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Superadmins y administradores activos, por nombre.
     *
     * Con `whereHas` y no con el `role()` de Spatie: ese revienta si el rol
     * todavía no existe, y una instalación recién montada no los tiene.
     */
    public static function elEquipoQueCoordina(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->whereHas('roles', fn ($r) => $r->whereIn('name', [User::ROL_SUPERADMIN, User::ROL_ADMINISTRADOR]))
            ->where('status', 'activo')
            ->orderBy('name');
    }

    /**
     * Los correos de un texto libre: separados por comas, punto y coma,
     * espacios o saltos de línea, como salgan al pegarlos.
     *
     * @return list<string>
     */
    public static function correosDe(?string $texto): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', (string) $texto) ?: []));
    }

    /** @param array<string,mixed> $datos */
    public static function guardar(array $datos): void
    {
        Setting::put('compras.configuracion', [
            'correo' => trim((string) ($datos['correo'] ?? '')),
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'copiar_al_equipo' => (bool) ($datos['copiar_al_equipo'] ?? self::POR_DEFECTO['copiar_al_equipo']),
            'copias' => implode(', ', self::correosDe($datos['copias'] ?? '')),
        ], 'compras');
    }
}
