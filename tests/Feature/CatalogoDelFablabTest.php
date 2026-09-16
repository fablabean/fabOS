<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ReferenciaDePrecio;
use App\Models\ServiceOffering;
use Database\Seeders\CatalogoDelFablabSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El catálogo de servicios y de dónde sale cada precio (§14).
 *
 * La tienda tenía un servicio publicado y noventa equipos públicos. Lo que se
 * cuida aquí son las tres cosas que harían daño en silencio: que un precio se
 * escriba en la unidad equivocada —la diferencia entre 500 y 5.000 pesos es un
 * cero—, que volver a sembrar pise lo que alguien ajustó a mano, y que un
 * catálogo sin revisar salga publicado a clientes de fuera.
 */
class CatalogoDelFablabTest extends TestCase
{
    use RefreshDatabase;

    private function areas(): void
    {
        foreach ([
            'Impresión 3D', 'Corte y grabado', 'Fresado CNC', 'Estampado y bordado',
            'Impresión 2D', 'Electrónica', 'Taller', 'Robots', 'VR',
        ] as $i => $nombre) {
            Area::firstOrCreate(
                ['slug' => 'a'.$i],
                ['name' => $nombre, 'position' => $i],
            );
        }
    }

    public function test_siembra_el_catalogo_con_todas_las_areas(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        // Una por área, al menos: si un área se queda sin servicios, el
        // catálogo tiene un hueco que nadie va a notar mirando la lista.
        foreach (Area::pluck('id') as $areaId) {
            $this->assertTrue(
                ServiceOffering::where('area_id', $areaId)->exists(),
                'Un área se quedó sin ningún servicio.',
            );
        }

        $this->assertGreaterThanOrEqual(15, ServiceOffering::count());
    }

    /**
     * El precio se escribe en pesos y se guarda en unidades menores.
     *
     * Es la conversión donde un cero de más pasa desapercibido: 50 unidades
     * menores son 500 pesos, y 500 serían 5.000. En pantalla las dos se ven
     * como un número plausible.
     */
    public function test_los_precios_quedan_en_la_unidad_correcta(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        $laser = ServiceOffering::where('slug', 'corte-laser-co2')->firstOrFail();

        // $500 el minuto, con 1 FabCoin = $1.000 y 100 unidades menores.
        $this->assertSame(50, $laser->price_minor);
        $this->assertSame('minuto', $laser->unit);

        // Y los escalones bajan, nunca suben: un escalón más caro al llevar
        // más sería un recargo con nombre de descuento.
        $precios = $laser->priceBreaks()->orderBy('min_quantity')->pluck('price_minor')->all();

        $this->assertSame([45, 40], $precios);
        $this->assertLessThan($laser->price_minor, $precios[0]);
    }

    /** Todo escalón de todo servicio tiene que ser más barato que su base. */
    public function test_ningun_escalon_sale_mas_caro_que_el_precio_base(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        foreach (ServiceOffering::with('priceBreaks')->get() as $servicio) {
            $anterior = $servicio->price_minor;

            foreach ($servicio->priceBreaks()->orderBy('min_quantity')->get() as $escalon) {
                $this->assertLessThan(
                    $anterior,
                    $escalon->price_minor,
                    "«{$servicio->name}» tiene un escalón que no baja.",
                );

                $anterior = $escalon->price_minor;
            }
        }
    }

    /**
     * Nace despublicado.
     *
     * Son precios calculados, no una decisión del laboratorio: alguien tiene
     * que mirarlos antes de que un cliente de fuera los vea.
     */
    public function test_el_catalogo_nace_sin_publicar(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        $this->assertSame(0, ServiceOffering::where('is_public', true)->count());
        $this->assertGreaterThan(0, ServiceOffering::where('is_active', true)->count());
    }

    /** Volver a sembrar no pisa lo que alguien ajustó a mano. */
    public function test_sembrar_dos_veces_no_duplica_ni_pisa(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        $cuantos = ServiceOffering::count();

        $laser = ServiceOffering::where('slug', 'corte-laser-co2')->firstOrFail();
        $laser->update(['price_minor' => 999, 'is_public' => true]);

        $this->seed(CatalogoDelFablabSeeder::class);

        $this->assertSame($cuantos, ServiceOffering::count(), 'Se duplicaron servicios.');

        $laser->refresh();
        $this->assertSame(999, $laser->price_minor, 'Se pisó un precio ajustado a mano.');
        $this->assertTrue($laser->is_public, 'Se despublicó algo que ya estaba publicado.');
    }

    // ---------------------------------------------------------------
    // Las referencias de precio
    // ---------------------------------------------------------------

    /** Las referencias se guardan en pesos, con fuente y fecha. */
    public function test_las_referencias_traen_fuente_y_fecha(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        $laser = ServiceOffering::where('slug', 'corte-laser-co2')->firstOrFail();
        $refs = $laser->referenciasDePrecio;

        $this->assertGreaterThanOrEqual(2, $refs->count(), 'Una sola fuente es una anécdota.');

        foreach ($refs as $ref) {
            $this->assertNotEmpty($ref->fuente);
            $this->assertNotNull($ref->consultado_el);
            $this->assertGreaterThan(0, $ref->precio_pesos);
            $this->assertNotEmpty($ref->unidad);
        }

        // En pesos, no en FabCoins: el mercado cotiza en pesos y convertirlo al
        // guardarlo escondería el dato detrás de una tasa que cambia.
        $this->assertSame(500, $refs->firstWhere('fuente', 'Acerlam AyR (Bogotá)')?->precio_pesos);
    }

    /** Una referencia vieja deja de respaldar nada, y hay que poder verlo. */
    public function test_una_referencia_de_hace_mas_de_un_ano_se_marca_vieja(): void
    {
        $this->areas();
        $this->seed(CatalogoDelFablabSeeder::class);

        $servicio = ServiceOffering::firstOrFail();

        $fresca = new ReferenciaDePrecio(['consultado_el' => now()->subMonths(3)]);
        $vieja = new ReferenciaDePrecio(['consultado_el' => now()->subMonths(18)]);

        $this->assertFalse($fresca->estaVieja());
        $this->assertTrue($vieja->estaVieja());

        $this->assertSame(0, $servicio->referenciasDePrecio()->get()
            ->filter(fn (ReferenciaDePrecio $r) => $r->estaVieja())->count());
    }
}
