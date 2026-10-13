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

    // ------------------------------------------- el NIT, escrito como sea

    /**
     * Con puntos o sin ellos, con guion o sin él, con o sin el dígito de
     * verificación: es el mismo proveedor.
     */
    public function test_el_nit_se_reconoce_escrito_de_cualquier_forma(): void
    {
        $p = Supplier::create(['name' => 'Tap Publicidad S.A.S.', 'tax_id' => '901.234.567-8']);

        foreach (['901.234.567-8', '901234567-8', '9012345678', '901.234.5678', '901 234 567 8', '901234567', '901.234.567'] as $escrito) {
            $this->assertSame($p->id, Supplier::conNit($escrito)->value('id'), "No reconoció «{$escrito}»");
            $this->assertSame($p->id, Supplier::anotar(['name' => 'Otro nombre', 'tax_id' => $escrito])->id, "Duplicó con «{$escrito}»");
        }

        $this->assertSame(1, Supplier::count());

        // Otro NIT es otro proveedor, aunque se parezca.
        $this->assertNull(Supplier::conNit('901.234.999-1')->value('id'));
    }

    /** Anotado sin dígito de verificación, cuando después llega con él se completa. */
    public function test_el_nit_mas_completo_se_queda(): void
    {
        Supplier::create(['name' => 'Tap Publicidad', 'tax_id' => '901234567']);

        Supplier::anotar(['name' => 'Tap Publicidad', 'tax_id' => '901.234.567-8']);

        $this->assertSame('901.234.567-8', Supplier::sole()->tax_id);
    }

    /** Y se busca igual: tecleando un trozo, con o sin puntos. */
    public function test_se_busca_por_nit_con_o_sin_puntos(): void
    {
        $this->administrador();
        $tap = Supplier::create(['name' => 'Tap Publicidad S.A.S.', 'tax_id' => '901.234.567-8']);
        $otro = Supplier::create(['name' => 'Acrílicos del Norte', 'tax_id' => '800.111.222-3']);

        foreach (['901234567', '901.234', '9012345678', '234567-8'] as $tecleado) {
            $this->assertSame([$tap->id], Supplier::buscar($tecleado)->pluck('id')->all(), "No encontró con «{$tecleado}»");
        }

        // Por nombre sigue funcionando, y un texto sin números no se toma por NIT.
        $this->assertSame([$otro->id], Supplier::buscar('acrílicos')->pluck('id')->all());

        Livewire::test(ListSuppliers::class)
            ->searchTable('901234567')
            ->assertCanSeeTableRecords([$tap])
            ->assertCanNotSeeTableRecords([$otro]);
    }

    // ----------------------------------- de perfil profesional a proveedor

    /** Un perfil al que ya se le compra pasa a la lista de proveedores, enlazado. */
    public function test_un_perfil_profesional_pasa_a_proveedores(): void
    {
        $this->administrador();

        $perfil = \App\Models\ProfessionalProfile::create([
            'name' => 'Sofía Barriga', 'specialty' => 'Contenido para redes', 'email' => 'sofia@test.co',
            'phone' => '+57 3203492501', 'person_kind' => 'natural', 'document_type' => 'CC',
            'document_number' => '1023375338', 'address' => 'Calle 1 # 2-3', 'city' => 'Bogotá',
        ]);

        Livewire::test(\App\Filament\Resources\ProfessionalProfiles\Pages\ListProfessionalProfiles::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('proveedor')->table($perfil))
            ->assertHasNoActionErrors();

        $p = Supplier::sole();
        $this->assertSame('Sofía Barriga', $p->name);
        $this->assertSame('1023375338', $p->tax_id);
        $this->assertSame('Calle 1 # 2-3, Bogotá', $p->address);
        $this->assertSame('Sofía Barriga', $p->contact);
        $this->assertSame('+57 3203492501', $p->phone);
        $this->assertSame('sofia@test.co', $p->email);
        $this->assertSame($perfil->id, $p->professional_profile_id);

        // Pasarlo otra vez no crea otro.
        Supplier::desdeElPerfil($perfil);
        $this->assertSame(1, Supplier::count());
    }

    /** Si es una empresa, el proveedor es la empresa y la persona, su representante. */
    public function test_el_perfil_de_una_empresa_pasa_con_su_razon_social(): void
    {
        $perfil = \App\Models\ProfessionalProfile::create([
            'name' => 'Jonathan Rincón', 'email' => 'j@test.co', 'person_kind' => 'juridica',
            'document_type' => 'NIT', 'document_number' => '901234567', 'document_dv' => '8',
            'legal_name' => 'Tap Publicidad S.A.S.', 'representative' => 'Jonathan Rincón Pineda',
        ]);

        $p = Supplier::desdeElPerfil($perfil);

        $this->assertSame('Tap Publicidad S.A.S.', $p->name);
        $this->assertSame('901234567-8', $p->tax_id);
        $this->assertSame('Jonathan Rincón Pineda', $p->contact);
    }

    /** Si ya estaba en la lista por su NIT, se enlaza a ese en vez de duplicarlo. */
    public function test_un_perfil_cuyo_nit_ya_esta_en_la_lista_se_enlaza_al_que_habia(): void
    {
        $ya = Supplier::create(['name' => 'Tap Publicidad', 'tax_id' => '901.234.567-8']);

        $perfil = \App\Models\ProfessionalProfile::create([
            'name' => 'Jonathan Rincón', 'email' => 'j@test.co', 'person_kind' => 'juridica',
            'document_type' => 'NIT', 'document_number' => '901234567', 'document_dv' => '8',
            'legal_name' => 'Tap Publicidad S.A.S.',
        ]);

        $p = Supplier::desdeElPerfil($perfil);

        $this->assertSame($ya->id, $p->id);
        $this->assertSame($perfil->id, $p->professional_profile_id);
        $this->assertSame(1, Supplier::count());
    }
}
