<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Purchasing\PurchasingService;
use App\Support\FactoresDeSesion;
use App\Services\Auth\TwoFactorService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La requisición compartida con compras y el carrito ya armado (§13).
 *
 * Quien recibe la requisición en el área de compras no tiene cuenta en fabOS:
 * abre un enlace, ve el documento y baja el PDF. El enlace no existe hasta
 * que alguien decide compartir, y se puede revocar.
 */
class RequisicionCompartidaTest extends TestCase
{
    use RefreshDatabase;

    private function compras(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function administrador(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = $this->persona();
        $u->assignRole(User::ROL_ADMINISTRADOR);

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

    private function solicitud(?User $quien = null): PurchaseRequest
    {
        $presupuesto = Budget::create([
            'name' => 'Materiales', 'year' => 2026, 'amount' => 10_000_000, 'status' => 'vigente',
        ]);

        $carrito = $this->compras()->abrirCarrito($quien ?? $this->persona(), $presupuesto, 'Sensores para el semillero');
        // Algo que no esta en el catalogo: se pide con sus palabras, sin insumo.
        $this->compras()->agregar($carrito, 'Sensor de temperatura DS18B20', 10, 12_000, proveedor: 'Amazon');
        $carrito->update(['cart_url' => 'https://www.amazon.com/gp/cart/view.html?ref_=nav_cart&id=abc123']);

        return $carrito->fresh();
    }

    // ---------------------------------------------------------- el enlace

    public function test_sin_compartir_no_hay_enlace(): void
    {
        $s = $this->solicitud();

        $this->assertFalse($s->estaCompartida());
        $this->assertNull($s->enlaceCompartido());
    }

    public function test_compartir_da_el_mismo_enlace_las_dos_veces(): void
    {
        $s = $this->solicitud();

        // Quien ya lo mando por correo espera que el segundo sea el mismo.
        $primero = $this->compras()->compartir($s);
        $segundo = $this->compras()->compartir($s->fresh());

        $this->assertSame($primero, $segundo);
        $this->assertStringContainsString('/compras/compartida/', $primero);
        $this->assertNotNull($s->fresh()->shared_at);
    }

    public function test_el_enlace_abre_sin_sesion_y_muestra_todo(): void
    {
        $s = $this->solicitud();
        $enlace = $this->compras()->compartir($s);

        $this->get($enlace)
            ->assertOk()
            ->assertSee($s->code)
            ->assertSee('Sensor de temperatura DS18B20')
            ->assertSee('Carrito ya armado')
            ->assertSee('https://www.amazon.com/gp/cart/view.html?ref_=nav_cart&amp;id=abc123', false)
            ->assertSee('Descargar PDF');
    }

    public function test_el_enlace_deja_bajar_el_pdf(): void
    {
        $s = $this->solicitud();
        $this->compras()->compartir($s);

        $respuesta = $this->get(route('compras.compartida.pdf', $s->fresh()->share_token))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString($s->code . '.pdf', $respuesta->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_un_enlace_inventado_no_abre_nada(): void
    {
        $this->solicitud();

        $this->get(route('compras.compartida', str_repeat('x', 40)))->assertNotFound();
        $this->get(route('compras.compartida.pdf', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_dejar_de_compartir_apaga_el_enlace_y_el_siguiente_es_otro(): void
    {
        $s = $this->solicitud();
        $viejo = $this->compras()->compartir($s);

        $this->compras()->dejarDeCompartir($s->fresh());

        $this->assertFalse($s->fresh()->estaCompartida());
        $this->get($viejo)->assertNotFound();

        $this->assertNotSame($viejo, $this->compras()->compartir($s->fresh()));
    }

    // ------------------------------------------------------- con sesión

    public function test_desde_el_panel_tambien_se_baja_el_pdf(): void
    {
        $u = $this->administrador();
        $s = $this->solicitud($u);

        $this->entra($u)->get(route('compras.requisicion.pdf', $s))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Y la pantalla con sesion lleva el boton para bajarlo.
        $this->entra($u)->get(route('compras.requisicion', $s))
            ->assertOk()
            ->assertSee('Descargar PDF')
            ->assertSee('Carrito ya armado');
    }

    public function test_quien_no_entra_al_backoffice_no_baja_el_pdf(): void
    {
        $s = $this->solicitud();

        $this->actingAs($this->persona())
            ->get(route('compras.requisicion.pdf', $s))
            ->assertForbidden();
    }

    public function test_la_ficha_pide_el_enlace_del_carrito_y_deja_compartir(): void
    {
        $u = $this->administrador();
        $s = $this->solicitud($u);
        $this->compras()->compartir($s);

        $this->entra($u)->get("/admin/purchase-requests/{$s->id}/edit")
            ->assertOk()
            ->assertSee('Enlace del carrito')
            ->assertSee('No tiene que estar en el catálogo')
            ->assertSee('Enlace para compras')
            ->assertSee('Dejar de compartir');
    }

    public function test_la_accion_del_listado_comparte_y_revoca(): void
    {
        $u = $this->administrador();
        $s = $this->solicitud($u);
        $this->entra($u);

        // Abrir el dialogo ya comparte: el enlace queda listo para copiar.
        Livewire::test(ListPurchaseRequests::class)
            ->mountAction(TestAction::make('compartir')->table($s))
            ->assertActionMounted(TestAction::make('compartir')->table($s));

        $this->assertTrue($s->fresh()->estaCompartida());

        Livewire::test(ListPurchaseRequests::class)
            ->callAction(TestAction::make('dejarDeCompartir')->table($s))
            ->assertHasNoActionErrors();

        $this->assertFalse($s->fresh()->estaCompartida());
    }
}
