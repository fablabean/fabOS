<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Supply;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Purchasing\PurchasingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de Compras responden como una persona las usaría (§13). */
class BackofficeComprasTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole($rol);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession(['segundo_factor_verificado' => true]);

        return $this;
    }

    private function solicitudAprobada(User $u): PurchaseRequest
    {
        $compras = app(PurchasingService::class);
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => 2026, 'amount' => 10_000_000, 'status' => 'vigente',
        ]);

        $carrito = $compras->abrirCarrito($u, $presupuesto, 'Reposición del semestre');
        $compras->agregar($carrito, 'Filamento PLA negro', 4, 90_000);
        $compras->enviar($carrito);

        return $compras->aprobar($carrito->refresh(), $u);
    }

    public function test_los_listados_de_compras_cargan(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->solicitudAprobada($u);
        Supply::create(['name' => 'Resina estándar', 'unit' => 'ml', 'stock' => 500]);

        $this->entra($u)->get('/admin/budgets')->assertOk()->assertSee('Insumos');
        $this->entra($u)->get('/admin/purchase-requests')->assertOk()->assertSee('COM-');
        $this->entra($u)->get('/admin/supplies')->assertOk()->assertSee('Resina estándar');
    }

    public function test_el_presupuesto_muestra_comprometido_y_disponible(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->solicitudAprobada($u);

        // 4 × 90.000 = 360.000 + IVA = 428.400 comprometidos de 10.000.000.
        $this->entra($u)->get('/admin/budgets')
            ->assertOk()
            ->assertSee('$428.400')
            ->assertSee('$9.571.600');
    }

    public function test_la_requisicion_se_puede_imprimir(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $solicitud = $this->solicitudAprobada($u);

        // Es el entregable del módulo: el papel que va al área de compras.
        $this->entra($u)->get(route('compras.requisicion', $solicitud))
            ->assertOk()
            ->assertSee($solicitud->code)
            ->assertSee('Filamento PLA negro')
            ->assertSee('Requisición de compra')
            ->assertSee('$428.400');
    }

    public function test_quien_no_entra_al_backoffice_no_ve_la_requisicion(): void
    {
        $solicitud = $this->solicitudAprobada($this->conRol(User::ROL_ADMINISTRADOR));

        $ajeno = User::create([
            'name' => 'Ajeno', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->actingAs($ajeno)
            ->get(route('compras.requisicion', $solicitud))
            ->assertForbidden();
    }

    public function test_recibir_desde_el_backoffice_mueve_el_inventario(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $insumo = Supply::create(['name' => 'Filamento PLA', 'unit' => 'kg', 'stock' => 1]);

        $compras = app(PurchasingService::class);
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => 2026, 'amount' => 5_000_000, 'status' => 'vigente',
        ]);
        $carrito = $compras->abrirCarrito($u, $presupuesto);
        $linea = $compras->agregar($carrito, 'Filamento PLA', 4, 90_000, $insumo);
        $compras->enviar($carrito);
        $solicitud = $compras->aprobar($carrito->refresh(), $u);

        $this->entra($u);

        Livewire::test(ListPurchaseRequests::class)
            ->callAction(TestAction::make('recibir')->table($solicitud), [
                'linea_' . $linea->id => 4,
                'memo' => 'Llegó completo',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('recibida', $solicitud->fresh()->status);
        $this->assertSame(5.0, (float) $insumo->fresh()->stock);
    }

    public function test_las_reglas_de_compras_quedan_documentadas(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->solicitudAprobada($u);

        $this->entra($u)->get('/admin/reglas')
            ->assertOk()
            ->assertSee('El camino de una compra')
            ->assertSee('El laboratorio no compra: pide')
            ->assertSee('$9.571.600');
    }

    public function test_el_carrito_de_reposicion_se_arma_solo(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        Supply::create(['name' => 'Resina', 'unit' => 'ml', 'stock' => 100, 'reorder_point' => 500]);

        $this->entra($u);

        Livewire::test(ListPurchaseRequests::class)
            ->callAction('reposicion')
            ->assertHasNoActionErrors();

        $carrito = PurchaseRequest::latest('id')->first();

        $this->assertNotNull($carrito);
        $this->assertSame('borrador', $carrito->status);
        $this->assertSame(1, $carrito->items()->count());
        $this->assertSame(900.0, (float) $carrito->items()->first()->quantity);
    }
}
