<?php

namespace Tests\Feature;

use App\Filament\Pages\Cobros;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cobrar en la tienda aunque las reservas sigan sin cobrar (§14).
 *
 * Con un solo interruptor, encender la tienda obligaba a encender las
 * reservas, y como las tarifas seguian en duda nadie lo encendia: la gente
 * compraba «con FabCoins» y no se le descontaba nada.
 */
class CobrosEnLaTiendaTest extends TestCase
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

    private function cliente(int $saldo = 50_000): User
    {
        $cat = UserCategory::create(['slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true]);
        $u = User::create([
            'name' => 'Cliente', 'email' => uniqid() . '@test.co', 'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($saldo > 0) {
            app(ChargeService::class)->dotar($u, $saldo, '2026-09');
        }

        return $u->fresh();
    }

    private function insumo(): Supply
    {
        return Supply::create([
            'name' => 'Filamento PLA', 'unit' => 'kg', 'stock' => 10, 'last_cost' => 9_000,
            'is_active' => true, 'is_public' => true,
        ]);
    }

    /** Lo que pasaba: con todo apagado, se compra y no se descuenta nada. */
    public function test_con_los_cobros_apagados_la_tienda_no_descuenta_y_lo_dice(): void
    {
        $cliente = $this->cliente();
        $insumo = $this->insumo();

        $this->actingAs($cliente)->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 1]);

        $this->actingAs($cliente)->get(route('tienda.publica'))->assertSee('Los cobros están apagados por ahora');

        $this->actingAs($cliente)->post(route('tienda.pagar'))->assertRedirect(route('tienda.publica'));

        $this->assertSame('pagada', Sale::sole()->status);
        $this->assertSame(50_000, app(LedgerService::class)->saldoDe($cliente), 'no se descontó nada');
    }

    /** Con la tienda encendida por su cuenta, comprar descuenta el saldo. */
    public function test_la_tienda_cobra_sola_aunque_las_reservas_no(): void
    {
        Setting::put(Settings::COBROS_TIENDA, true, 'finanzas');

        $cliente = $this->cliente();
        $insumo = $this->insumo();

        $this->actingAs($cliente)->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 1]);
        $this->actingAs($cliente)->get(route('tienda.publica'))->assertDontSee('Los cobros están apagados por ahora');
        $this->actingAs($cliente)->post(route('tienda.pagar'))->assertRedirect(route('tienda.publica'));

        $venta = Sale::sole();

        $this->assertSame('pagada', $venta->status);
        $this->assertSame(50_000 - $venta->total_minor, app(LedgerService::class)->saldoDe($cliente));
        $this->assertGreaterThan(0, $venta->total_minor);

        // Y las reservas siguen sin cobrar.
        $this->assertFalse(app(ChargeService::class)->activo());
        $this->assertFalse(Settings::cobrosActivos());
        $this->assertTrue(Settings::cobrosEnTienda());
    }

    /** Sin saldo no hay compra, y no queda una venta a medias. */
    public function test_sin_saldo_no_se_compra(): void
    {
        Setting::put(Settings::COBROS_TIENDA, true, 'finanzas');

        $cliente = $this->cliente(saldo: 0);
        $insumo = $this->insumo();

        $this->actingAs($cliente)->post(route('tienda.carrito.agregar'), ['tipo' => 'insumo', 'id' => $insumo->id, 'cantidad' => 1]);

        $this->actingAs($cliente)
            ->post(route('tienda.pagar'))
            ->assertSessionHasErrors('carrito');

        $this->assertSame(0, Sale::count());
        $this->assertSame(10.0, (float) $insumo->fresh()->stock, 'la existencia no se movió');
    }

    /** El cobro general enciende la tienda de todos modos. */
    public function test_el_cobro_general_tambien_enciende_la_tienda(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $this->assertTrue(Settings::cobrosEnTienda());
    }

    /** Se enciende desde Finanzas → Cobros, en su propia casilla. */
    public function test_se_enciende_desde_la_pagina_de_cobros(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $super = User::create(['name' => 'Super', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $super->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($super);
        $servicio->confirmar($super, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($super->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(Cobros::class)
            ->assertSet('cobrosTienda', false)
            ->set('cobrosTienda', true)
            ->call('save');

        $this->assertTrue(Settings::cobrosEnTienda());
        $this->assertFalse(Settings::cobrosActivos());
    }
}
