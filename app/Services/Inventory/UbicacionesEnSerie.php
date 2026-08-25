<?php

namespace App\Services\Inventory;

use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Crear muchas ubicaciones iguales de una vez (§7).
 *
 * Un rack tiene dieciséis gavetas y todas se llaman igual salvo el número.
 * Teclearlas una por una es trabajo de copiadora, y además invita a errores
 * que luego cuestan: una gaveta que se salta, dos con el mismo nombre.
 */
final class UbicacionesEnSerie
{
    /** Un tope sensato: más que esto casi siempre es un cero de más al teclear. */
    public const MAXIMO = 200;

    /**
     * @return array{creadas: list<string>, omitidas: list<string>}
     */
    public function crear(
        Location $padre,
        string $base,
        int $cantidad,
        int $desde = 1,
        bool $rellenarCeros = true,
    ): array {
        $cantidad = max(1, min($cantidad, self::MAXIMO));

        $nombres = $this->nombres($base, $cantidad, $desde, $rellenarCeros);

        // Los que ya existen bajo ese mismo padre. Repetirlos crearía dos
        // gavetas «03» y ninguna forma de saber cuál es cuál.
        $existentes = Location::where('parent_id', $padre->id)
            ->whereIn('name', $nombres)
            ->pluck('name')
            ->all();

        $creadas = [];

        DB::transaction(function () use ($nombres, $existentes, $padre, &$creadas) {
            foreach ($nombres as $nombre) {
                if (in_array($nombre, $existentes, true)) {
                    continue;
                }

                Location::create([
                    'name'      => $nombre,
                    'parent_id' => $padre->id,
                    // El espacio no se toca: lo hereda del padre.
                ]);

                $creadas[] = $nombre;
            }
        });

        return ['creadas' => $creadas, 'omitidas' => $existentes];
    }

    /**
     * Los nombres que se van a crear.
     *
     * Se rellena con ceros por defecto, y no es cosmético: ordenado por nombre,
     * «Gaveta 10» va antes que «Gaveta 2». Con «Gaveta 02» la lista sale en el
     * orden en que están físicamente, que es como la gente las busca.
     *
     * @return list<string>
     */
    public function nombres(string $base, int $cantidad, int $desde = 1, bool $rellenarCeros = true): array
    {
        $base = trim($base);
        $ultimo = $desde + $cantidad - 1;
        $ancho = $rellenarCeros ? strlen((string) $ultimo) : 1;

        $nombres = [];

        for ($n = $desde; $n <= $ultimo; $n++) {
            $nombres[] = trim($base . ' ' . str_pad((string) $n, $ancho, '0', STR_PAD_LEFT));
        }

        return $nombres;
    }

    /** Un vistazo de cómo van a quedar, sin crear nada. */
    public function vistaPrevia(string $base, int $cantidad, int $desde = 1, bool $rellenarCeros = true): string
    {
        if ($base === '' || $cantidad < 1) {
            return '';
        }

        $nombres = collect($this->nombres($base, min($cantidad, self::MAXIMO), $desde, $rellenarCeros));

        return $nombres->count() <= 4
            ? $nombres->implode(' · ')
            : $nombres->take(2)->implode(' · ') . ' · … · ' . $nombres->last();
    }
}
