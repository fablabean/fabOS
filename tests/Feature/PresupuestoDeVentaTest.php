<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Budget;
use App\Models\Sale;
use App\Models\Supply;
use App\Models\User;
use App\Services\Shop\ShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Presupuesto de venta y ejecutado de arranque (§13).
 *
 * Dos cosas que le faltaban al presupuesto, y las dos por el mismo motivo: el
 * sistema deriva lo ejecutado de lo que puede demostrar, y eso está bien —un
 * «disponible» editable a mano es lo que hace que a mitad de año nadie sepa
 * cuánto queda—, pero deja fuera lo que ocurrió antes de que existiera.
 */
class PresupuestoDeVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Quien compra', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function insumo(array $cambios = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'Filamento ' . uniqid(), 'unit' => 'kg',
            'stock' => 100, 'last_cost' => 90_000, 'is_active' => true,
        ], $cambios));
    }

    // -------------------------------------------------- el ejecutado de antes

    /**
     * El año arrancó antes que el sistema. Lo gastado en enero no tiene
     * solicitud que lo respalde, y sin poder anotarlo el presupuesto enseñaría
     * como disponible una plata que ya no está.
     */
    public function test_el_arranque_baja_el_disponible(): void
    {
        $p = Budget::create([
            'name' => 'Materiales', 'year' => 2026, 'amount' => 50_000_000,
            'opening_executed' => 12_000_000,
            'opening_note' => 'Compras de enero a marzo, antes de fabOS.',
        ]);

        $this->assertSame(12_000_000, $p->ejecutado());
        $this->assertSame(38_000_000, $p->disponible());

        // Y se distingue de lo que el sistema puede demostrar.
        $this->assertSame(0, $p->ejecutadoDelSistema());
    }

    public function test_sin_arranque_el_presupuesto_se_comporta_igual_que_antes(): void
    {
        $p = Budget::create(['name' => 'Materiales', 'year' => 2026, 'amount' => 10_000_000]);

        $this->assertSame(0, $p->ejecutado());
        $this->assertSame(10_000_000, $p->disponible());
    }

    // ------------------------------------------------- el presupuesto de venta

    /** Por defecto un presupuesto es de gasto: es lo que había hasta ahora. */
    public function test_por_defecto_es_de_gasto(): void
    {
        $p = Budget::create(['name' => 'Materiales', 'year' => 2026, 'amount' => 1_000_000]);

        $this->assertFalse($p->esDeVenta());
    }

    /**
     * En uno de venta, lo ejecutado es lo que entró por el mostrador. La plata
     * que ingresa al laboratorio queda contra esa meta sin que nadie la anote a
     * mano.
     */
    public function test_lo_que_se_vende_suma_al_presupuesto_de_venta(): void
    {
        $meta = Budget::create([
            'name' => 'Ingresos por venta', 'kind' => 'venta',
            'year' => (int) date('Y'), 'amount' => 20_000_000,
        ]);

        $this->assertSame(0, $meta->ejecutado());

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($this->persona());
        $tienda->agregarInsumo($venta, $this->insumo(), 2);
        $tienda->cobrar($venta->refresh());

        // 2 kg a 90.000 con 30% de margen: 234.000 pesos.
        $this->assertSame(234_000, $meta->fresh()->ejecutado());
        $this->assertSame(20_000_000 - 234_000, $meta->fresh()->disponible());
    }

    /** Lo anulado no entró: no puede contar como ingreso. */
    public function test_una_venta_anulada_no_suma(): void
    {
        $meta = Budget::create([
            'name' => 'Ingresos', 'kind' => 'venta',
            'year' => (int) date('Y'), 'amount' => 1_000_000,
        ]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($this->persona());
        $tienda->agregarInsumo($venta, $this->insumo(), 1);
        $tienda->cobrar($venta->refresh());

        $antes = $meta->fresh()->ejecutado();
        $this->assertGreaterThan(0, $antes);

        Sale::first()->update(['status' => 'anulada']);

        $this->assertSame(0, $meta->fresh()->ejecutado());
    }

    /** Y lo de otro año tampoco: cada vigencia cuenta lo suyo. */
    public function test_las_ventas_de_otro_ano_no_suman(): void
    {
        $meta = Budget::create([
            'name' => 'Ingresos', 'kind' => 'venta',
            'year' => (int) date('Y') - 1, 'amount' => 1_000_000,
        ]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($this->persona());
        $tienda->agregarInsumo($venta, $this->insumo(), 1);
        $tienda->cobrar($venta->refresh());

        $this->assertSame(0, $meta->fresh()->ejecutado());
    }

    /** El arranque también vale para las ventas: el año empezó antes. */
    public function test_los_ingresos_de_arranque_cuentan(): void
    {
        $meta = Budget::create([
            'name' => 'Ingresos', 'kind' => 'venta',
            'year' => (int) date('Y'), 'amount' => 20_000_000,
            'opening_executed' => 5_000_000,
            'opening_note' => 'Facturado de enero a marzo, antes de fabOS.',
        ]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($this->persona());
        $tienda->agregarInsumo($venta, $this->insumo(), 1);
        $tienda->cobrar($venta->refresh());

        $this->assertSame(5_000_000 + 117_000, $meta->fresh()->ejecutado());
    }

    /** Un presupuesto de venta partido por área cuenta solo lo de esa área. */
    public function test_una_meta_por_area_cuenta_solo_su_area(): void
    {
        $laser = Area::create(['slug' => 'laser-' . uniqid(), 'name' => 'Corte Láser']);
        $otra = Area::create(['slug' => 'otra-' . uniqid(), 'name' => 'Prototipado']);

        $meta = Budget::create([
            'name' => 'Ingresos de láser', 'kind' => 'venta', 'area_id' => $laser->id,
            'year' => (int) date('Y'), 'amount' => 5_000_000,
        ]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($this->persona());
        $tienda->agregarInsumo($venta, $this->insumo(['area_id' => $otra->id]), 1);
        $tienda->cobrar($venta->refresh());

        $this->assertSame(0, $meta->fresh()->ejecutado(), 'Se contó una venta de otra área.');
    }

    // ------------------------------------------------------------ la moneda

    /**
     * El presupuesto se habla en pesos y el laboratorio cobra en FabCoins.
     * Sin la equivalencia delante hay que ir a buscarla para entender
     * cualquiera de las dos cifras.
     */
    public function test_el_listado_ensena_la_equivalencia(): void
    {
        $admin = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        Budget::create(['name' => 'Materiales', 'year' => 2026, 'amount' => 1_000_000]);

        $this->actingAs($admin->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/budgets')
            ->assertOk()
            ->assertSee('1 FBC = $1.000')
            ->assertSee('Todo en pesos');
    }
}
