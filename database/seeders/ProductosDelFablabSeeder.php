<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Supply;
use App\Models\SupplyCategory;
use Illuminate\Database\Seeder;

/**
 * Productos que el laboratorio sabe fabricar y todavía no vendía (§14).
 *
 * El catálogo tenía treinta y un productos, casi todos de dos máquinas:
 * impresión 3D decorativa y acrílico cortado. Las otras seis áreas no vendían
 * **nada**: dos bordadoras y una termofijadora sin un solo textil, un láser de
 * fibra sin una sola pieza en metal, tres fresadoras sin nada en madera, una
 * impresora UV, un plóter de gran formato y una Cricut sin producto propio.
 *
 * No es que faltara catálogo: faltaba mirar el inventario de máquinas y
 * preguntarse qué se puede vender con cada una.
 *
 * ## Lo que este seeder NO toca
 *
 * **Los productos que ya existen.** Ni sus precios, ni sus costos, ni su
 * clasificación. Están bien como están y son decisión del laboratorio; esto
 * solo añade lo que faltaba.
 *
 * ## Por qué van por encargo y sin existencia
 *
 * Un fablab no tiene cien llaveros en un cajón: los hace cuando se los piden.
 * Entran con `por_encargo` y su plazo, que es lo que permite ofrecerlos sin
 * mentir sobre lo que hay. El día que se produzca un lote, se le carga
 * existencia y pasan a llevarse el mismo día sin cambiar nada más.
 *
 * ## Sobre los costos
 *
 * Son **estimaciones de producción** —material más tiempo de máquina— y el
 * precio sale de ahí con el margen del sistema. La tienda los marca como
 * estimados a propósito: se corrigen cuando llegue la primera compra real del
 * material o cuando alguien cronometre la primera pieza.
 */
class ProductosDelFablabSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->productos() as $fila) {
            if (Supply::where('sku', $fila['sku'])->exists()) {
                $this->command?->line("  · ya existe: {$fila['nombre']}");

                continue;
            }

            $producto = Supply::create([
                'sku' => $fila['sku'],
                'name' => $fila['nombre'],
                'kind' => 'producto',
                'unit' => 'unidad',
                'area_id' => Area::where('name', $fila['area'])->value('id'),
                'category_id' => $this->categoria($fila['categoria']),
                'public_description' => $fila['descripcion'],
                'last_cost' => $fila['costo'],
                // Se fabrica cuando lo piden: sin existencia y con su plazo.
                'stock' => 0,
                'por_encargo' => true,
                'dias_por_encargo' => $fila['dias'],
                'is_active' => true,
                // Despublicado: los costos son estimaciones mias, no una
                // decision del laboratorio. Alguien los mira antes de que un
                // cliente de fuera los vea.
                'is_public' => false,
            ]);

            foreach ($fila['escalones'] ?? [] as $desde => $pesos) {
                $producto->priceBreaks()->create([
                    'min_quantity' => $desde,
                    'price_minor' => (int) round($pesos / (int) config('fabos.currency.peso_rate')
                        * (int) config('fabos.currency.minor_units')),
                ]);
            }

            $this->command?->info("  ✓ {$fila['nombre']}");
        }
    }

    private function categoria(string $nombre): int
    {
        return SupplyCategory::firstOrCreate(['name' => $nombre])->id;
    }

    /**
     * Lo que se puede vender con cada máquina que hoy no vende nada.
     *
     * El costo es de producción, en pesos; el precio sale de ahí con el margen
     * del sistema. Los escalones son `desde => precio unitario final`, y
     * existen donde el volumen cambia de verdad el trabajo: veinte gorras se
     * bordan con un solo montaje, y cien stickers salen de la misma lámina.
     *
     * @return list<array<string,mixed>>
     */
    private function productos(): array
    {
        return [
            // ---- Bordado y textil: dos bordadoras y una termofijadora sin un
            //      solo producto en el catalogo.
            [
                'sku' => 'TEX-GORRA-BORD', 'area' => 'Estampado y bordado', 'categoria' => 'Textil y bordado',
                'nombre' => 'Gorra bordada con logo',
                'descripcion' => 'Gorra de algodón con tu logo bordado en hilo. El bordado no se despega ni se decolora como un estampado.',
                'costo' => 32000, 'dias' => 5,
                'escalones' => [12 => 36000, 50 => 31000],
            ],
            [
                'sku' => 'TEX-CAM-VINILO', 'area' => 'Estampado y bordado', 'categoria' => 'Textil y bordado',
                'nombre' => 'Camiseta con vinilo textil',
                'descripcion' => 'Algodón, en cualquier color. Ideal para nombres, números y logos de pocos colores: aguanta lavadas sin agrietarse.',
                'costo' => 26000, 'dias' => 4,
                'escalones' => [12 => 29000, 50 => 25000],
            ],
            [
                'sku' => 'TEX-TOTE-BORD', 'area' => 'Estampado y bordado', 'categoria' => 'Textil y bordado',
                'nombre' => 'Tote bag bordada',
                'descripcion' => 'Bolsa de lona con bordado. El regalo corporativo que la gente sí vuelve a usar.',
                'costo' => 22000, 'dias' => 5,
                'escalones' => [12 => 25000, 50 => 21000],
            ],
            [
                'sku' => 'TEX-PARCHE', 'area' => 'Estampado y bordado', 'categoria' => 'Textil y bordado',
                'nombre' => 'Parche bordado termoadhesivo',
                'descripcion' => 'Se plancha sobre cualquier prenda. Para uniformes, semilleros y grupos estudiantiles.',
                'costo' => 6000, 'dias' => 5,
                'escalones' => [25 => 6500, 100 => 5200],
            ],

            // ---- Metal: el laser de fibra xTool F1 Ultra, sin una sola pieza.
            [
                'sku' => 'MET-LLAVERO', 'area' => 'Corte y grabado', 'categoria' => 'Metal',
                'nombre' => 'Llavero metálico grabado',
                'descripcion' => 'Acero inoxidable con marcado láser permanente. No se borra con el uso, a diferencia del grabado por impresión.',
                'costo' => 9000, 'dias' => 3,
                'escalones' => [25 => 9500, 100 => 7500],
            ],
            [
                'sku' => 'MET-PLACA', 'area' => 'Corte y grabado', 'categoria' => 'Metal',
                'nombre' => 'Placa en acero inoxidable grabada',
                'descripcion' => 'Para señalización de equipos, placas conmemorativas y fichas técnicas que tienen que durar años a la intemperie.',
                'costo' => 38000, 'dias' => 4,
                'escalones' => [10 => 42000],
            ],
            [
                'sku' => 'MET-PIN', 'area' => 'Corte y grabado', 'categoria' => 'Metal',
                'nombre' => 'Pin o distintivo metálico',
                'descripcion' => 'Distintivos para eventos, grados y reconocimientos. Cortado y marcado en el mismo láser de fibra.',
                'costo' => 7000, 'dias' => 4,
                'escalones' => [25 => 7500, 100 => 5800],
            ],

            // ---- Madera y CNC: tres fresadoras sin producto propio.
            [
                'sku' => 'CNC-LETRAS', 'area' => 'Fresado CNC', 'categoria' => 'Madera',
                'nombre' => 'Letra corpórea en madera',
                'descripcion' => 'Letras y logos en volumen para fachadas, stands y salas. Se cobra por letra; el precio baja con el tamaño del pedido.',
                'costo' => 22000, 'dias' => 7,
                'escalones' => [10 => 24000, 30 => 20000],
            ],
            [
                'sku' => 'CNC-TABLA', 'area' => 'Fresado CNC', 'categoria' => 'Madera',
                'nombre' => 'Tabla de picar personalizada',
                'descripcion' => 'Madera maciza con grabado a la medida. Regalo de grado y de fin de año que no termina en un cajón.',
                'costo' => 45000, 'dias' => 7,
                'escalones' => [10 => 54000],
            ],
            [
                'sku' => 'CNC-SENAL', 'area' => 'Fresado CNC', 'categoria' => 'Madera',
                'nombre' => 'Señalética en madera',
                'descripcion' => 'Letreros de puerta, directorios y señalización interna, fresados y con acabado. A la medida del espacio.',
                'costo' => 35000, 'dias' => 7,
            ],

            // ---- Impresion UV sobre objeto: la Eufymake, sin producto.
            [
                'sku' => 'UV-TERMO', 'area' => 'Impresión 2D', 'categoria' => 'Impresión sobre objeto',
                'nombre' => 'Termo personalizado',
                'descripcion' => 'Impresión UV a color directamente sobre el termo, con relieve si se quiere. No es calcomanía: la tinta se cura sobre el metal.',
                'costo' => 42000, 'dias' => 4,
                'escalones' => [12 => 47000, 50 => 41000],
            ],
            [
                'sku' => 'UV-LIBRETA', 'area' => 'Impresión 2D', 'categoria' => 'Impresión sobre objeto',
                'nombre' => 'Libreta con tapa impresa',
                'descripcion' => 'Tapa rígida impresa a color. Para kits de bienvenida, congresos y cursos.',
                'costo' => 24000, 'dias' => 4,
                'escalones' => [25 => 26000, 100 => 22000],
            ],

            // ---- Gran formato: la Canon TC-20, sin producto.
            [
                'sku' => 'GF-POSTER', 'area' => 'Impresión 2D', 'categoria' => 'Papelería y gran formato',
                'nombre' => 'Póster académico para congreso',
                'descripcion' => 'El formato estándar de congreso, impreso en papel de calidad. Lo entregamos enrollado y listo para colgar.',
                'costo' => 35000, 'dias' => 2,
                'escalones' => [5 => 38000],
            ],

            // ---- Vinilo: la Cricut Maker, sin producto.
            [
                'sku' => 'VIN-STICKERS', 'area' => 'Corte y grabado', 'categoria' => 'Papelería y gran formato',
                'nombre' => 'Set de stickers troquelados',
                'descripcion' => 'Calcomanías cortadas con tu diseño, en vinilo resistente al agua. Para semilleros, eventos y marca propia.',
                'costo' => 12000, 'dias' => 3,
                'escalones' => [10 => 13000, 50 => 9500],
            ],

            // ---- Laser y 3D: productos que las maquinas de siempre no vendian.
            [
                'sku' => 'LAS-ROMPE', 'area' => 'Corte y grabado', 'categoria' => 'Merchandising',
                'nombre' => 'Rompecabezas en MDF',
                'descripcion' => 'Con la imagen que quieras grabada. Se usa como material didáctico y como recuerdo de evento.',
                'costo' => 18000, 'dias' => 4,
                'escalones' => [10 => 19000],
            ],
            [
                'sku' => 'LAS-ORGANIZA', 'area' => 'Corte y grabado', 'categoria' => 'Merchandising',
                'nombre' => 'Organizador de escritorio en acrílico',
                'descripcion' => 'Portalápices, portacelular y bandeja en una pieza. Se puede grabar con el logo de quien lo regala.',
                'costo' => 28000, 'dias' => 4,
            ],
            [
                'sku' => 'LAS-MAPA', 'area' => 'Corte y grabado', 'categoria' => 'Merchandising',
                'nombre' => 'Mapa topográfico en capas',
                'descripcion' => 'Relieve de un terreno construido en capas de MDF. Para facultades de ingeniería, arquitectura y ciencias de la tierra.',
                'costo' => 65000, 'dias' => 8,
            ],

            // ---- Electronica: veintitres equipos y ningun producto.
            [
                'sku' => 'ELE-KIT', 'area' => 'Electrónica', 'categoria' => 'Electrónica',
                'nombre' => 'Kit educativo de electrónica básica',
                'descripcion' => 'Placa, componentes y guía para un curso introductorio. Se arma en el laboratorio y se lleva funcionando.',
                'costo' => 55000, 'dias' => 6,
                'escalones' => [15 => 60000, 40 => 52000],
            ],
        ];
    }
}
