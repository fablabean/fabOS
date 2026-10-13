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
        'copias' => '',
    ];

    /** @return array{correo:string,nombre:string,copiar_a:list<int>,copias:string} */
    public static function todo(): array
    {
        $guardado = Setting::get('compras.configuracion');
        $guardado = is_array($guardado) ? $guardado : [];

        return [
            'correo'   => filled($guardado['correo'] ?? null) ? $guardado['correo'] : self::POR_DEFECTO['correo'],
            'nombre'   => filled($guardado['nombre'] ?? null) ? $guardado['nombre'] : self::POR_DEFECTO['nombre'],
            'copiar_a' => self::aQuienesSeCopia($guardado),
            'copias'   => (string) ($guardado['copias'] ?? ''),
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
     * A quiénes del equipo se copia: los que se marcaron, o —mientras nadie
     * haya decidido— los superadmins.
     *
     * Solo superadmins por defecto, y no también los administradores: una
     * requisición en copia a seis personas es un correo que cinco aprenden a
     * ignorar. Quien quiera sumar a alguien lo marca.
     *
     * Siempre cruzado con quien hoy coordina y está activo: quien se marcó y
     * después salió del equipo deja de recibir copia sin que nadie lo quite.
     *
     * @param  array<string,mixed>  $guardado
     * @return list<int>
     */
    private static function aQuienesSeCopia(array $guardado): array
    {
        $equipo = self::elEquipoQueCoordina()->get(['id']);

        if (array_key_exists('copiar_a', $guardado) && is_array($guardado['copiar_a'])) {
            $marcados = array_map('intval', $guardado['copiar_a']);

            return $equipo->pluck('id')->filter(fn ($id) => in_array($id, $marcados, true))->values()->all();
        }

        return $equipo->filter(fn (User $u) => $u->hasRole(User::ROL_SUPERADMIN))->pluck('id')->values()->all();
    }

    /**
     * Los correos que van en copia: los del equipo que estén marcados, más los
     * que se escribieron a mano.
     *
     * Sin repetir, y sin el destinatario principal: mandarle su propia copia
     * a quien ya lo recibe solo ensucia.
     *
     * @return list<string>
     */
    public static function copias(): array
    {
        $config = self::todo();

        $delEquipo = $config['copiar_a'] === []
            ? []
            : User::whereIn('id', $config['copiar_a'])->whereNotNull('email')->orderBy('name')->pluck('email')->all();

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
     * Superadmins y administradores activos, por nombre: entre quienes se
     * elige a quién copiar.
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
     * Las casillas para elegir: cada persona con su rol al lado.
     *
     * @return array<int,string>
     */
    public static function opcionesDelEquipo(): array
    {
        return self::elEquipoQueCoordina()->with('roles')->get()
            ->mapWithKeys(fn (User $u) => [
                $u->id => $u->name . ' · ' . ($u->hasRole(User::ROL_SUPERADMIN) ? 'superadmin' : 'administrador'),
            ])
            ->all();
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
        $nuevo = [
            'correo' => trim((string) ($datos['correo'] ?? '')),
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'copias' => implode(', ', self::correosDe($datos['copias'] ?? '')),
        ];

        // Solo si vino la lista: guardar el nombre y el correo desde otro
        // sitio no debe borrar a quién se copia.
        if (array_key_exists('copiar_a', $datos)) {
            $nuevo['copiar_a'] = array_values(array_map('intval', (array) $datos['copiar_a']));
        } else {
            $anterior = Setting::get('compras.configuracion');

            if (is_array($anterior) && array_key_exists('copiar_a', $anterior)) {
                $nuevo['copiar_a'] = $anterior['copiar_a'];
            }
        }

        Setting::put('compras.configuracion', $nuevo, 'compras');
    }
}
