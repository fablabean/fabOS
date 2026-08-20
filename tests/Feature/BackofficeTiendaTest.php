<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Shop\ShopService;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** El mostrador y el catálogo, tal como se usan (§14). */
class BackofficeTiendaTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function insumo(array $datos = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'Filamento PLA ' . uniqid(), 'unit' => 'kg',
            'stock' => 10, 'last_cost' => 90_000, 'is_active' => true,
        ], $datos));
    }

    public function test_el_listado_de_ventas_carga(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $cliente = $this->conRol();
        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($cliente, $admin);
        $tienda->agregarInsumo($venta, $this->insumo(), 2);

        $this->entra($admin)->get('/admin/sales')
            ->assertOk()
            ->assertSee($venta->code)
            ->assertSee($cliente->name);
    }

    public function test_cobrar_desde_el_mostrador_descuenta_saldo_y_existencia(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $cliente = $this->conRol();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo(['stock' => 10]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($cliente, $admin);
        $tienda->agregarInsumo($venta, $insumo, 2);

        $this->entra($admin);

        Livewire::test(ListSales::class)
            ->callAction(TestAction::make('cobrar')->table($venta))
            ->assertHasNoActionErrors();

        $this->assertSame('pagada', $venta->fresh()->status);
        $this->assertSame(8.0, (float) $insumo->fresh()->stock);
        $this->assertSame(26_600, app(LedgerService::class)->saldoDe($cliente));
    }

    public function test_cobrar_sin_saldo_avisa_y_no_mueve_nada(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $cliente = $this->conRol();
        $insumo = $this->insumo(['stock' => 10]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($cliente, $admin);
        $tienda->agregarInsumo($venta, $insumo, 2);

        $this->entra($admin);

        Livewire::test(ListSales::class)
            ->callAction(TestAction::make('cobrar')->table($venta));

        // El error se muestra como notificación, no como excepción: quien
        // atiende tiene que poder seguir trabajando.
        $this->assertSame('abierta', $venta->fresh()->status);
        $this->assertSame(10.0, (float) $insumo->fresh()->stock);
    }

    public function test_anular_desde_el_mostrador_devuelve_todo(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $cliente = $this->conRol();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo(['stock' => 10]);

        $tienda = app(ShopService::class);
        $venta = $tienda->abrirVenta($cliente, $admin);
        $tienda->agregarInsumo($venta, $insumo, 2);
        $tienda->cobrar($venta->refresh(), $admin);

        $this->entra($admin);

        Livewire::test(ListSales::class)
            ->callAction(TestAction::make('anular')->table($venta->refresh()), [
                'motivo' => 'El rollo venía dañado',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('anulada', $venta->fresh()->status);
        $this->assertSame(50_000, app(LedgerService::class)->saldoDe($cliente));
        $this->assertSame(10.0, (float) $insumo->fresh()->stock);
    }

    public function test_la_tienda_publica_muestra_precios_y_saldo(): void
    {
        $cliente = $this->conRol();
        app(ChargeService::class)->dotar($cliente, 45_000, '2026-08');
        $this->insumo(['name' => 'Filamento PLA negro', 'stock' => 5, 'last_cost' => 90_000]);
        $this->insumo(['name' => 'Agotado del todo', 'stock' => 0]);

        $this->actingAs($cliente)
            ->get(route('tienda'))
            ->assertOk()
            ->assertSee('Filamento PLA negro')
            ->assertSee('117,00')             // 90.000 + 30% a 1.000 por FabCoin
            ->assertSee('450,00')             // su saldo
            ->assertSee('estimado')           // el precio es calculado, y se dice
            ->assertDontSee('Agotado del todo');
    }

    public function test_las_reglas_de_la_tienda_quedan_documentadas(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->insumo(['name' => 'Filamento de prueba', 'stock' => 5]);

        $this->entra($admin)->get('/admin/reglas')
            ->assertOk()
            ->assertSee('De dónde sale el precio de un insumo')
            ->assertSee('Tasa supuesta')
            ->assertSee('30% sobre el costo')
            ->assertSee('Anular no borra');
    }

    public function test_la_tienda_exige_sesion(): void
    {
        $this->get(route('tienda'))->assertRedirect(route('login'));
    }
}
