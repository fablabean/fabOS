<?php

namespace App\Support;

use App\Models\Setting;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Cómo se ve el menú del panel: el orden de los grupos, su color, y el ícono,
 * el grupo y el orden de cada opción (§19).
 *
 * Se decide en *Configuración → Menú* y se guarda en los ajustes, no en el
 * código: el laboratorio reordena su menú sin desplegar. Lo que no se ha
 * tocado se queda como lo dejó el código, así que una pantalla nueva aparece
 * donde la puso quien la escribió hasta que alguien la mueva.
 *
 * Las opciones se reconocen por su nombre en el menú: es lo único estable que
 * comparten una pantalla y un recurso, y es lo que ve quien las ordena.
 */
class MenuDelPanel
{
    public const GRUPOS = 'menu.grupos';

    public const OPCIONES = 'menu.opciones';

    /**
     * El orden de fábrica: arriba lo diario, abajo lo que se ajusta de vez en
     * cuando. Un grupo que no esté aquí sale al final.
     */
    public const GRUPOS_DE_FABRICA = [
        'Proyectos',
        'Operación',
        'Comunicaciones',
        'Software y claves',
        'Compras',
        'Laboratorio',
        'Formación',
        'Finanzas',
        'Tienda',
        'Personas',
        'Mantenimiento',
        'Jornadas',
        'Documentación',
        'Configuración',
    ];

    /**
     * Los colores que se ofrecen, con su tono para fondo claro y para oscuro.
     * Una paleta corta a propósito: veinte tonos en un menú no ordenan nada.
     *
     * @var array<string, array{0:string,1:string,2:string}> clave => [nombre, claro, oscuro]
     */
    public const COLORES = [
        'ambar'    => ['Ámbar',    '#d97706', '#fbbf24'],
        'naranja'  => ['Naranja',  '#ea580c', '#fb923c'],
        'rojo'     => ['Rojo',     '#dc2626', '#f87171'],
        'rosa'     => ['Rosa',     '#db2777', '#f472b6'],
        'violeta'  => ['Violeta',  '#7c3aed', '#a78bfa'],
        'indigo'   => ['Índigo',   '#4f46e5', '#818cf8'],
        'azul'     => ['Azul',     '#2563eb', '#60a5fa'],
        'cian'     => ['Cian',     '#0891b2', '#22d3ee'],
        'turquesa' => ['Turquesa', '#0d9488', '#2dd4bf'],
        'verde'    => ['Verde',    '#16a34a', '#4ade80'],
        'gris'     => ['Gris',     '#4b5563', '#9ca3af'],
    ];

    /**
     * Los grupos en su orden, con su color.
     *
     * @return list<array{nombre:string,color:?string}>
     */
    public static function grupos(): array
    {
        $guardados = collect(Setting::get(self::GRUPOS, []))
            ->filter(fn ($g) => is_array($g) && filled($g['nombre'] ?? null))
            ->map(fn ($g) => ['nombre' => (string) $g['nombre'], 'color' => $g['color'] ?? null])
            ->values();

        // Los de fábrica que nunca se ordenaron van detrás de los ordenados.
        $faltan = collect(self::GRUPOS_DE_FABRICA)
            ->reject(fn ($n) => $guardados->contains('nombre', $n))
            ->map(fn ($n) => ['nombre' => $n, 'color' => null]);

        return $guardados->concat($faltan)->values()->all();
    }

    /** @return list<string> */
    public static function ordenDeGrupos(): array
    {
        return array_column(self::grupos(), 'nombre');
    }

    /**
     * Lo decidido para cada opción, por su nombre.
     *
     * @return array<string, array{icono?:?string,grupo?:?string,orden?:?int}>
     */
    public static function opciones(): array
    {
        $guardadas = Setting::get(self::OPCIONES, []);

        return is_array($guardadas) ? $guardadas : [];
    }

    /**
     * Aplica lo decidido a las opciones del menú: ícono, grupo y orden.
     *
     * Las ordenadas a mano van primero en su grupo; las que nadie ha tocado
     * detrás, en el orden que les dio el código.
     *
     * @param  array<NavigationItem>  $items
     * @return array<NavigationItem>
     */
    public static function aplicar(array $items): array
    {
        $opciones = self::opciones();

        if ($opciones === []) {
            return $items;
        }

        foreach ($items as $item) {
            $ajuste = $opciones[$item->getLabel()] ?? null;

            if (! is_array($ajuste)) {
                // Sin tocar: detrás de las ordenadas de su grupo.
                $item->sort(1000 + (int) $item->getSort());

                continue;
            }

            if (filled($ajuste['icono'] ?? null) && self::esIcono($ajuste['icono'])) {
                $item->icon($ajuste['icono'])->activeIcon($ajuste['icono']);
            }

            if (array_key_exists('grupo', $ajuste)) {
                $item->group(filled($ajuste['grupo']) ? $ajuste['grupo'] : null);
            }

            if (isset($ajuste['orden'])) {
                $item->sort((int) $ajuste['orden']);
            }
        }

        return $items;
    }

    /**
     * Guarda el menú tal como quedó en la pantalla.
     *
     * Solo se escribe lo que se vio: quien no ve una opción no la ordenó, y lo
     * que otro decidió de ella se conserva. Lo mismo con los grupos que no le
     * salían, que siguen donde estaban, detrás.
     *
     * @param  list<array{nombre:string,color:?string,opciones:list<array{nombre:string,icono:?string,grupo:?string}>}>  $grupos
     */
    public static function guardar(array $grupos): void
    {
        $grupos = array_values(array_filter($grupos, fn ($g) => is_array($g)));

        $vistos = array_column(array_filter($grupos, fn ($g) => filled($g['nombre'] ?? null)), 'nombre');

        $orden = collect($grupos)
            ->filter(fn ($g) => filled($g['nombre'] ?? null))
            ->map(fn ($g) => [
                'nombre' => (string) $g['nombre'],
                'color'  => array_key_exists($g['color'] ?? '', self::COLORES) ? $g['color'] : null,
            ])
            ->concat(collect(self::grupos())->reject(fn ($g) => in_array($g['nombre'], $vistos, true)))
            ->values()
            ->all();

        $opciones = self::opciones();

        foreach ($grupos as $grupo) {
            foreach (array_values($grupo['opciones'] ?? []) as $i => $opcion) {
                if (blank($opcion['nombre'] ?? null)) {
                    continue;
                }

                // Una opción que se mueve a otro grupo va al final de aquel.
                $destino = array_key_exists('grupo', $opcion) ? ($opcion['grupo'] ?: null) : ($grupo['nombre'] ?: null);
                $seMueve = $destino !== ($grupo['nombre'] ?: null);

                $opciones[$opcion['nombre']] = [
                    'icono' => self::esIcono($opcion['icono'] ?? null) ? $opcion['icono'] : null,
                    'grupo' => $destino,
                    'orden' => $seMueve ? 900 + $i : $i + 1,
                ];
            }
        }

        Setting::put(self::GRUPOS, $orden, 'menu');
        Setting::put(self::OPCIONES, $opciones, 'menu');
    }

    /** Todo como lo dejó el código. */
    public static function restaurar(): void
    {
        Setting::put(self::GRUPOS, [], 'menu');
        Setting::put(self::OPCIONES, [], 'menu');
    }

    /**
     * Los colores de los grupos, como hoja de estilos: el título del grupo y
     * los íconos de sus opciones.
     */
    public static function estilos(): string
    {
        $reglas = [];

        foreach (self::grupos() as $grupo) {
            $color = self::COLORES[$grupo['color'] ?? ''] ?? null;

            if (! $color) {
                continue;
            }

            $sel = '.fi-sidebar-group[data-group-label="' . addcslashes($grupo['nombre'], '"\\') . '"]';

            $reglas[] = "{$sel} .fi-sidebar-group-label,{$sel} .fi-sidebar-item-icon{color:{$color[1]}}";
            $reglas[] = ".dark {$sel} .fi-sidebar-group-label,.dark {$sel} .fi-sidebar-item-icon{color:{$color[2]}}";
        }

        return implode("\n", $reglas);
    }

    /**
     * Los íconos que se pueden elegir: los de contorno de Heroicons, que son
     * los que usa todo el menú. Mezclar rellenos y contornos lo desordena.
     *
     * @return array<string, string> valor => nombre legible
     */
    public static function iconos(): array
    {
        static $iconos = null;

        return $iconos ??= collect(Heroicon::cases())
            ->filter(fn (Heroicon $h) => str_starts_with($h->name, 'Outlined'))
            ->mapWithKeys(fn (Heroicon $h) => [
                'heroicon-' . $h->value => Str::of($h->name)->after('Outlined')->headline()->toString(),
            ])
            ->all();
    }

    public static function esIcono(mixed $valor): bool
    {
        return is_string($valor) && array_key_exists($valor, self::iconos());
    }

    /** El ícono de una opción como lo tiene ahora, en el formato de la lista. */
    public static function valorDeIcono(mixed $icono): ?string
    {
        if ($icono instanceof Heroicon) {
            return 'heroicon-' . $icono->value;
        }

        return is_string($icono) ? $icono : null;
    }
}
