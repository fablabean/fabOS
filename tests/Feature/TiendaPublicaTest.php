<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sale;
use App\Models\ServiceOffering;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Support\Settings;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La tienda pública (§14).
 *
 * Se mira sin entrar: obligar a identificarse para ver precios es la forma más
 * rápida de que nadie los vea. Y tiene dos salidas, porque el laboratorio
 * atiende dos necesidades que no se parecen: llevárselo con FabCoins, o pedir
 * una cotización cuando lo que hace falta es un encargo a medida.
 */
class TiendaPublicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    private function insumo(array $cambios = []): Supply
    {
        return Supply::create(array_merge([
            'name'      => 'Filamento PLA',
            'unit'      => 'g',
            'stock'     => 1000,
            'last_cost' => 100,
            'is_active' => true,
            'is_public' => true,
        ], $cambios));
    }

    private function servicio(array $cambios = []): ServiceOffering
    {
        return ServiceOffering::create(array_merge([
            'name'        => 'Corte láser en MDF de 3 mm',
            'unit'        => 'hoja',
            'price_minor' => 2500,
            'is_active'   => true,
            'is_public'   => true,
        ], $cambios));
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Quien compra', 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'user_category_id' => UserCategory::where('slug', 'invitado')->value('id'),
        ]);
    }

    // ------------------------------------------------------------ mirarla

    public function test_la_tienda_se_mira_sin_entrar(): void
    {
        $this->insumo();
        $this->servicio();
        $this->insumo(['name' => 'Llavero impreso', 'kind' => 'producto', 'unit' => 'unidad']);

        $this->get(route('tienda.publica'))
            ->assertOk()
            ->assertSee('Filamento PLA')
            ->assertSee('Corte láser en MDF de 3 mm')
            ->assertSee('Llavero impreso')
            ->assertSee('Productos')
            ->assertSee('Servicios');
    }

    /** No todo lo que hay se vende: la acetona y las brocas no. */
    public function test_lo_que_no_esta_publicado_no_sale(): void
    {
        $this->insumo(['name' => 'Acetona técnica', 'is_public' => false]);

        $this->get(route('tienda.publica'))
            ->assertOk()
            ->assertDontSee('Acetona técnica');
    }

    /** Sin precio no aparece, aunque esté marcado: un catálogo con huecos no sirve. */
    public function test_sin_precio_no_aparece(): void
    {
        $this->insumo(['name' => 'Sin precio', 'last_cost' => null]);

        $this->get(route('tienda.publica'))
            ->assertOk()
            ->assertDontSee('Sin precio');
    }

    // ------------------------------------------------------------ carrito

    public function test_se_llena_el_carrito_sin_cuenta(): void
    {
        $insumo = $this->insumo();

        $this->post(route('tienda.carrito.agregar'), [
            'tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 250,
        ])->assertRedirect();

        $this->get(route('tienda.publica'))
            ->assertOk()
            ->assertSee('Tu carrito')
            ->assertSee('Filamento PLA');
    }

    /** Volver a añadir lo mismo significa querer más, no querer lo mismo. */
    public function test_anadir_dos_veces_suma(): void
    {
        $insumo = $this->insumo();

        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 100]);
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 150]);

        $carrito = app(\App\Services\Shop\Carrito::class);

        $this->assertSame(250.0, $carrito->lineas()->first()['cantidad']);
    }

    public function test_poner_cantidad_cero_lo_quita(): void
    {
        $insumo = $this->insumo();

        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 5]);
        $this->post(route('tienda.carrito.actualizar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 0]);

        $this->assertTrue(app(\App\Services\Shop\Carrito::class)->estaVacio());
    }

    /**
     * Un carrito que no se puede ni abrir para vaciarlo es peor que uno con una
     * línea de menos.
     */
    public function test_lo_que_dejo_de_venderse_desaparece_del_carrito(): void
    {
        $insumo = $this->insumo();

        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 5]);

        $insumo->update(['is_public' => false]);

        $this->get(route('tienda.publica'))->assertOk();
        $this->assertTrue(app(\App\Services\Shop\Carrito::class)->estaVacio());
    }

    // ------------------------------------------------------------- pagar

    public function test_pagar_exige_cuenta(): void
    {
        $insumo = $this->insumo();
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 10]);

        $this->post(route('tienda.pagar'))->assertRedirect(route('login'));
    }

    public function test_se_paga_con_fabcoins_y_baja_la_existencia(): void
    {
        $insumo = $this->insumo(['stock' => 500]);
        $servicio = $this->servicio();
        $quien = $this->persona();

        $this->actingAs($quien);
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 100]);
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'servicio', 'id' => $servicio->id, 'cantidad' => 2]);

        $this->post(route('tienda.pagar'))->assertRedirect(route('tienda.publica'));

        $venta = Sale::firstOrFail();

        $this->assertCount(2, $venta->items);
        $this->assertEqualsWithDelta(400, (float) $insumo->fresh()->stock, 0.001);
        $this->assertTrue(app(\App\Services\Shop\Carrito::class)->estaVacio());
    }

    /** No se vende lo que no hay: decirlo antes evita la venta a medias. */
    public function test_no_se_paga_lo_que_no_alcanza(): void
    {
        $insumo = $this->insumo(['stock' => 10]);

        $this->actingAs($this->persona());
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 500]);

        $this->post(route('tienda.pagar'))->assertSessionHasErrors('carrito');

        $this->assertDatabaseCount('sales', 0);
        $this->assertFalse(app(\App\Services\Shop\Carrito::class)->estaVacio());
    }

    public function test_no_se_paga_un_carrito_vacio(): void
    {
        $this->actingAs($this->persona());

        $this->post(route('tienda.pagar'))->assertSessionHasErrors('carrito');
    }

    // --------------------------------------------------------- cotizarlo

    /**
     * Lo que se junta en el carrito no siempre es una compra: a veces es la
     * forma más clara de decir «necesito esto».
     */
    public function test_el_carrito_se_convierte_en_solicitud_de_proyecto(): void
    {
        $insumo = $this->insumo();
        $servicio = $this->servicio();

        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 300]);
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'servicio', 'id' => $servicio->id, 'cantidad' => 4]);

        $this->post(route('tienda.cotizar'), [
            'titulo'  => 'Señalética del laboratorio de suelos',
            'detalle' => 'Van pegados en seis puertas.',
            'nombre'  => 'Steban Gómez',
            'correo'  => 'steban@ejemplo.co',
            'cliente' => 'externo',
        ])->assertRedirect(route('tienda.publica'));

        $proyecto = Project::firstOrFail();

        $this->assertSame('Señalética del laboratorio de suelos', $proyecto->name);
        $this->assertSame('formulario', $proyecto->source);
        $this->assertSame('idea', $proyecto->stage);

        // Cada cosa del carrito es un entregable: es exactamente lo que se pide.
        $this->assertCount(2, $proyecto->deliverables);
        $this->assertStringContainsString('Filamento PLA', $proyecto->deliverables->first()->title);
        $this->assertStringContainsString('300', $proyecto->deliverables->first()->title);

        // El valor de lista queda de punto de partida para quien lo cotice.
        $this->assertGreaterThan(0, (int) $proyecto->estimated_value);

        $this->assertTrue(app(\App\Services\Shop\Carrito::class)->estaVacio());
    }

    /** Sin cuenta también, que para eso el carrito no la pide. */
    public function test_cotizar_sin_cuenta_crea_la_cuenta(): void
    {
        $insumo = $this->insumo();
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 50]);

        $this->post(route('tienda.cotizar'), [
            'nombre'  => 'Steban Gómez',
            'correo'  => 'steban@ejemplo.co',
            'cliente' => 'externo',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'steban@ejemplo.co']);
        $this->assertNotNull(Project::first()->requested_by);
    }

    /** Y a quien ya entró no se le vuelve a preguntar quién es. */
    public function test_quien_entro_no_repite_sus_datos(): void
    {
        $insumo = $this->insumo();
        $quien = $this->persona();

        $this->actingAs($quien);
        $this->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 50]);

        $this->post(route('tienda.cotizar'), ['titulo' => 'Lo de siempre'])->assertRedirect();

        $proyecto = Project::firstOrFail();

        $this->assertSame($quien->id, $proyecto->requested_by);
        $this->assertSame('externo', $proyecto->client_kind);
    }

    public function test_no_se_cotiza_un_carrito_vacio(): void
    {
        $this->post(route('tienda.cotizar'), [
            'nombre' => 'Alguien', 'correo' => 'a@b.co', 'cliente' => 'externo',
        ])->assertSessionHasErrors('carrito');

        $this->assertDatabaseCount('projects', 0);
    }
}
