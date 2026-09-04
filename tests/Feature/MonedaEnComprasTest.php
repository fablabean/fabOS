<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Supply;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Purchasing\PurchasingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Compras en dólares, y precios con centavos (§13).
 *
 * Buena parte de lo que se compra viene de Amazon: un lanyard vale US$18,99.
 * El precio era un entero en pesos y la base lo rechazaba. Ahora se escribe
 * en la moneda del carrito, con centavos, y la solicitud dice a cuántos pesos
 * va el dólar: el presupuesto sigue en pesos y ahí se compara todo.
 */
class MonedaEnComprasTest extends TestCase
{
    use RefreshDatabase;

    private function compras(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    private function jefa(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function enDolares(User $u, float $tasa = 4000): PurchaseRequest
    {
        $carrito = $this->compras()->abrirCarrito($u);
        $carrito->update(['currency' => 'USD', 'exchange_rate' => $tasa, 'tax_rate' => 0.19]);

        // Lo del carrito real: 4 × 18,99 + 5 × 9,99 = 75,96 + 49,95 = 125,91
        $this->compras()->agregar($carrito, 'Lanyard blanco', 4, 18.99);
        $this->compras()->agregar($carrito, 'Porta carnet', 5, 9.99);

        return $carrito->fresh()->load('items');
    }

    // ------------------------------------------------------------ centavos

    public function test_el_precio_unitario_admite_centavos(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $carrito = $this->compras()->abrirCarrito($u);

        $linea = $this->compras()->agregar($carrito, 'Lanyard', 4, 18.99);

        $this->assertSame(18.99, (float) $linea->fresh()->unit_price);
        $this->assertSame(75.96, $linea->total());
    }

    // ------------------------------------------------------------ dolares

    public function test_en_dolares_el_total_se_lleva_a_pesos_con_la_tasa(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $s = $this->enDolares($u, 4000);

        $this->assertSame(125.91, $s->subtotalEnMoneda());
        $this->assertSame(149.83, $s->totalEnMoneda(), '125,91 + 19 %');
        $this->assertSame('US$149,83', $s->formato($s->totalEnMoneda()));

        // En pesos, que es donde vive el presupuesto.
        $this->assertSame(503_640, $s->subtotal());
        $this->assertSame(599_320, $s->totalEstimado());
        $this->assertStringContainsString('por dólar', $s->comoSeCalcula());
    }

    public function test_en_pesos_no_cambia_nada(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $carrito = $this->compras()->abrirCarrito($u);
        $this->compras()->agregar($carrito, 'Resina', 2, 100_000);
        $carrito->load('items');

        $this->assertTrue($carrito->esEnPesos());
        $this->assertSame(200_000, $carrito->subtotal());
        $this->assertSame(238_000, $carrito->totalEstimado());
        $this->assertSame('$200.000', $carrito->formato($carrito->subtotalEnMoneda()));
    }

    public function test_se_aprueba_contra_el_presupuesto_en_pesos(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $s = $this->enDolares($u, 4000);
        $presupuesto = Budget::create(['name' => 'Insumos', 'year' => 2026, 'amount' => 600_000, 'status' => 'vigente']);

        $this->compras()->enviar($s);
        $this->compras()->aprobar($s->fresh(), $u, $presupuesto);

        $this->assertSame(600_000 - 599_320, $presupuesto->fresh()->disponible());
    }

    /** La ficha del insumo no sabe de dólares: el último costo queda en pesos. */
    public function test_al_recibir_el_ultimo_costo_del_insumo_queda_en_pesos(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $insumo = Supply::create(['name' => 'Tarjetas NFC', 'unit' => 'unidad', 'stock' => 0, 'is_active' => true]);

        $carrito = $this->compras()->abrirCarrito($u);
        $carrito->update(['currency' => 'USD', 'exchange_rate' => 4000]);
        $linea = $this->compras()->agregar($carrito, 'Tarjetas NFC', 2, 22.99, $insumo);

        $presupuesto = Budget::create(['name' => 'Insumos', 'year' => 2026, 'amount' => 10_000_000, 'status' => 'vigente']);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->fresh(), $u, $presupuesto);
        $this->compras()->recibir($carrito->fresh(), [$linea->id => 2], $u);

        $this->assertSame(91_960, (int) $insumo->fresh()->last_cost, '22,99 × 4.000');
    }

    // ------------------------------------------------------------ pantallas

    public function test_la_requisicion_en_dolares_dice_los_dos_totales(): void
    {
        $u = $this->jefa();
        $s = $this->enDolares($u, 4000);

        $this->get(route('compras.requisicion', $s))
            ->assertOk()
            ->assertSee('US$18,99')
            ->assertSee('US$149,83')
            ->assertSee('por dólar')
            ->assertSee('$599.320');
    }

    /** «Sin impuesto» se guardaba como 0.0000 y al reabrir decía «impuesto inválido». */
    public function test_una_solicitud_sin_impuesto_se_reabre_y_se_guarda(): void
    {
        $u = $this->jefa();
        $s = $this->compras()->abrirCarrito($u);
        $s->update(['tax_rate' => 0]);
        $this->compras()->agregar($s, 'Lanyard', 4, 18.99);

        Livewire::test(EditPurchaseRequest::class, ['record' => $s->id])
            ->assertFormSet(['tax_rate' => '0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0.0, (float) $s->fresh()->tax_rate);
        $this->assertSame(18.99, (float) $s->fresh()->items()->first()->unit_price, 'los centavos sobreviven al guardar');
    }

    public function test_desde_el_formulario_se_guarda_en_dolares_con_centavos(): void
    {
        $u = $this->jefa();
        $s = $this->compras()->abrirCarrito($u);
        $this->compras()->agregar($s, 'Lanyard', 4, 0);

        Livewire::test(EditPurchaseRequest::class, ['record' => $s->id])
            ->fillForm(['currency' => 'USD', 'exchange_rate' => 4100])
            ->call('save')
            ->assertHasNoFormErrors();

        $s->refresh();
        $this->assertSame('USD', $s->currency);
        $this->assertSame(4100.0, (float) $s->exchange_rate);
    }

    public function test_en_dolares_hay_que_decir_la_tasa(): void
    {
        $u = $this->jefa();
        $s = $this->compras()->abrirCarrito($u);

        Livewire::test(EditPurchaseRequest::class, ['record' => $s->id])
            ->fillForm(['currency' => 'USD', 'exchange_rate' => null])
            ->call('save')
            ->assertHasFormErrors(['exchange_rate']);
    }
}
