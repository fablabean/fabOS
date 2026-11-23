<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Vistas, «me gusta» y descargas de cada figura de la Galería 3D.
 *
 * Sin cuentas: quien visita no inicia sesión. Para que recargar la página o
 * tocar el corazón diez veces no infle las cifras, cada navegador cuenta una
 * vista y un «me gusta» por figura (se recuerda en su sesión). Las descargas
 * sí se suman cada vez: bajar el STL y luego el 3MF son dos descargas.
 */
class Galeria3dContadores
{
    public const TABLA = 'galeria3d_contadores';

    private const SESION_VISTAS = 'galeria3d.vistas';
    private const SESION_ME_GUSTA = 'galeria3d.me_gusta';

    /** @return array<string, array{vistas:int, me_gusta:int, descargas:int}> */
    public static function todos(): array
    {
        try {
            return DB::table(self::TABLA)->get()->mapWithKeys(fn ($f) => [$f->slug => [
                'vistas'    => (int) $f->vistas,
                'me_gusta'  => (int) $f->me_gusta,
                'descargas' => (int) $f->descargas,
            ]])->all();
        } catch (\Illuminate\Database\QueryException) {
            return [];   // sin migrar todavía: la galería se ve igual, sin cifras
        }
    }

    /** @return array{vistas:int, me_gusta:int, descargas:int} */
    public static function de(string $slug): array
    {
        return self::todos()[$slug] ?? ['vistas' => 0, 'me_gusta' => 0, 'descargas' => 0];
    }

    /** Las figuras a las que este navegador ya les dio «me gusta». */
    public static function meGustaDeEstaSesion(): array
    {
        return array_values((array) session(self::SESION_ME_GUSTA, []));
    }

    /** Una vista por figura y por navegador. */
    public static function vista(string $slug): void
    {
        $vistos = (array) session(self::SESION_VISTAS, []);
        if (in_array($slug, $vistos, true)) {
            return;
        }
        self::sumar($slug, 'vistas', 1);
        $vistos[] = $slug;
        session([self::SESION_VISTAS => array_slice($vistos, -200)]);
    }

    /** Pone o quita el «me gusta» de este navegador. Devuelve si quedó puesto. */
    public static function meGusta(string $slug, bool $poner): bool
    {
        $dados = (array) session(self::SESION_ME_GUSTA, []);
        $ya = in_array($slug, $dados, true);
        if ($poner && ! $ya) {
            self::sumar($slug, 'me_gusta', 1);
            $dados[] = $slug;
        } elseif (! $poner && $ya) {
            self::sumar($slug, 'me_gusta', -1);
            $dados = array_values(array_diff($dados, [$slug]));
        }
        session([self::SESION_ME_GUSTA => $dados]);

        return $poner;
    }

    public static function descarga(string $slug): void
    {
        self::sumar($slug, 'descargas', 1);
    }

    private static function sumar(string $slug, string $campo, int $cuanto): void
    {
        $ahora = now();
        DB::table(self::TABLA)->insertOrIgnore(['slug' => $slug, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $q = DB::table(self::TABLA)->where('slug', $slug);
        if ($cuanto < 0) {
            $q->where($campo, '>', 0)->update([$campo => DB::raw("{$campo} - 1"), 'updated_at' => $ahora]);
        } else {
            $q->update([$campo => DB::raw("{$campo} + {$cuanto}"), 'updated_at' => $ahora]);
        }
    }
}
