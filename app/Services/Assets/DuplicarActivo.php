<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetDependency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copiar un activo que ya existe, una o varias veces (§7).
 *
 * Llega una tanda de multímetros iguales al que ya está fichado. Volver a
 * llenar el formulario —familia de riesgo, modo de reserva, autonomía,
 * dependencias— es la clase de tarea que se hace mal a la cuarta, y una ficha
 * mal copiada es una máquina que se reserva con las reglas de otra.
 *
 * **Lo que NO se copia es tan importante como lo que sí.** La placa, el serie
 * y el QR son de CADA aparato: copiarlos inventaría datos, y dos máquinas con
 * el mismo QR mandarían a quien lo escanea a la ficha equivocada.
 */
class DuplicarActivo
{
    /** Tantas como el alta por cantidad: la misma mano, el mismo tope. */
    public const MAXIMAS = 50;

    /**
     * @return Collection<int,Asset> las copias, en orden
     */
    public function copiar(Asset $original, int $cuantas = 1): Collection
    {
        $cuantas = max(1, min($cuantas, self::MAXIMAS));

        $datos = $original->replicate([
            // De cada aparato, no del modelo: se anotan con el aparato delante.
            'asset_tag',
            'serial',
            // Y el QR es la identidad de la máquina: dos fichas con el mismo
            // token mandarían a quien lo escanea a la equivocada. Cada copia
            // se gana el suyo la primera vez que alguien lo pide.
            'qr_token',
        ])->getAttributes();

        unset($datos['id'], $datos['created_at'], $datos['updated_at'], $datos['deleted_at']);

        $base = $this->base($original->name);

        /*
         * Si se reservan, las copias son unidades equivalentes por definición:
         * quien pide «un multímetro» no pide el número tres. Sin el grupo
         * tendría que adivinar cuál está libre.
         *
         * Se le pone también al ORIGINAL: es una unidad más del montón, y
         * dejarlo fuera haría que el primero nunca se ofreciera por turno.
         */
        if ($original->is_reservable && blank($original->pool_key)) {
            $grupo = Str::slug($base) ?: Str::slug(Str::random(8));
            $datos['pool_key'] = $grupo;
        }

        $desde = $this->ultimoNumero($base) + 1;

        return DB::transaction(function () use ($original, $datos, $base, $cuantas, $desde) {
            if (isset($datos['pool_key']) && blank($original->pool_key)) {
                $original->forceFill(['pool_key' => $datos['pool_key']])->save();
            }

            /*
             * El original se renumera si no lo estaba.
             *
             * Copiar «Multímetro» deja «Multímetro» y «Multímetro 2», que se
             * lee como si el primero fuera otra cosa. Con «Multímetro 1» y
             * «Multímetro 2» se ve que son del mismo montón.
             */
            if ($original->name === $base && $this->ultimoNumero($base) === 0) {
                $original->forceFill(['name' => $base . ' 1'])->save();
                $desde = 2;
            }

            return collect(range($desde, $desde + $cuantas - 1))
                ->map(function (int $numero) use ($original, $datos, $base) {
                    $copia = Asset::create(array_merge($datos, ['name' => $base . ' ' . $numero]));

                    $this->copiarLoQueLaDefine($original, $copia);

                    return $copia;
                });
        });
    }

    /**
     * Lo que hace que la copia sea la misma máquina y no una ficha vacía.
     *
     * Las dependencias —el extractor que el láser necesita— y quiénes pueden
     * asesorar sobre ella. Sin esto la copia nace fuera del reparto de
     * asesorías y sin arrastrar lo que no funciona por separado: parece igual
     * en la lista y se comporta distinto, que es la peor clase de copia.
     *
     * No se copia nada que sea historia del aparato original: reservas,
     * mantenimientos, certifabs de gente. Eso le pasó a él, no a este.
     */
    private function copiarLoQueLaDefine(Asset $original, Asset $copia): void
    {
        foreach ($original->dependenciasConModo as $dependencia) {
            AssetDependency::create(array_merge(
                collect($dependencia->getAttributes())
                    ->except(['id', 'created_at', 'updated_at'])
                    ->all(),
                ['asset_id' => $copia->id],
            ));
        }

        $asesores = $original->advisors()
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => ['es_responsable' => (bool) $u->pivot->es_responsable]])
            ->all();

        if ($asesores !== []) {
            $copia->advisors()->sync($asesores);
        }
    }

    /**
     * El nombre sin su número, si lo lleva.
     *
     * Copiar «Multímetro 3» tiene que dar «Multímetro 4», no «Multímetro 3 1».
     */
    public function base(string $nombre): string
    {
        return trim((string) preg_replace('/\s+\d+$/', '', trim($nombre)));
    }

    /** El número más alto que ya lleva una unidad de este nombre. */
    private function ultimoNumero(string $base): int
    {
        return (int) Asset::query()
            ->where('name', 'like', $base . ' %')
            ->pluck('name')
            ->map(fn (string $nombre) => (int) Str::of($nombre)->after($base . ' ')->trim()->toString())
            ->max();
    }
}
