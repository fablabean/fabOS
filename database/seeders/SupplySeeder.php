<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Supply;
use Illuminate\Database\Seeder;

/**
 * Insumos base del laboratorio (§7, §13).
 *
 * Los que ya tienen tarifa de material en `TariffSeeder`, para que lo que se
 * cobra y lo que se repone hablen del mismo insumo. Las existencias arrancan en
 * cero a propósito: el conteo físico es de la coordinación, y sembrar cifras
 * inventadas haría que el inventario naciera mintiendo.
 *
 * Los puntos de reposición sí son supuestos razonables —lo que alcanza para más
 * o menos un mes de operación—, y se ajustan desde el backoffice.
 */
class SupplySeeder extends Seeder
{
    public function run(): void
    {
        $areas = Area::pluck('id', 'slug');

        $insumos = [
            // slug de área, nombre, unidad, punto de reposición, costo de referencia
            ['impresion-3d', 'Filamento PLA',                  'kg',    4,   90_000],
            ['impresion-3d', 'Filamento PETG',                 'kg',    2,  110_000],
            ['impresion-3d', 'Filamento técnico (ABS, TPU)',   'kg',    1,  180_000],
            ['impresion-3d', 'Resina estándar',                'ml', 2000,      280],
            ['impresion-3d', 'Alcohol isopropílico',           'ml', 2000,       35],
            ['corte-laser',  'Acrílico 3 mm (hoja 60×40)',     'hoja',  6,   25_000],
            ['corte-laser',  'MDF 3 mm (hoja 60×40)',          'hoja', 10,   12_000],
            ['corte-laser',  'Vinilo adhesivo',                'm',    10,    8_000],
            ['prototipado',  'Estaño para soldadura',          'm',    20,    1_200],
            ['prototipado',  'Lija (pliego)',                  'hoja', 20,    2_500],
            ['prototipado',  'Pintura en aerosol',             'unidad', 6,  22_000],
            ['taller',       'Guantes de nitrilo (par)',       'par',  50,    1_500],
            ['taller',       'Tapabocas',                      'unidad', 50,  1_000],
        ];

        foreach ($insumos as [$area, $nombre, $unidad, $minimo, $costo]) {
            Supply::firstOrCreate(
                ['name' => $nombre],
                [
                    'area_id'       => $areas[$area] ?? null,
                    'unit'          => $unidad,
                    'stock'         => 0,
                    'reorder_point' => $minimo,
                    'last_cost'     => $costo,
                    'is_active'     => true,
                ],
            );
        }
    }
}
