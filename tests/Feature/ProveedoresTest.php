<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
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
 * La lista de proveedores (§13).
 *
 * Se llena sola desde las solicitudes de compra, y desde ella se eligen para
 * no volver a teclear el NIT de la misma empresa.
 */
class ProveedoresTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function solicitud(User $quien): PurchaseRequest
    {
        $presupuesto = Budget::create(['name' => 'Materiales', 'year' => 2026, 'amount' => 10_000_000, 'status' => 'vigente']);

        return app(PurchasingService::class)->abrirCarrito($quien, $presupuesto, 'Lanyards')->fresh();
    }

    /** Escrito a mano en una solicitud, el proveedor queda en la lista. */
    public function test_el_proveedor_de_una_solicitud_queda_en_la_lista(): void
    {
        $s = $this->solicitud($this->administrador());

        $s->update([
            'supplier_name' => 'Tap Publicidad S.A.S.', 'supplier_tax_id' => '901.234.567-8',
            'supplier_address' => 'Calle 72 # 10-34', 'supplier_contact' => 'Jonathan Rincón',
        ]);

        $p = Supplier::sole();
        $this->assertSame('Tap Publicidad S.A.S.', $p->name);
        $this->assertSame('901.234.567-8', $p->tax_id);
        $this->assertSame('Jonathan Rincón', $p->contact);
        $this->assertSame($p->id, $s->fresh()->supplier_id);
    }

    /** El mismo NIT escrito de otra forma, o el mismo nombre, es el mismo proveedor. */
    public function test_no_se_duplica_el_mismo_proveedor(): void
    {
        $admin = $this->administrador();

        $this->solicitud($admin)->update(['supplier_name' => 'Tap Publicidad S.A.S.', 'supplier_tax_id' => '901.234.567-8']);
        $this->solicitud($admin)->update(['supplier_name' => 'TAP PUBLICIDAD', 'supplier_tax_id' => '9012345678', 'supplier_address' => 'Calle 72']);
        $this->solicitud($admin)->update(['supplier_name' => 'tap publicidad s.a.s.']);

        $p = Supplier::sole();

        // Lo que ya tenía no se pisa; lo que le faltaba, se completa.
        $this->assertSame('Tap Publicidad S.A.S.', $p->name);
        $this->assertSame('Calle 72', $p->address);
        $this->assertSame(3, $p->purchaseRequests()->count());
    }

    /** La requisición ya hecha no cambia porque después se corrija el proveedor. */
    public function test_la_solicitud_conserva_su_copia_de_los_datos(): void
    {
        $s = $this->solicitud($this->administrador());
        $s->update(['supplier_name' => 'Tap Publicidad', 'supplier_address' => 'Calle 72']);

        Supplier::sole()->update(['address' => 'Carrera 7 # 1-1', 'name' => 'Tap Publicidad S.A.S.']);

        $s->refresh();
        $this->assertSame('Tap Publicidad', $s->supplier_name);
        $this->assertSame('Calle 72', $s->supplier_address);
    }

    /** Quitar el proveedor de la solicitud la desenlaza, sin borrar al proveedor. */
    public function test_quitar_el_proveedor_desenlaza_la_solicitud(): void
    {
        $s = $this->solicitud($this->administrador());
        $s->update(['supplier_name' => 'Tap Publicidad']);

        $s->update(['supplier_name' => null]);

        $this->assertNull($s->fresh()->supplier_id);
        $this->assertSame(1, Supplier::count());
    }

    /** Elegirlo de la lista llena los datos de la solicitud. */
    public function test_elegir_un_proveedor_de_la_lista_llena_sus_datos(): void
    {
        $admin = $this->administrador();
        $p = Supplier::create(['name' => 'Acrílicos del Norte', 'tax_id' => '800.111.222-3', 'address' => 'Av. 68 # 5-10', 'contact' => 'Marta Ruiz']);
        $s = $this->solicitud($admin);

        Livewire::test(EditPurchaseRequest::class, ['record' => $s->getRouteKey()])
            ->set('data.proveedor_de_la_lista', $p->id)
            ->assertSet('data.supplier_name', 'Acrílicos del Norte')
            ->assertSet('data.supplier_tax_id', '800.111.222-3')
            ->assertSet('data.supplier_address', 'Av. 68 # 5-10')
            ->assertSet('data.supplier_contact', 'Marta Ruiz')
            ->call('save')
            ->assertHasNoFormErrors();

        $s->refresh();
        $this->assertSame($p->id, $s->supplier_id);
        $this->assertSame('Acrílicos del Norte', $s->supplier_name);
        $this->assertSame(1, Supplier::count(), 'no se creó otro');
    }

    public function test_la_lista_de_proveedores_se_ve_en_el_panel(): void
    {
        $this->administrador();
        Supplier::create(['name' => 'Acrílicos del Norte', 'tax_id' => '800.111.222-3']);

        Livewire::test(ListSuppliers::class)
            ->assertOk()
            ->assertSee('Acrílicos del Norte')
            ->assertSee('800.111.222-3');
    }
}
