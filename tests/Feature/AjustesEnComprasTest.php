<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Models\Area;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestAdjustment;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Purchasing\PurchasingException;
use App\Services\Purchasing\PurchasingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Descuentos y cobros adicionales en una solicitud de compra (§13).
 *
 * Las líneas sumaban bien y aun así la cuenta no daba: Amazon aplica un
 * descuento sobre el pedido, cobra el envío aparte, a veces un cargo de
 * importación. Nada de eso es una línea con cantidad y precio.
 */
class AjustesEnComprasTest extends TestCase
{
    use RefreshDatabase;

    private function compras(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    private function persona(): User
    {
        return User::create(['name' => 'Ana', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
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

    /** Un carrito en pesos: 200.000 de líneas, 19 % de impuesto. */
    private function enPesos(User $u): PurchaseRequest
    {
        $carrito = $this->compras()->abrirCarrito($u);
        $carrito->update(['tax_rate' => 0.19]);
        $this->compras()->agregar($carrito, 'Filamento', 2, 100_000);

        return $carrito;
    }

    private function cargado(PurchaseRequest $s): PurchaseRequest
    {
        return $s->fresh()->load(['items', 'adjustments']);
    }

    // ------------------------------------------------------------ la cuenta

    /** Un descuento baja el subtotal y la base del impuesto. */
    public function test_un_descuento_baja_la_base_y_el_total(): void
    {
        $s = $this->enPesos($this->persona());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::DESCUENTO, 'Descuento de Amazon', 20_000);
        $s = $this->cargado($s);

        $this->assertSame(200_000.0, $s->subtotalEnMoneda(), 'el subtotal de las líneas no cambia');
        $this->assertSame(20_000.0, $s->descuentosEnMoneda());
        $this->assertSame(180_000.0, $s->baseGravableEnMoneda());
        $this->assertSame(34_200.0, $s->impuestoEnMoneda(), '19 % de 180.000');
        $this->assertSame(214_200.0, $s->totalEnMoneda());
        $this->assertSame(214_200, $s->totalEstimado());
    }

    /** Un envío suma, y por defecto no lleva impuesto. */
    public function test_un_cobro_sin_impuesto_suma_al_final(): void
    {
        $s = $this->enPesos($this->persona());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::COBRO, 'Envío', 15_000);
        $s = $this->cargado($s);

        $this->assertFalse($s->adjustments->first()->applies_tax);
        $this->assertSame(200_000.0, $s->baseGravableEnMoneda(), 'el envío no entra en la base');
        $this->assertSame(38_000.0, $s->impuestoEnMoneda());
        $this->assertSame(253_000.0, $s->totalEnMoneda(), '200.000 + 38.000 + 15.000');
    }

    /** Y si se dice que sí lleva, entra en la base. */
    public function test_un_cobro_con_impuesto_entra_en_la_base(): void
    {
        $s = $this->enPesos($this->persona());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::COBRO, 'Instalación', 10_000, llevaImpuesto: true);
        $s = $this->cargado($s);

        $this->assertSame(210_000.0, $s->baseGravableEnMoneda());
        $this->assertSame(39_900.0, $s->impuestoEnMoneda());
        $this->assertSame(249_900.0, $s->totalEnMoneda());
    }

    /** Lo de Amazon, completo: en dólares, con descuento y envío. */
    public function test_la_cuenta_de_amazon_da(): void
    {
        $carrito = $this->compras()->abrirCarrito($this->persona());
        $carrito->update(['currency' => 'USD', 'exchange_rate' => 4000, 'tax_rate' => 0.19]);
        $this->compras()->agregar($carrito, 'Lanyard blanco', 4, 18.99);   // 75,96
        $this->compras()->agregar($carrito, 'Porta carnet', 5, 9.99);      // 49,95 → 125,91
        $this->compras()->ajustar($carrito, PurchaseRequestAdjustment::DESCUENTO, 'Cupón 10 %', 12.59);
        $this->compras()->ajustar($carrito, PurchaseRequestAdjustment::COBRO, 'Envío internacional', 23.40);
        $s = $this->cargado($carrito);

        $this->assertSame(125.91, $s->subtotalEnMoneda());
        $this->assertSame(113.32, $s->baseGravableEnMoneda());
        $this->assertSame(21.53, $s->impuestoEnMoneda(), '19 % de 113,32');
        // 125,91 − 12,59 + 23,40 + 21,53
        $this->assertSame(158.25, $s->totalEnMoneda());
        $this->assertSame(633_000, $s->totalEstimado(), 'a 4.000 por dólar');
        $this->assertStringContainsString('de descuento', $s->comoSeCalcula());
        $this->assertStringContainsString('cobros adicionales', $s->comoSeCalcula());
    }

    /** Un descuento mayor que el pedido no deja un total negativo. */
    public function test_el_total_nunca_es_negativo(): void
    {
        $s = $this->enPesos($this->persona());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::DESCUENTO, 'Bono', 500_000);
        $s = $this->cargado($s);

        $this->assertSame(0.0, $s->impuestoEnMoneda());
        $this->assertSame(0.0, $s->totalEnMoneda());
    }

    // ---------------------------------------------- lo recibido y el presupuesto

    /** Se aprueba contra el total con los ajustes, no contra el de las líneas. */
    public function test_el_presupuesto_se_compromete_con_los_ajustes(): void
    {
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => 2026, 'amount' => 300_000, 'status' => 'vigente',
            'area_id' => Area::create(['slug' => 'a', 'name' => 'Área'])->id,
        ]);

        $s = $this->enPesos($this->persona());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::COBRO, 'Envío', 15_000);
        $s = $this->compras()->enviar($this->cargado($s));
        $this->compras()->aprobar($s, $this->persona(), $presupuesto);

        // 238.000 + 15.000 de envío = 253.000 comprometidos.
        $this->assertSame(300_000 - 253_000, $presupuesto->fresh()->disponible());
    }

    /** Lo recibido va en proporción: al llegar todo, es el total exacto. */
    public function test_lo_recibido_reparte_los_ajustes_en_proporcion(): void
    {
        $s = $this->enPesos($this->persona());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::COBRO, 'Envío', 15_000);
        $s = $this->compras()->enviar($this->cargado($s));
        $s = $this->compras()->aprobar($s, $this->persona(), Budget::create([
            'name' => 'Insumos', 'year' => 2026, 'amount' => 900_000, 'status' => 'vigente',
            'area_id' => Area::create(['slug' => 'a', 'name' => 'Área'])->id,
        ]));

        $linea = $s->items()->first();

        // La mitad de las líneas: la mitad del total, envío incluido.
        $s = $this->compras()->recibir($s, [$linea->id => 1], $this->persona());
        $this->assertSame(126_500, $this->cargado($s)->recibidoEnPesos(), 'la mitad de 253.000');
        $this->assertSame(126_500, $this->cargado($s)->pendienteEnPesos());

        // Todo: el total exacto, y nada pendiente.
        $s = $this->compras()->recibir($s, [$linea->id => 1], $this->persona());
        $this->assertSame(253_000, $this->cargado($s)->recibidoEnPesos());
        $this->assertSame(0, $this->cargado($s)->pendienteEnPesos());
    }

    // ------------------------------------------------------------ las reglas

    public function test_el_valor_va_en_positivo_y_el_tipo_pone_el_signo(): void
    {
        $s = $this->enPesos($this->persona());

        $this->expectException(PurchasingException::class);
        $this->expectExceptionMessage('mayor que cero');

        $this->compras()->ajustar($s, PurchaseRequestAdjustment::DESCUENTO, 'Descuento', -20_000);
    }

    public function test_una_solicitud_cerrada_no_admite_ajustes(): void
    {
        $s = $this->enPesos($this->persona());
        $s->update(['status' => 'recibida']);

        $this->expectException(PurchasingException::class);

        $this->compras()->ajustar($s->fresh(), PurchaseRequestAdjustment::COBRO, 'Envío', 1_000);
    }

    // ------------------------------------------------------------ en pantalla

    /** La requisición los enseña, con su signo y si llevan impuesto. */
    public function test_la_requisicion_los_enseña(): void
    {
        $s = $this->enPesos($this->jefa());
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::DESCUENTO, 'Descuento de Amazon', 20_000);
        $this->compras()->ajustar($s, PurchaseRequestAdjustment::COBRO, 'Envío', 15_000);

        $this->get(route('compras.requisicion', $s))
            ->assertOk()
            ->assertSee('Descuento de Amazon')
            ->assertSee('− $20.000', false)
            ->assertSee('Envío')
            ->assertSee('+ $15.000', false)
            ->assertSee('(sin impuesto)')
            ->assertSee('$229.200'); // 200.000 − 20.000 + 15.000 + 34.200
    }

    /** Y se escriben desde la ficha, en su propio bloque. */
    public function test_se_anotan_desde_la_ficha(): void
    {
        $s = $this->enPesos($this->jefa());

        Livewire::test(EditPurchaseRequest::class, ['record' => $s->id])
            ->fillForm([
                'adjustments' => [
                    ['kind' => 'descuento', 'description' => 'Descuento de Amazon', 'amount' => 20000, 'applies_tax' => true],
                    ['kind' => 'cobro', 'description' => 'Envío', 'amount' => 15000, 'applies_tax' => false],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $s = $this->cargado($s);

        $this->assertCount(2, $s->adjustments);
        $this->assertSame(229_200, $s->totalEstimado());
    }
}
