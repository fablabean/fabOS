<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\ServiceOffering;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * El catálogo de servicios del laboratorio (§14).
 *
 * La tienda tenía **un** servicio publicado y noventa equipos públicos. Lo que
 * el laboratorio sabe hacer no estaba en ninguna parte donde alguien de fuera
 * pudiera verlo, y un catálogo que no existe no se cotiza: se pregunta por
 * correo, o no se pregunta.
 *
 * ## De dónde sale cada precio
 *
 * Tres capas, y conviene no confundirlas:
 *
 *  1. **El costo de máquina** ya estaba decidido: `TariffSeeder` ancló la hora
 *     de láser CO₂ en 20 FabCoins y puso el resto en proporción. Eso es lo que
 *     cuesta ocupar el equipo, y no se toca aquí.
 *  2. **El precio de venta** suma a eso el material, el montaje y el manejo.
 *     Por eso un minuto de láser vendido vale más que el minuto de máquina:
 *     alguien prepara el archivo, monta la lámina y responde por el resultado.
 *  3. **La referencia del mercado** dice si esa cifra es defendible. Se guarda
 *     aparte, con fuente y fecha, porque la pregunta que llega no es «¿cómo lo
 *     calcularon?» sino «¿está caro?».
 *
 * Las referencias se consultaron en septiembre de 2026 y **caducan**: el
 * modelo las marca viejas al año. No son el precio, son con qué se comparó.
 *
 * ## Qué NO hace este seeder
 *
 * **No pisa lo que ya existe.** Si alguien ajustó un precio desde el panel,
 * volver a sembrar no le borra el trabajo: se salta el servicio entero, con
 * sus escalones y sus referencias.
 *
 * Y **nace todo despublicado**. Estos precios son una propuesta calculada, no
 * una decisión del laboratorio: alguien tiene que mirarlos antes de que un
 * cliente de fuera los vea. Se publican desde la lista, cuando estén revisados.
 */
class CatalogoDelFablabSeeder extends Seeder
{
    /**
     * Un FabCoin son mil pesos, así que una unidad menor son diez.
     *
     * Los precios se escriben en PESOS más abajo, que es como se piensan y
     * como se discuten con quien compra. La conversión vive aquí y en un solo
     * sitio: escribir 2500 donde se quiso decir 25.000 es un error que en
     * unidades menores no se ve venir.
     */
    private function aMenor(int $pesos): int
    {
        return (int) round($pesos / (int) config('fabos.currency.peso_rate')
            * (int) config('fabos.currency.minor_units'));
    }

    public function run(): void
    {
        $consultado = Carbon::parse('2026-09-16');

        foreach ($this->catalogo() as $fila) {
            $area = Area::where('name', $fila['area'])->first();

            // Un servicio cuya area no existe en esta instalacion se salta en
            // vez de crearse suelto: el catalogo se navega por area, y uno sin
            // ella no aparece donde alguien lo buscaria.
            if (! $area) {
                $this->command?->warn("  · sin área «{$fila['area']}»: se omite {$fila['nombre']}");

                continue;
            }

            if (ServiceOffering::where('slug', $fila['slug'])->exists()) {
                $this->command?->line("  · ya existe: {$fila['nombre']}");

                continue;
            }

            $servicio = ServiceOffering::create([
                'name' => $fila['nombre'],
                'slug' => $fila['slug'],
                'area_id' => $area->id,
                'description' => $fila['descripcion'],
                'unit' => $fila['unidad'],
                'price_minor' => $this->aMenor($fila['pesos']),
                'lead_time_days' => $fila['dias'],
                'is_active' => true,
                // Despublicado: es una propuesta, no una decision tomada.
                'is_public' => false,
            ]);

            foreach ($fila['escalones'] ?? [] as $desde => $pesos) {
                $servicio->priceBreaks()->create([
                    'min_quantity' => $desde,
                    'price_minor' => $this->aMenor($pesos),
                ]);
            }

            foreach ($fila['referencias'] ?? [] as $ref) {
                $servicio->referenciasDePrecio()->create([
                    'fuente' => $ref['fuente'],
                    'url' => $ref['url'] ?? null,
                    'precio_pesos' => $ref['pesos'],
                    'unidad' => $ref['unidad'],
                    'consultado_el' => $consultado,
                    'notas' => $ref['notas'] ?? null,
                ]);
            }

            $this->command?->info("  ✓ {$fila['nombre']}");
        }
    }

    /**
     * El catálogo, en pesos y por área.
     *
     * Los escalones son `desde => precio unitario`. Existen porque un
     * laboratorio cobra distinto una pieza que veinte: el montaje se reparte,
     * la lámina se aprovecha entera, la máquina se para una vez y no veinte.
     *
     * @return list<array<string,mixed>>
     */
    private function catalogo(): array
    {
        $laser = [
            [
                'fuente' => 'Acerlam AyR (Bogotá)',
                'url' => 'https://www.acerlamayr.co/corte-y-grabado-laser-en-bogota-al-mejor-precio-por-minuto-desde-500-de-acuerdo-al-material-a-cortar/',
                'pesos' => 500, 'unidad' => 'minuto',
                'notas' => 'Desde $500 según material.',
            ],
            [
                'fuente' => 'Corte Láser Bogotá',
                'url' => 'http://cortelaserbogota.com/',
                'pesos' => 600, 'unidad' => 'minuto',
                'notas' => 'Se anuncian como el minuto más económico de Bogotá.',
            ],
        ];

        return [
            // ---------------------------------------------------- Impresión 3D
            [
                'area' => 'Impresión 3D', 'slug' => 'impresion-3d-filamento',
                'nombre' => 'Impresión 3D en filamento (FDM)',
                'descripcion' => 'Piezas en PLA, PETG o TPU. Se cobra por gramo de pieza terminada, soportes incluidos. Si no tienes el archivo listo, proponnos un proyecto y lo modelamos.',
                'unidad' => 'gramo', 'pesos' => 250, 'dias' => 3,
                'escalones' => [100 => 210, 500 => 170],
                'referencias' => [[
                    'fuente' => 'Kojak Graphic', 'url' => 'https://www.kojakgraphic.com.co/impresion-en-3d-en-cali',
                    'pesos' => 90, 'unidad' => 'gramo',
                    'notas' => 'Precio de entrada, probablemente PLA a volumen y sin acabado.',
                ]],
            ],
            [
                'area' => 'Impresión 3D', 'slug' => 'impresion-3d-resina',
                'nombre' => 'Impresión 3D en resina',
                'descripcion' => 'Alta resolución para piezas pequeñas y detalle fino: joyería, miniaturas, moldes. Se cobra por mililitro e incluye lavado y curado.',
                'unidad' => 'mililitro', 'pesos' => 700, 'dias' => 3,
                'escalones' => [50 => 620, 200 => 520],
            ],
            [
                'area' => 'Impresión 3D', 'slug' => 'lavado-y-curado-resina',
                'nombre' => 'Lavado y curado de piezas en resina',
                'descripcion' => 'Para piezas impresas por fuera. Lavado en alcohol y curado UV controlado, que es lo que deja la pieza manipulable y estable.',
                'unidad' => 'lote', 'pesos' => 15000, 'dias' => 1,
            ],
            [
                'area' => 'Impresión 3D', 'slug' => 'secado-de-filamento',
                'nombre' => 'Secado de filamento',
                'descripcion' => 'Un rollo húmedo imprime mal y no siempre es evidente. Secado controlado por rollo.',
                'unidad' => 'rollo', 'pesos' => 12000, 'dias' => 1,
            ],

            // ------------------------------------------------- Corte y grabado
            [
                'area' => 'Corte y grabado', 'slug' => 'corte-laser-co2',
                'nombre' => 'Corte láser CO₂',
                'descripcion' => 'MDF, acrílico, cartón y textiles. Se cobra por minuto de máquina; trae tu archivo vectorial o lo preparamos contigo.',
                'unidad' => 'minuto', 'pesos' => 500, 'dias' => 2,
                'escalones' => [30 => 450, 120 => 400],
                'referencias' => $laser,
            ],
            [
                'area' => 'Corte y grabado', 'slug' => 'grabado-laser-co2',
                'nombre' => 'Grabado láser CO₂',
                'descripcion' => 'Grabado sobre madera, acrílico, cuero o vidrio. Se cobra por minuto de máquina.',
                'unidad' => 'minuto', 'pesos' => 450, 'dias' => 2,
                'escalones' => [30 => 400],
                'referencias' => $laser,
            ],
            [
                'area' => 'Corte y grabado', 'slug' => 'marcado-laser-metal',
                'nombre' => 'Marcado láser en metal',
                'descripcion' => 'Láser de fibra: marca permanente sobre acero, aluminio y titanio. Para placas, herramienta marcada y series numeradas.',
                'unidad' => 'pieza', 'pesos' => 9000, 'dias' => 2,
                'escalones' => [10 => 7500, 50 => 6000],
            ],
            [
                'area' => 'Corte y grabado', 'slug' => 'corte-de-vinilo',
                'nombre' => 'Corte de vinilo adhesivo',
                'descripcion' => 'Rótulos, calcomanías y plantillas. Se cobra por metro lineal de material cortado.',
                'unidad' => 'metro', 'pesos' => 14000, 'dias' => 2,
                'escalones' => [5 => 12000, 20 => 10000],
            ],

            // ----------------------------------------------------- Fresado CNC
            [
                'area' => 'Fresado CNC', 'slug' => 'fresado-cnc',
                'nombre' => 'Fresado CNC',
                'descripcion' => 'Mecanizado en madera, acrílico, aluminio y cera. Incluye preparación de trayectorias; el material va aparte.',
                'unidad' => 'hora', 'pesos' => 85000, 'dias' => 5,
                'escalones' => [8 => 75000],
            ],

            // -------------------------------------------- Estampado y bordado
            [
                'area' => 'Estampado y bordado', 'slug' => 'bordado-industrial',
                'nombre' => 'Bordado',
                'descripcion' => 'Sobre prenda propia o nuestra. Se cobra por cada mil puntadas, que es como se mide el bordado: un logo sencillo ronda las 8.000.',
                'unidad' => 'mil puntadas', 'pesos' => 1200, 'dias' => 4,
                'escalones' => [20 => 1000, 50 => 850],
            ],
            [
                'area' => 'Estampado y bordado', 'slug' => 'digitalizacion-bordado',
                'nombre' => 'Digitalización de diseño para bordado',
                'descripcion' => 'Pasar un logo a matriz de puntadas. Se hace una vez por diseño y sirve para todas las prendas que vengan después.',
                'unidad' => 'diseño', 'pesos' => 55000, 'dias' => 3,
            ],
            [
                'area' => 'Estampado y bordado', 'slug' => 'estampado-sublimacion',
                'nombre' => 'Estampado por sublimación',
                'descripcion' => 'Color completo sobre poliéster claro: camisetas deportivas, tazas, mousepads. La tinta queda dentro de la tela, no encima.',
                'unidad' => 'prenda', 'pesos' => 18000, 'dias' => 3,
                'escalones' => [10 => 15000, 50 => 12500],
            ],
            [
                'area' => 'Estampado y bordado', 'slug' => 'vinilo-textil',
                'nombre' => 'Termofijado de vinilo textil',
                'descripcion' => 'Para algodón y colores oscuros, donde la sublimación no sirve. Ideal para números, nombres y logos de pocos colores.',
                'unidad' => 'prenda', 'pesos' => 15000, 'dias' => 3,
                'escalones' => [10 => 12500, 50 => 10000],
            ],

            // ---------------------------------------------------- Impresión 2D
            [
                'area' => 'Impresión 2D', 'slug' => 'impresion-uv-objeto',
                'nombre' => 'Impresión UV sobre objeto',
                'descripcion' => 'Impresión a color directamente sobre superficies rígidas: termos, cajas, señalética, tapas de equipos. Con relieve si se quiere.',
                'unidad' => 'pieza', 'pesos' => 28000, 'dias' => 3,
                'escalones' => [10 => 23000, 50 => 19000],
            ],
            [
                'area' => 'Impresión 2D', 'slug' => 'plano-gran-formato',
                'nombre' => 'Plano o póster en gran formato',
                'descripcion' => 'Hasta 61 cm de ancho, en bond o papel fotográfico. Para planos, pósteres de congreso y piezas de exposición.',
                'unidad' => 'metro', 'pesos' => 22000, 'dias' => 1,
                'escalones' => [5 => 19000, 20 => 16000],
            ],

            // --------------------------------------- Acompañamiento y sesiones
            [
                'area' => 'Taller', 'slug' => 'hora-de-taller-asistida',
                'nombre' => 'Hora de taller con acompañamiento técnico',
                'descripcion' => 'Carpintería y ensamble con alguien del equipo al lado: sierra de banco, ingletadora, taladro de árbol. El material va aparte.',
                'unidad' => 'hora', 'pesos' => 60000, 'dias' => 2,
                'escalones' => [4 => 52000],
            ],
            [
                'area' => 'Electrónica', 'slug' => 'banco-electronica-asistido',
                'nombre' => 'Banco de electrónica con acompañamiento',
                'descripcion' => 'Osciloscopio, fuente, generador de señal y estación de soldadura, con un técnico apoyando la medición o la reparación.',
                'unidad' => 'hora', 'pesos' => 55000, 'dias' => 2,
                'escalones' => [4 => 48000],
            ],
            [
                'area' => 'VR', 'slug' => 'sesion-realidad-virtual',
                'nombre' => 'Sesión de realidad virtual',
                'descripcion' => 'Quince visores Meta Quest para talleres, demostraciones y clases. Se cobra por puesto y hora, con acompañamiento incluido.',
                'unidad' => 'puesto-hora', 'pesos' => 35000, 'dias' => 5,
                'escalones' => [5 => 30000, 10 => 25000],
            ],
            [
                'area' => 'Robots', 'slug' => 'demostracion-robotica',
                'nombre' => 'Demostración de robótica avanzada',
                'descripcion' => 'Sesión con el cuadrúpedo Unitree Go2 y el humanoide G1. Para ferias, clases y visitas: se explica qué hacen, cómo se programan y para qué sirven.',
                'unidad' => 'sesión', 'pesos' => 320000, 'dias' => 7,
            ],
        ];
    }
}
