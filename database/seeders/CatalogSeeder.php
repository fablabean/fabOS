<?php

namespace Database\Seeders;

use App\Models\UserCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos maestros del laboratorio. Los valores numericos son provisionales:
 * dependen de la tarifa ancla que aun no esta definida (cuantos FabCoins vale
 * una hora de laser), de la cual se derivan las demas por proporcion (§20).
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->categories();
        $this->areas();
    }

    private function categories(): void
    {
        $rows = [
            // slug, nombre, factor, dotacion (FBC), institucional, reserva, tramite, bienvenida (FBC), semanal
            //
            // La bienvenida es con cuanto nace quien recibe la categoria; el
            // semanal, si se le completa el saldo cada lunes. Los programas de
            // Educacion Continua arrancan con mas porque traen mas trabajo al
            // laboratorio, y despues van como cualquier estudiante.
            ['estudiante',           'Estudiante',             0.5, 500, true,  true,  'estudiante', 8,  true],
            ['estudiante-bootcamp',  'Estudiante · bootcamp',  0.5, 500, true,  true,  'estudiante', 10, true],
            ['estudiante-curso',     'Estudiante · curso',     0.5, 500, true,  true,  'estudiante', 20, true],
            ['estudiante-diplomado', 'Estudiante · diplomado', 0.5, 500, true,  true,  'estudiante', 30, true],
            ['profesor',             'Profesor',               0.5, 800, true,  true,  'interno',    0,  false],
            ['colaborador',          'Colaborador',            0.0, 0,   true,  true,  'interno',    0,  false],
            ['externo',              'Externo',                2.0, 0,   false, true,  'externo',    0,  false],
            ['invitado',             'Invitado',               1.0, 0,   false, false, 'externo',    0,  false],
        ];

        foreach ($rows as $i => [$slug, $name, $factor, $allowance, $institutional, $canReserve, $tramite, $bienvenida, $semanal]) {
            UserCategory::updateOrCreate(['slug' => $slug], [
                'name'             => $name,
                'position'         => $i,
                'rate_factor'      => $factor,
                'allowance_minor'  => $allowance * config('fabos.currency.minor_units'),
                'welcome_minor'    => $bienvenida * config('fabos.currency.minor_units'),
                'weekly_benefit'   => $semanal,
                'is_institutional' => $institutional,
                'can_reserve'      => $canReserve,
                'max_days_ahead'   => $institutional ? 30 : 14,
                // Qué trámite le toca a un encargo suyo: un profesor y un
                // colaborador son institución y pagan por traslado; un
                // estudiante no pasa por nada de eso.
                'client_kind'      => $tramite,
            ]);
        }
    }

    /**
     * Siete areas reales (§7). Dentro de cada una, las familias de riesgo son
     * lo que realmente se certifica: en Impresion 3D, FDM y resina no son lo
     * mismo; en Taller, una lijadora y una sierra de banco tampoco.
     */
    private function areas(): void
    {
        $areas = [
            'impresion-3d' => ['Impresión 3D', [
                ['fdm',      'FDM (filamento)',        'byte', false],
                ['resina',   'Resina',                 'kilo', false],
                ['ceramica', 'Cerámica y arcilla',     'kilo', false],
                ['post',     'Lavado y curado',        'kilo', false],
            ]],
            'corte-laser' => ['Corte Láser', [
                ['co2',   'Láser CO₂',        'kilo', false],
                ['fibra', 'Láser de fibra',   'kilo', true],
                ['corte-vinilo', 'Corte de vinilo', 'byte', false],
            ]],
            'fresado-cnc' => ['Fresado CNC', [
                ['cnc-escritorio', 'CNC de escritorio', 'kilo', true],
                ['cnc-grande',     'CNC de formato grande', 'mega', true],
            ]],
            'prototipado' => ['Prototipado', [
                ['electronica',  'Electrónica y soldadura', 'byte', false],
                ['acabados',     'Acabados y pintura',      'byte', false],
                ['textil',       'Bordado y textil',        'byte', false],
                ['impresion-uv', 'Impresión UV y plotter',  'kilo', false],
            ]],
            'taller' => ['Taller', [
                ['herramienta-menor', 'Herramienta menor',  'byte', false],
                ['maquina-mayor',     'Máquina mayor',      'kilo', true],
            ]],
            'robots' => ['Robots', [
                ['humanoide', 'Robótica avanzada', 'mega', true],
            ]],
            'vr' => ['VR', [
                ['visores', 'Realidad virtual', 'bit', false],
            ]],
        ];

        $position = 0;

        foreach ($areas as $slug => [$name, $families]) {
            $areaId = DB::table('areas')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'position' => $position++, 'updated_at' => now(), 'created_at' => now()],
            );

            $areaId = DB::table('areas')->where('slug', $slug)->value('id');

            foreach ($families as [$fSlug, $fName, $level, $companion]) {
                DB::table('risk_families')->updateOrInsert(
                    ['area_id' => $areaId, 'slug' => $fSlug],
                    [
                        'name'                  => $fName,
                        'required_course_level' => $level,
                        'requires_companion'    => $companion,
                        'updated_at'            => now(),
                        'created_at'            => now(),
                    ],
                );
            }
        }
    }
}
