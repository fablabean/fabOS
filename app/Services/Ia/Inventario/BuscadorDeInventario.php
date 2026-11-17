<?php

namespace App\Services\Ia\Inventario;

use App\Models\Asset;
use App\Models\Supply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Lo que de verdad hay en el inventario, preguntado a la base.
 *
 * La IA no sabe qué hay registrado y no debe suponerlo: cuando lo necesita,
 * le pide a estas funciones, y lo que contesta sale de aquí. Todo es de solo
 * lectura.
 *
 * Los posibles duplicados también se buscan aquí y no se le preguntan al
 * modelo. Decir «esto ya existe» con una serie que coincide es una regla, y
 * una regla se escribe en código: no se deja a que el modelo se acuerde.
 */
class BuscadorDeInventario
{
    public const LIMITE = 15;

    public function __construct(private CatalogoDeInventario $catalogo) {}

    /**
     * @param  array{texto?:?string,area_id?:?int,estado?:?string,tipo?:?string,limite?:?int}  $filtros
     * @return list<array<string,mixed>>
     */
    public function activos(array $filtros): array
    {
        $consulta = Asset::query()->with(['area', 'location', 'space']);

        if (filled($filtros['texto'] ?? null)) {
            $this->porPalabras($consulta, (string) $filtros['texto'], ['name', 'brand', 'model', 'serial', 'asset_tag']);
        }

        if (filled($filtros['area_id'] ?? null)) {
            $consulta->where('area_id', (int) $filtros['area_id']);
        }

        if (filled($filtros['estado'] ?? null) && isset(Asset::ESTADOS[$filtros['estado']])) {
            $consulta->where('status', $filtros['estado']);
        }

        if (filled($filtros['tipo'] ?? null) && isset(Asset::TIPOS[$filtros['tipo']])) {
            $consulta->where('kind', $filtros['tipo']);
        }

        return $consulta->orderBy('name')
            ->limit($this->limite($filtros['limite'] ?? null))
            ->get()
            ->map(fn (Asset $a) => $this->fichaDeActivo($a))
            ->all();
    }

    /**
     * @param  array{texto?:?string,area_id?:?int,solo_bajo_minimo?:?bool,limite?:?int}  $filtros
     * @return list<array<string,mixed>>
     */
    public function insumos(array $filtros): array
    {
        $consulta = Supply::query()->with(['area', 'location', 'category']);

        if (filled($filtros['texto'] ?? null)) {
            $this->porPalabras($consulta, (string) $filtros['texto'], ['name', 'sku', 'description']);
        }

        if (filled($filtros['area_id'] ?? null)) {
            $consulta->where('area_id', (int) $filtros['area_id']);
        }

        if ($filtros['solo_bajo_minimo'] ?? false) {
            $consulta->whereNotNull('reorder_point')->whereColumn('stock', '<=', 'reorder_point');
        }

        return $consulta->orderBy('name')
            ->limit($this->limite($filtros['limite'] ?? null))
            ->get()
            ->map(fn (Supply $s) => $this->fichaDeInsumo($s))
            ->all();
    }

    /**
     * Registros que podrían ser el mismo que se quiere crear.
     *
     * Ordenados de la coincidencia más fuerte a la más débil. Una coincidencia
     * **fuerte** —misma serie, misma placa, mismo código— casi seguro es el
     * mismo objeto, y no se deja crear otro sin que la persona diga que lo
     * revisó.
     *
     * @param  array<string,mixed>  $datos
     * @return list<array{id:int,nombre:string,motivo:string,fuerte:bool,ficha:array<string,mixed>}>
     */
    public function posiblesDuplicados(string $tipo, string $nombre, array $datos): array
    {
        $encontrados = [];

        $anotar = function ($registro, string $motivo, bool $fuerte) use (&$encontrados, $tipo) {
            $id = $registro->id;

            if (isset($encontrados[$id])) {
                $encontrados[$id]['fuerte'] = $encontrados[$id]['fuerte'] || $fuerte;
                $encontrados[$id]['motivo'] .= ' · ' . $motivo;

                return;
            }

            $encontrados[$id] = [
                'id' => $id,
                'nombre' => $registro->name,
                'motivo' => $motivo,
                'fuerte' => $fuerte,
                'ficha' => $tipo === 'activo' ? $this->fichaDeActivo($registro) : $this->fichaDeInsumo($registro),
            ];
        };

        if ($tipo === 'activo') {
            $base = fn () => Asset::query()->with(['area', 'location', 'space']);

            if (filled($datos['serie'] ?? null)) {
                $base()->whereRaw('lower(serial) = ?', [Str::lower(trim($datos['serie']))])
                    ->get()->each(fn ($a) => $anotar($a, 'misma serie', true));
            }

            if (filled($datos['placa'] ?? null)) {
                $base()->whereRaw('lower(asset_tag) = ?', [Str::lower(trim($datos['placa']))])
                    ->get()->each(fn ($a) => $anotar($a, 'misma placa', true));
            }

            if (filled($datos['marca'] ?? null) && filled($datos['modelo'] ?? null)) {
                $base()->where('brand', 'ilike', '%' . trim($datos['marca']) . '%')
                    ->where('model', 'ilike', '%' . trim($datos['modelo']) . '%')
                    ->limit(10)->get()->each(fn ($a) => $anotar($a, 'misma marca y modelo', false));
            }

            // El mismo nombre exacto: dos fichas iguales no se distinguen en ninguna lista.
            if (filled($nombre)) {
                $base()->whereRaw("lower(trim(name)) = ?", [Str::lower(Str::squish($nombre))])
                    ->get()->each(fn ($a) => $anotar($a, 'mismo nombre', true));
            }

            if (filled($nombre)) {
                $consulta = $base();
                $this->porPalabras($consulta, $nombre, ['name']);
                $consulta->limit(10)->get()->each(fn ($a) => $anotar($a, 'nombre parecido', false));
            }
        } else {
            $base = fn () => Supply::query()->with(['area', 'location', 'category']);

            if (filled($datos['sku'] ?? null)) {
                $base()->whereRaw('lower(sku) = ?', [Str::lower(trim($datos['sku']))])
                    ->get()->each(fn ($s) => $anotar($s, 'mismo código', true));
            }

            // El mismo nombre exacto es el mismo insumo registrado otra vez.
            if (filled($nombre)) {
                $base()->whereRaw("lower(trim(name)) = ?", [Str::lower(Str::squish($nombre))])
                    ->get()->each(fn ($s) => $anotar($s, 'mismo nombre', true));
            }

            if (filled($nombre)) {
                $consulta = $base();
                $this->porPalabras($consulta, $nombre, ['name']);
                $consulta->limit(10)->get()->each(fn ($s) => $anotar($s, 'nombre parecido', false));
            }
        }

        return collect($encontrados)
            ->sortByDesc(fn ($d) => $d['fuerte'])
            ->take(6)
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    public function fichaDeActivo(Asset $a): array
    {
        return [
            'id' => $a->id,
            'numero' => $a->numero(),
            'nombre' => $a->name,
            'tipo' => Asset::TIPOS[$a->kind] ?? $a->kind,
            'area' => $a->area?->name,
            'espacio' => $a->space?->name,
            'ubicacion' => $a->location_id ? ($this->catalogo->rutas()[$a->location_id] ?? $a->location?->name) : null,
            'estado' => Asset::ESTADOS[$a->status] ?? $a->status,
            'marca' => $a->brand,
            'modelo' => $a->model,
            'serie' => $a->serial,
            'placa' => $a->asset_tag,
        ];
    }

    /** @return array<string,mixed> */
    public function fichaDeInsumo(Supply $s): array
    {
        return [
            'id' => $s->id,
            'nombre' => $s->name,
            'codigo' => $s->sku,
            'categoria' => $s->category?->name,
            'area' => $s->area?->name,
            'ubicacion' => $s->location_id ? ($this->catalogo->rutas()[$s->location_id] ?? $s->location?->name) : null,
            'unidad' => $s->unit,
            'existencia' => (float) $s->stock,
            'minimo' => $s->reorder_point !== null ? (float) $s->reorder_point : null,
            'bajo_minimo' => $s->estaBajoMinimo(),
            'falta_para_el_minimo' => $s->estaBajoMinimo()
                ? round((float) $s->reorder_point - (float) $s->stock, 3)
                : 0,
        ];
    }

    /**
     * Todas las palabras tienen que aparecer, en cualquiera de las columnas.
     *
     * «impresora creality» encuentra «Creality K1 — Impresora 3D» aunque las
     * palabras vengan en otro orden, que es como las dice quien tiene el
     * equipo delante.
     */
    private function porPalabras(Builder $consulta, string $texto, array $columnas): void
    {
        $palabras = collect(preg_split('/\s+/u', Str::of($texto)->squish()->limit(120, '')->value()))
            ->filter(fn ($p) => mb_strlen($p) >= 2)
            ->take(6);

        foreach ($palabras as $palabra) {
            $consulta->where(function (Builder $q) use ($palabra, $columnas) {
                foreach ($columnas as $columna) {
                    $q->orWhere($columna, 'ilike', '%' . addcslashes($palabra, '%_\\') . '%');
                }
            });
        }
    }

    private function limite(mixed $pedido): int
    {
        return max(1, min((int) ($pedido ?: 10), self::LIMITE));
    }
}
