<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * El catálogo de la Galería 3D: lo que dice config/galeria3d.php más lo que
 * hay en disco (§ Galería 3D).
 *
 * Un modelo se enseña solo si están sus cuatro archivos. Las direcciones
 * llevan `?v=` con la fecha del archivo: nginx sirve los estáticos con caché
 * de treinta días e «immutable», así que sin eso un modelo reconstruido
 * seguiría viéndose viejo un mes.
 *
 * Las cifras de la ficha técnica (triángulos, peso, medidas) salen de
 * estadisticas.json, que escribe el mismo script que construye los modelos:
 * así no hay dos sitios donde se puedan contradecir.
 */
class Galeria3d
{
    public const CARPETA = '3d/modelos';

    /** @var array<string, array<string,mixed>>|null */
    private static ?array $memo = null;

    /** Se apagan desde el panel (Galería 3D → «Modelos de ejemplo»). */
    public const AJUSTE_EJEMPLOS = 'galeria3d.mostrar_ejemplos';

    /**
     * Todos los que se enseñan: primero los subidos desde el panel (en su
     * orden), después los de ejemplo, si no se apagaron.
     *
     * @return Collection<string, array<string,mixed>>
     */
    public static function modelos(): Collection
    {
        if (self::$memo !== null) {
            return collect(self::$memo);
        }

        $modelos = self::subidos();
        if (self::conEjemplos()) {
            $modelos += self::ejemplos();   // si un slug se repite, gana el subido
        }
        self::$memo = $modelos;

        return collect($modelos);
    }

    public static function conEjemplos(): bool
    {
        try {
            return (bool) \App\Models\Setting::get(self::AJUSTE_EJEMPLOS, true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** Los modelos subidos desde el panel y publicados. */
    public static function subidos(): array
    {
        try {
            $filas = \App\Models\Modelo3d::query()->where('publicado', true)->orderBy('orden')->orderByDesc('id')->get();
        } catch (\Illuminate\Database\QueryException) {
            return [];   // instalación sin migrar todavía: la galería sigue con los de ejemplo
        }
        $categorias = config('galeria3d.categorias', []) + config('galeria3d.categorias_retiradas', []);
        $modelos = [];
        foreach ($filas as $m) {
            if (! $m->tieneArchivos()) {
                continue;
            }
            $e = $m->estadisticas ?? [];
            $opt = $e['optimizado'] ?? [];
            $urls = $bytes = [];
            foreach (array_keys(\App\Models\Modelo3d::ARCHIVOS) as $clave) {
                $urls[$clave] = $m->url($clave);
                $bytes[$clave] = $m->bytes($clave);
            }
            $modelos[$m->slug] = [
                'slug'        => $m->slug,
                'nombre'      => $m->nombre,
                'categoria'   => $m->categoria,
                'categoria_nombre' => $categorias[$m->categoria] ?? $m->categoria,
                'tecnica'     => $m->tecnica,
                'maquina'     => $m->maquina,
                'material'    => $m->material,
                'resumen'     => (string) $m->resumen,
                'descripcion' => (string) $m->descripcion,
                'escala_fija' => $m->escala_fija,
                'alejar'      => 1.0,
                'acabados'    => $m->acabadosParaLaGaleria(),
                'urls'        => $urls,
                'bytes'       => $bytes,
                'medidas'     => $e['medidas'] ?? null,
                'triangulos'  => $opt['triangulos'] ?? null,
                'animado'     => ! empty($opt['animaciones']),
                'texturas'    => $opt['texturas'] ?? [],
                'fuente'      => ! empty($e['fuente']['bytes']) ? [
                    'triangulos' => $e['fuente']['triangulos'],
                    'bytes'      => $e['fuente']['bytes'],
                ] : null,
                'subido'      => true,
            ];
        }

        return $modelos;
    }

    /** Los de ejemplo: config/galeria3d.php y sus archivos en public/3d/modelos. */
    public static function ejemplos(): array
    {
        $estadisticas = self::estadisticas();
        $categorias = config('galeria3d.categorias', []) + config('galeria3d.categorias_retiradas', []);
        $modelos = [];

        foreach (config('galeria3d.modelos', []) as $slug => $m) {
            $archivos = [
                'glb'    => "{$slug}.glb",
                'glb_ar' => "{$slug}-ar.glb",
                'usdz'   => "{$slug}.usdz",
                'poster' => "{$slug}.webp",
            ];

            $urls = [];
            $bytes = [];
            foreach ($archivos as $clave => $nombre) {
                $ruta = public_path(self::CARPETA . '/' . $nombre);
                if (! is_file($ruta)) {
                    continue 2;
                }
                $urls[$clave] = asset(self::CARPETA . '/' . $nombre) . '?v=' . filemtime($ruta);
                $bytes[$clave] = filesize($ruta);
            }

            $e = $estadisticas[$slug] ?? [];
            $opt = $e['optimizado'] ?? [];

            $modelos[$slug] = [
                'slug'        => $slug,
                'nombre'      => $m['nombre'],
                'categoria'   => $m['categoria'],
                'categoria_nombre' => $categorias[$m['categoria']] ?? $m['categoria'],
                'tecnica'     => $m['tecnica'] ?? null,
                'maquina'     => $m['maquina'] ?? null,
                'material'    => $m['material'] ?? null,
                'resumen'     => $m['resumen'] ?? '',
                'descripcion' => $m['descripcion'] ?? '',
                'escala_fija' => (bool) ($m['ar']['escala_fija'] ?? false),
                // >1 aleja la cámara: para piezas compactas (un cubo) que llenan el cuadro
                'alejar'      => max(0.5, min(3.0, (float) ($m['vista']['alejar'] ?? 1))),
                'acabados'    => array_values($m['acabados'] ?? []),
                'urls'        => $urls,
                'bytes'       => $bytes,
                'medidas'     => $e['medidas'] ?? null,
                'triangulos'  => $opt['triangulos'] ?? null,
                'animado'     => ! empty($opt['animaciones']),
                'texturas'    => $opt['texturas'] ?? [],
                'fuente'      => isset($e['fuente']) ? [
                    'triangulos' => $e['fuente']['triangulos'],
                    'bytes'      => $e['fuente']['bytes'],
                ] : null,
                'subido'      => false,
            ];
        }

        return $modelos;
    }

    /** @return array<string,mixed>|null */
    public static function modelo(string $slug): ?array
    {
        return self::modelos()->get($slug);
    }

    /** Las categorías que tienen al menos un modelo, en el orden de la configuración. */
    public static function categorias(): array
    {
        $usadas = self::modelos()->pluck('categoria')->unique()->all();

        return array_filter(
            config('galeria3d.categorias', []),
            fn ($clave) => in_array($clave, $usadas, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /*
     * Los códigos QR de cada figura llevan a su página de realidad aumentada
     * (/galeria-3d/{figura}/ra). Para imprimirlos, la dirección tiene que ser
     * fija: config('galeria3d.url_publica') o APP_URL si es https.
     */

    /** La dirección base de los QR, sin barra al final. */
    public static function baseQr(): string
    {
        $configurada = trim((string) config('galeria3d.url_publica'));
        if ($configurada !== '') {
            return rtrim($configurada, '/');
        }
        $app = (string) config('app.url');
        if (str_starts_with($app, 'https://')) {
            return rtrim($app, '/');
        }

        $pagina = rtrim(request()->getSchemeAndHttpHost(), '/');

        // Abierta como localhost (o con una IP de la red): si el túnel del
        // celular está encendido, su enlace público, que el teléfono sí abre.
        if (self::hostLocal((string) parse_url($pagina, PHP_URL_HOST))) {
            return self::enlaceDelTunel() ?? $pagina;
        }

        return $pagina;
    }

    /** El enlace público del túnel del celular, o null si está apagado (ver TunelCelular). */
    public static function enlaceDelTunel(): ?string
    {
        return TunelCelular::enlace();
    }

    /** localhost, la propia máquina o una dirección privada de la red. */
    public static function hostLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    }

    /**
     * ¿El teléfono podrá abrir lo que lleva el QR? No, si es localhost o una
     * dirección de esta red (y el túnel del celular está apagado).
     */
    public static function qrAlcanzable(): bool
    {
        return ! self::hostLocal((string) parse_url(self::baseQr(), PHP_URL_HOST));
    }

    /** La página de realidad aumentada de una figura, con la dirección de los QR. */
    public static function urlRA(string $slug): string
    {
        return self::baseQr() . '/galeria-3d/' . rawurlencode($slug) . '/ra';
    }

    /**
     * ¿Los QR apuntan a una dirección que no sirve para imprimir? localhost y
     * las direcciones privadas solo existen en la red del laboratorio, y el
     * enlace del túnel del celular cambia cada vez que se enciende.
     */
    public static function qrTemporal(): bool
    {
        $host = strtolower((string) parse_url(self::baseQr(), PHP_URL_HOST));

        return self::hostLocal($host) || str_ends_with($host, '.trycloudflare.com');
    }

    /** Las categorías con botón de filtro en la colección (config galeria3d.filtros). */
    public static function filtros(): array
    {
        $filtros = config('galeria3d.filtros');
        if (! is_array($filtros)) {
            return self::categorias();
        }

        return array_filter(self::categorias(), fn ($clave) => in_array($clave, $filtros, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Las etiquetas de un modelo para los filtros de la colección: el tipo de
     * impresión y los materiales, sacados de su técnica y su material
     * («Impresión 3D en resina (SLA)» → Resina; «PETG gris y PLA naranja» →
     * PETG y PLA), más «Animada» si se mueve.
     *
     * @return array<string,string> clave => nombre
     */
    public static function etiquetas(array $m): array
    {
        $texto = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(($m['tecnica'] ?? '') . ' ' . ($m['material'] ?? '') . ' ' . ($m['maquina'] ?? '')));
        $e = [];
        if (str_contains($texto, 'resina') || str_contains($texto, 'sla')) {
            $e['resina'] = 'Resina';
        } elseif (str_contains($texto, 'vaso')) {
            $e['vaso'] = 'Modo vaso';
        } elseif (($m['categoria'] ?? '') === 'impresion-3d') {
            $e['fdm'] = 'FDM';
        }
        foreach (config('galeria3d.impresion.materiales', []) as $clave => $mat) {
            if ($clave !== 'resina' && preg_match('/\b' . preg_quote(strtolower($mat['nombre']), '/') . '\b/', $texto)) {
                $e[$clave] = $mat['nombre'];
            }
        }
        if (! empty($m['animado'])) {
            $e['animada'] = 'Animadas';
        }

        return $e;
    }

    /** Las etiquetas que tiene al menos un modelo, en un orden fijo. */
    public static function etiquetasUsadas(): array
    {
        $usadas = [];
        foreach (self::modelos() as $m) {
            $usadas += self::etiquetas($m);
        }
        $orden = array_merge(['fdm', 'resina', 'vaso'], array_keys(config('galeria3d.impresion.materiales', [])), ['animada']);

        return array_filter(array_replace(array_fill_keys($orden, null), $usadas));
    }

    /**
     * El material con que la calculadora de impresión empieza: el primero que
     * nombra el modelo (PLA si no nombra ninguno).
     */
    public static function materialDeImpresion(array $m): string
    {
        $e = self::etiquetas($m);
        if (isset($e['resina'])) {
            return 'resina';
        }
        foreach (array_keys(config('galeria3d.impresion.materiales', [])) as $clave) {
            if (isset($e[$clave])) {
                return $clave;
            }
        }

        return 'pla';
    }

    /** Totales de la optimización, para contarlos en la página. */
    public static function resumenOptimizacion(): array
    {
        $m = self::modelos()->filter(fn ($x) => $x['fuente']);

        return [
            'modelos'          => $m->count(),
            'triangulos_antes' => $m->sum(fn ($x) => $x['fuente']['triangulos']),
            'triangulos'       => $m->sum('triangulos'),
            'bytes_antes'      => $m->sum(fn ($x) => $x['fuente']['bytes']),
            'bytes'            => $m->sum(fn ($x) => $x['bytes']['glb']),
        ];
    }

    /** Para que las pruebas vean un catálogo nuevo tras cambiar la configuración. */
    public static function olvidar(): void
    {
        self::$memo = null;
    }

    private static function estadisticas(): array
    {
        $ruta = public_path(self::CARPETA . '/estadisticas.json');
        if (! is_file($ruta)) {
            return [];
        }

        return json_decode((string) file_get_contents($ruta), true) ?: [];
    }

    /** «1,4 MB», «273 KB»: como lo dice la gente. */
    public static function peso(?int $bytes): string
    {
        if (! $bytes) {
            return '—';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
