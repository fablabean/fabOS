<?php

namespace Tests\Feature;

use App\Models\Supply;
use App\Services\Shop\Carrito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que el laboratorio fabrica cuando se lo piden (§14).
 *
 * La tienda solo enseñaba lo que tiene existencia, y para un insumo eso está
 * bien: o hay lámina de MDF o no la hay. Pero un fablab no tiene cien llaveros
 * en un cajón — los hace cuando se los piden—, así que con esa regla a secas
 * todo el catálogo de lo que **sabe fabricar** quedaba invisible hasta que
 * alguien produjera un lote por si acaso.
 *
 * Lo que se cuida aquí es que la excepción no se coma la regla: un insumo
 * agotado tiene que seguir desapareciendo.
 */
class ProductosPorEncargoTest extends TestCase
{
    use RefreshDatabase;

    private function cosa(array $extra = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'Llavero grabado',
            'kind' => 'producto',
            'unit' => 'unidad',
            'stock' => 0,
            'last_cost' => 9000,
            'is_active' => true,
            'is_public' => true,
        ], $extra));
    }

    public function test_lo_agotado_sigue_sin_verse(): void
    {
        $this->cosa(['name' => 'Lámina de MDF', 'kind' => 'insumo', 'stock' => 0]);

        $this->assertSame(0, Supply::enLaTienda()->ofrecible()->count());
    }

    public function test_lo_que_se_fabrica_se_ofrece_aunque_no_haya(): void
    {
        $producto = $this->cosa(['por_encargo' => true, 'dias_por_encargo' => 3]);

        $this->assertSame(1, Supply::enLaTienda()->ofrecible()->count());
        $this->assertTrue($producto->sePuedePedir());
        $this->assertSame('por encargo · listo en 3 días', $producto->cuandoEstaListo());
    }

    /**
     * Con existencia no se dice ningún plazo.
     *
     * «Por encargo, listo en tres días» sobre algo que está en la vitrina sería
     * mentir hacia el otro lado: se puede llevar hoy.
     */
    public function test_con_existencia_no_promete_un_plazo(): void
    {
        $producto = $this->cosa(['por_encargo' => true, 'dias_por_encargo' => 3, 'stock' => 5]);

        $this->assertNull($producto->cuandoEstaListo());
    }

    /** Sin plazo medido se dice «por encargo» y ya: no se inventa una fecha. */
    public function test_sin_plazo_medido_no_se_inventa_uno(): void
    {
        $producto = $this->cosa(['por_encargo' => true, 'dias_por_encargo' => null]);

        $this->assertSame('por encargo', $producto->cuandoEstaListo());
    }

    /**
     * El carrito no frena lo que se fabrica por encargo.
     *
     * Si contara como «falta existencia», sería imposible pedir justo aquello
     * para lo que el laboratorio existe.
     */
    public function test_el_carrito_no_frena_lo_que_se_fabrica(): void
    {
        $encargo = $this->cosa(['por_encargo' => true, 'dias_por_encargo' => 3]);
        $agotado = $this->cosa(['name' => 'Filamento PLA', 'kind' => 'insumo', 'stock' => 0]);

        $carrito = app(Carrito::class);
        $carrito->agregar('insumo', $encargo->id, 10);
        $carrito->agregar('insumo', $agotado->id, 10);

        $faltantes = $carrito->sinExistencia()->pluck('cosa.id')->all();

        $this->assertContains($agotado->id, $faltantes, 'Un insumo agotado sí tiene que frenar.');
        $this->assertNotContains($encargo->id, $faltantes, 'Lo que se fabrica no «falta»: todavía no existe.');
    }

    /** Y se ve en la tienda pública, con su plazo delante. */
    public function test_se_ve_en_la_tienda_con_su_plazo(): void
    {
        $this->cosa(['name' => 'Gorra bordada', 'por_encargo' => true, 'dias_por_encargo' => 5]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee('Gorra bordada')
            // Y lo dice: un producto que se fabrica y no lo avisa parece que
            // esta en la vitrina, y quien lo pide se entera del plazo al final.
            ->assertSee('por encargo · listo en 5 días');
    }
}
