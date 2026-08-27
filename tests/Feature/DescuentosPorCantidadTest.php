<?php

namespace Tests\Feature;

use App\Filament\Resources\Supplies\Pages\EditSupply;
use App\Models\PriceBreak;
use App\Models\ServiceOffering;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Money\PricingService;
use App\Services\Shop\Carrito;
use App\Services\Shop\ShopService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Descuentos por cantidad (§14).
 *
 * Un laboratorio cobra distinto una pieza que veinte: el montaje se reparte, la
 * lámina se aprovecha entera, la máquina se para una vez y no veinte. Sin esto
 * se negocia por WhatsApp y se cobra a ojo, que es como dos personas acaban
 * pagando precios distintos por lo mismo.
 *
 * Lo que este archivo defiende: que el escalón se aplique **en todas partes** y
 * se **enseñe antes** de comprar. Un descuento que solo aparece al cobrar no
 * cambia la decisión de nadie, porque no llega a saberse que existía.
 */
class DescuentosPorCantidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'fabos.currency.peso_rate'   => 1000,
            'fabos.currency.minor_units' => 100,
        ]);

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    /** Un producto a $25.000, con escalón desde 10 a $20.000. */
    private function producto(bool $conEscalon = true): Supply
    {
        $insumo = Supply::create([
            'name' => 'DinoClick', 'unit' => 'unidad', 'stock' => 100,
            'is_active' => true, 'is_public' => true,
        ]);

        app(PricingService::class)->fijarPrecioEnPesos($insumo, 25_000);

        if ($conEscalon) {
            $insumo->priceBreaks()->create(['min_quantity' => 10, 'price_minor' => 2_000]);
        }

        return $insumo->fresh();
    }

    private function precios(): PricingService
    {
        return app(PricingService::class);
    }

    // ------------------------------------------------------------- el precio

    public function test_sin_escalones_la_cantidad_no_cambia_el_precio(): void
    {
        $insumo = $this->producto(conEscalon: false);

        $this->assertSame(2_500, $this->precios()->precioDe($insumo, 1));
        $this->assertSame(2_500, $this->precios()->precioDe($insumo, 50));
    }

    public function test_por_debajo_del_escalon_se_paga_el_precio_normal(): void
    {
        $insumo = $this->producto();

        $this->assertSame(2_500, $this->precios()->precioDe($insumo, 9));
    }

    public function test_al_llegar_al_escalon_se_paga_el_del_escalon(): void
    {
        $insumo = $this->producto();

        $this->assertSame(2_000, $this->precios()->precioDe($insumo, 10));
        $this->assertSame(2_000, $this->precios()->precioDe($insumo, 40));
    }

    /** Con varios, manda el más alto que no pase de lo que se lleva. */
    public function test_manda_el_escalon_mas_alto_que_aplica(): void
    {
        $insumo = $this->producto();
        $insumo->priceBreaks()->create(['min_quantity' => 50, 'price_minor' => 1_500]);

        $insumo = $insumo->fresh();

        $this->assertSame(2_500, $this->precios()->precioDe($insumo, 5));
        $this->assertSame(2_000, $this->precios()->precioDe($insumo, 20));
        $this->assertSame(1_500, $this->precios()->precioDe($insumo, 60));
    }

    /** Un servicio se pregunta igual: el que se pregunta a mano es el que se olvida. */
    public function test_un_servicio_tambien_tiene_escalones(): void
    {
        $servicio = ServiceOffering::create([
            'name' => 'Corte láser', 'unit' => 'hoja',
            'price_minor' => 3_000, 'is_active' => true, 'is_public' => true,
        ]);
        $servicio->priceBreaks()->create(['min_quantity' => 5, 'price_minor' => 2_400]);

        $servicio = $servicio->fresh();

        $this->assertSame(3_000, $this->precios()->precioDeServicio($servicio, 4));
        $this->assertSame(2_400, $this->precios()->precioDeServicio($servicio, 5));
    }

    // ------------------------------------------------- donde se cobra de verdad

    /**
     * El carrito cobra el escalón.
     *
     * Enseñar el precio de una sola y aplicar el descuento al cobrar es
     * exactamente lo que hace que nadie se fíe del total.
     */
    public function test_el_carrito_cobra_el_escalon(): void
    {
        $insumo = $this->producto();

        $carrito = app(Carrito::class);
        $carrito->agregar('insumo', $insumo->id, 10);

        $linea = $carrito->lineas()->firstOrFail();

        $this->assertSame(2_000, $linea['precio']);
        $this->assertSame(20_000, $linea['total']);
    }

    public function test_el_carrito_con_pocas_unidades_cobra_lo_normal(): void
    {
        $insumo = $this->producto();

        $carrito = app(Carrito::class);
        $carrito->agregar('insumo', $insumo->id, 2);

        $this->assertSame(2_500, $carrito->lineas()->firstOrFail()['precio']);
    }

    /** Y el mostrador cobra lo mismo que la tienda: si no, depende de por dónde entres. */
    public function test_la_venta_de_mostrador_cobra_el_escalon(): void
    {
        $insumo = $this->producto();

        $quien = User::create([
            'name' => 'Quien compra', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($quien, $quien);
        $linea = $tienda->agregarInsumo($venta, $insumo, 10);

        $this->assertSame(2_000, (int) $linea->unit_price_minor);
    }

    // --------------------------------------------------------- lo que se enseña

    public function test_los_escalones_se_leen_con_su_descuento(): void
    {
        $insumo = $this->producto();

        $escalones = $this->precios()->escalonesDe($insumo);

        $this->assertCount(1, $escalones);
        $this->assertEqualsWithDelta(10, $escalones[0]['desde'], 0.001);
        $this->assertSame(2_000, $escalones[0]['precio']);
        $this->assertEqualsWithDelta(20, $escalones[0]['descuento'], 0.1);
    }

    /** La tienda lo anuncia antes de comprar, no al cobrar. */
    public function test_la_tienda_anuncia_el_escalon(): void
    {
        $this->producto();

        $html = $this->get('/tienda')->assertOk()->getContent();

        $this->assertStringContainsString('$20.000 c/u', $html);
        // Y la ficha completa viaja con la tarjeta, para poder abrirla sin
        // volver al servidor.
        $this->assertStringContainsString('data-ficha=', $html);
    }

    /** La foto es cuadrada: con alturas fijas la rejilla se lee como un mosaico roto. */
    public function test_las_fotos_del_catalogo_son_cuadradas(): void
    {
        $this->producto();

        $this->get('/tienda')
            ->assertOk()
            ->assertSee('aspect-ratio:1/1', false);
    }

    // ------------------------------------------------------------- el admin

    public function test_los_escalones_se_configuran_desde_la_ficha(): void
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $insumo = $this->producto(conEscalon: false);

        Livewire::test(EditSupply::class, ['record' => $insumo->getKey()])
            ->fillForm([
                'priceBreaks' => [
                    ['min_quantity' => 10, 'price_minor' => 20_000],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $escalon = PriceBreak::where('priceable_id', $insumo->id)->firstOrFail();

        // Se escribe en pesos y se guarda en unidades menores.
        $this->assertSame(2_000, (int) $escalon->price_minor);
        $this->assertEqualsWithDelta(10, (float) $escalon->min_quantity, 0.001);
    }
}
