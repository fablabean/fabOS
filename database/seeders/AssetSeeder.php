<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Asset;
use App\Models\RiskFamily;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catálogo real del Ean Fablab (§7), a partir del inventario levantado.
 *
 * Cada fila declara lo que el motor de reservas necesita saber:
 *   familia      → riesgo dentro del área; es lo que se certifica
 *   reservable   → false en accesorios: se inventarían, no se agendan
 *   desatendido  → el trabajo corre sin la persona presente
 *   pool         → unidades equivalentes: se reserva "una", no la #3
 *   unidades     → cuántas piezas idénticas existen
 *
 * Los nombres y cantidades salen del listado de la coordinación y deben
 * confirmarse contra el inventario físico en fase 0.
 */
class AssetSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogo() as $areaSlug => $filas) {
            $areaId = Area::where('slug', $areaSlug)->value('id');

            foreach ($filas as $fila) {
                $this->crear($areaId, $fila);
            }
        }

        $this->dependencias();
    }

    private function crear(?int $areaId, array $f): void
    {
        $familiaId = isset($f['familia'])
            ? RiskFamily::where('area_id', $areaId)->where('slug', $f['familia'])->value('id')
            : null;

        $unidades = $f['unidades'] ?? 1;

        for ($i = 1; $i <= $unidades; $i++) {
            $nombre = $unidades > 1 ? "{$f['nombre']} {$i}" : $f['nombre'];

            Asset::updateOrCreate(
                ['name' => $nombre, 'area_id' => $areaId],
                [
                    'risk_family_id'     => $familiaId,
                    'kind'               => $f['tipo'] ?? 'fijo',
                    'status'             => 'operativo',
                    'is_reservable'      => $f['reservable'] ?? true,
                    'unattended_use'     => $f['desatendido'] ?? false,
                    'pool_key'           => $f['pool'] ?? null,
                    'min_minutes'        => $f['min'] ?? 30,
                    'autonomous_minutes' => $f['autonomo'] ?? 60,
                    'max_minutes'        => $f['max'] ?? 720,
                    'qr_token'           => Str::uuid()->toString(),
                ]
            );
        }
    }

    /** Sin compresor no hay CNC; sin aspiradora no hay láser (§7). */
    private function dependencias(): void
    {
        $pares = [
            ['Syntec Grande', 'Compresor Pequeño'],
            ['Carvera Makera', 'Compresor Pequeño'],
            ['Carvera Air', 'Compresor Pequeño'],
            ['Máquina de corte Láser', 'Aspiradora'],
        ];

        foreach ($pares as [$principal, $soporte]) {
            $a = Asset::where('name', $principal)->first();
            $b = Asset::where('name', $soporte)->first();

            if ($a && $b) {
                $a->dependencies()->syncWithoutDetaching([
                    $b->id => ['note' => 'Debe estar operativo para poder usar el equipo'],
                ]);
            }
        }
    }

    private function catalogo(): array
    {
        return [
            'impresion-3d' => [
                ['nombre' => 'Secador de Filamento', 'unidades' => 2, 'familia' => 'fdm', 'reservable' => false],
                ['nombre' => 'Palette 3', 'familia' => 'fdm', 'reservable' => false],
                ['nombre' => 'Soplador de aire (Resina)', 'familia' => 'resina', 'reservable' => false],
                ['nombre' => 'Sopladora de aire (Resina)', 'familia' => 'resina', 'reservable' => false],
                ['nombre' => 'CFS extras', 'familia' => 'fdm', 'reservable' => false],

                ['nombre' => 'Elegoo Neptune 3 Max', 'familia' => 'fdm', 'desatendido' => true],
                ['nombre' => 'Elegoo Neptune 4 Max S', 'unidades' => 2, 'familia' => 'fdm', 'desatendido' => true, 'pool' => 'neptune-4-max'],
                ['nombre' => 'BambuLab A1 AMS lite', 'unidades' => 2, 'familia' => 'fdm', 'desatendido' => true, 'pool' => 'bambulab-a1'],
                ['nombre' => 'Creality Hi Combo', 'unidades' => 4, 'familia' => 'fdm', 'desatendido' => true, 'pool' => 'creality-hi-combo'],
                ['nombre' => 'Creality K1 Max', 'familia' => 'fdm', 'desatendido' => true],
                ['nombre' => 'Ender 3 v3 SE', 'unidades' => 2, 'familia' => 'fdm', 'desatendido' => true, 'pool' => 'ender-3-v3-se'],
                ['nombre' => 'FUSION T1 Pro', 'familia' => 'fdm', 'desatendido' => true],

                ['nombre' => 'Toycr (Arcilla o Cerámica)', 'familia' => 'ceramica', 'desatendido' => true],

                ['nombre' => 'Elegoo Saturn', 'familia' => 'resina', 'desatendido' => true],
                ['nombre' => 'Elegoo Saturn 5', 'familia' => 'resina', 'desatendido' => true],
                ['nombre' => 'Anycubic PHOTON M3 MAX', 'familia' => 'resina', 'desatendido' => true],
                ['nombre' => 'Anycubic Lavado y Curado 2', 'familia' => 'post', 'min' => 15],
            ],

            'corte-laser' => [
                ['nombre' => 'Máquina de corte Láser', 'familia' => 'co2'],
                ['nombre' => 'Aspiradora', 'familia' => 'co2', 'reservable' => false],
                ['nombre' => 'Máquina de Fibra xTool F1 Ultra', 'familia' => 'fibra'],
                ['nombre' => 'Cricut Maker 3 Plotter', 'familia' => 'corte-vinilo', 'autonomo' => 120],
            ],

            'fresado-cnc' => [
                ['nombre' => 'Syntec Grande', 'familia' => 'cnc-grande', 'min' => 60],
                ['nombre' => 'Carvera Makera', 'familia' => 'cnc-escritorio'],
                ['nombre' => 'Carvera Air', 'familia' => 'cnc-escritorio'],
                ['nombre' => 'Compresor Pequeño', 'familia' => 'cnc-escritorio', 'reservable' => false],
            ],

            'prototipado' => [
                ['nombre' => 'Rotuladora Digital', 'familia' => 'impresion-uv'],
                ['nombre' => 'Eufymake impresora UV', 'familia' => 'impresion-uv'],
                ['nombre' => 'Impresora Canon TC-20', 'familia' => 'impresion-uv'],
                ['nombre' => 'Impresora Epson L3251', 'familia' => 'impresion-uv'],
                ['nombre' => 'Brother Máquina de bordar', 'familia' => 'textil'],
                ['nombre' => 'Termofijadora', 'familia' => 'textil'],
                ['nombre' => 'Aerógrafo', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'Pistola de aire Pro', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'Pistola de aire lite', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'Estación de soldadura Baku', 'familia' => 'electronica'],
                ['nombre' => 'Cautín', 'unidades' => 3, 'familia' => 'electronica', 'tipo' => 'herramienta', 'pool' => 'cautin'],
                ['nombre' => 'Cautín Inalámbrico', 'familia' => 'electronica', 'tipo' => 'herramienta'],
                ['nombre' => 'Motor Tool Inalámbrico', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'MotorTool', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'MultiTool', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'Taladro Inalámbrico sin escobillas', 'familia' => 'acabados', 'tipo' => 'herramienta'],
                ['nombre' => 'Taladro Inalámbrico (Prototipado)', 'familia' => 'acabados', 'tipo' => 'herramienta'],
            ],

            'taller' => [
                ['nombre' => 'Sierra de Banco', 'familia' => 'maquina-mayor'],
                ['nombre' => 'Sierra caladora de banco', 'familia' => 'maquina-mayor'],
                ['nombre' => 'Ingleteadora', 'unidades' => 2, 'familia' => 'maquina-mayor', 'pool' => 'ingleteadora'],
                ['nombre' => 'Taladro de árbol', 'familia' => 'maquina-mayor'],
                ['nombre' => 'Pulidora (Esmeriladora angular)', 'familia' => 'maquina-mayor', 'tipo' => 'herramienta'],
                ['nombre' => 'Lijadora de Banda mesa y disco lateral', 'familia' => 'maquina-mayor'],
                ['nombre' => 'Lijadora de Banda móvil', 'familia' => 'herramienta-menor', 'tipo' => 'herramienta'],
                ['nombre' => 'Lijadora Orbital', 'familia' => 'herramienta-menor', 'tipo' => 'herramienta'],
                ['nombre' => 'Caladora', 'familia' => 'herramienta-menor', 'tipo' => 'herramienta'],
                ['nombre' => 'Taladro Dewalt', 'familia' => 'herramienta-menor', 'tipo' => 'herramienta'],
                ['nombre' => 'Taladro inalámbrico (Taller)', 'familia' => 'herramienta-menor', 'tipo' => 'herramienta'],
                ['nombre' => 'Herramientas de mano', 'familia' => 'herramienta-menor', 'tipo' => 'herramienta', 'reservable' => false],
                ['nombre' => 'Compresor', 'familia' => 'herramienta-menor', 'reservable' => false],
            ],

            'robots' => [
                ['nombre' => 'Robot Unitree Go2', 'familia' => 'humanoide', 'min' => 60, 'autonomo' => 0],
                ['nombre' => 'Robot Unitree G1 EDU U6', 'familia' => 'humanoide', 'min' => 60, 'autonomo' => 0],
            ],

            'vr' => [
                ['nombre' => 'Meta Quest 2', 'unidades' => 6, 'familia' => 'visores', 'tipo' => 'herramienta', 'pool' => 'meta-quest-2'],
                ['nombre' => 'Meta Quest 3', 'unidades' => 6, 'familia' => 'visores', 'tipo' => 'herramienta', 'pool' => 'meta-quest-3'],
                ['nombre' => 'Kinect', 'familia' => 'visores', 'tipo' => 'herramienta'],
                ['nombre' => 'Televisor táctil', 'familia' => 'visores'],
                ['nombre' => 'Disco duro', 'familia' => 'visores', 'tipo' => 'herramienta', 'reservable' => false],
            ],
        ];
    }
}
