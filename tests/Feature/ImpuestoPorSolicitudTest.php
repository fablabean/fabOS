<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El impuesto se dice por solicitud (§13).
 *
 * Sumarle IVA a todo es correcto para comprar material —compras trabaja con el
 * valor con IVA— pero no todo lo que se pide lo lleva: unos honorarios, un
 * servicio exento. El efecto de no poder decirlo es peor que el de no
 * calcularlo: quien escribe un valor ve otro más alto, no entiende de dónde
 * salió, y deja de fiarse de la cifra.
 */
class ImpuestoPorSolicitudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['fabos.money.tax_rate' => 0.19]);
    }

    private function quien(): User
    {
        return User::create([
            'name' => 'Quien pide', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function solicitudDe(int $valor, ?float $tasa = null): PurchaseRequest
    {
        $compras = app(PurchasingService::class);
        $solicitud = $compras->abrirCarrito($this->quien(), null, 'Unos honorarios');

        if ($tasa !== null) {
            $solicitud->update(['tax_rate' => $tasa]);
        }

        // Sin insumo detras: unos honorarios no reponen nada del catalogo.
        $compras->agregar($solicitud, 'Honorarios de los cursos', 1, $valor);

        return $solicitud->fresh()->load('items');
    }

    public function test_por_defecto_lleva_el_impuesto_del_laboratorio(): void
    {
        $s = $this->solicitudDe(1_989_000);

        $this->assertSame(1_989_000, $s->subtotal());
        $this->assertSame(2_366_910, $s->totalEstimado());
        $this->assertSame(377_910, $s->impuesto());
        $this->assertEqualsWithDelta(0.19, $s->tasaDeImpuesto(), 0.0001);
    }

    /** Unos honorarios no llevan ese IVA, y hay que poder decirlo. */
    public function test_una_solicitud_sin_impuesto_vale_lo_que_dice(): void
    {
        $s = $this->solicitudDe(1_989_000, 0);

        $this->assertSame(1_989_000, $s->subtotal());
        $this->assertSame(1_989_000, $s->totalEstimado());
        $this->assertSame(0, $s->impuesto());
    }

    public function test_se_puede_poner_otra_tasa(): void
    {
        $s = $this->solicitudDe(1_000_000, 0.05);

        $this->assertSame(1_050_000, $s->totalEstimado());
    }

    /**
     * Nulo significa «la del laboratorio»: cambiar la tasa general el día que
     * cambie la ley arrastra a todo lo que no dijo otra cosa.
     */
    public function test_lo_que_no_dijo_nada_sigue_a_la_tasa_general(): void
    {
        $s = $this->solicitudDe(1_000_000);

        config(['fabos.money.tax_rate' => 0.10]);

        $this->assertSame(1_100_000, $s->fresh()->load('items')->totalEstimado());
    }

    /** Y lo que dijo la suya no se mueve cuando cambia la general. */
    public function test_lo_que_dijo_la_suya_no_se_mueve(): void
    {
        $s = $this->solicitudDe(1_000_000, 0);

        config(['fabos.money.tax_rate' => 0.10]);

        $this->assertSame(1_000_000, $s->fresh()->load('items')->totalEstimado());
    }

    // ------------------------------------------------- lo que compromete

    /**
     * Un borrador no compromete nada: por eso el presupuesto seguía en cero.
     * Lo que compromete es aprobarla.
     */
    public function test_un_borrador_no_compromete_el_presupuesto(): void
    {
        $p = Budget::create([
            'name' => 'Honorarios', 'year' => 2026, 'amount' => 30_000_000,
            'status' => 'vigente',
        ]);

        $s = $this->solicitudDe(1_989_000, 0);
        $s->update(['budget_id' => $p->id]);

        $this->assertSame('borrador', $s->fresh()->status);
        $this->assertSame(0, $p->fresh()->comprometido());
    }

    /** Se aprueba directo desde borrador: no hay que «enviársela» a uno mismo. */
    public function test_se_aprueba_desde_borrador_y_compromete(): void
    {
        $p = Budget::create([
            'name' => 'Honorarios', 'year' => 2026, 'amount' => 30_000_000,
            'status' => 'vigente',
        ]);

        $s = $this->solicitudDe(1_989_000, 0);
        $jefa = $this->quien();

        app(PurchasingService::class)->aprobar($s, $jefa, $p);

        $this->assertSame('aprobada', $s->fresh()->status);
        $this->assertSame(1_989_000, $p->fresh()->comprometido());
        $this->assertSame(30_000_000 - 1_989_000, $p->fresh()->disponible());
    }

    /**
     * Lo que se recibe no siempre es mercancia.
     *
     * Unos honorarios o un curso contratado se reciben igual —se dan por
     * cumplidos y ejecutan el presupuesto— pero no reponen nada del catalogo:
     * no pueden mover la existencia de un insumo que no tienen detras.
     */
    public function test_un_servicio_se_recibe_sin_tocar_el_inventario(): void
    {
        $p = Budget::create([
            'name' => 'Honorarios', 'year' => 2026, 'amount' => 30_000_000,
            'status' => 'vigente',
        ]);

        $s = $this->solicitudDe(1_989_000, 0);
        $jefa = $this->quien();

        $compras = app(PurchasingService::class);
        $compras->aprobar($s, $jefa, $p);

        $linea = $s->fresh()->items->first();

        $this->assertNull($linea->supply_id);

        $compras->recibir($s->fresh(), [$linea->id => 1], $jefa);

        $this->assertSame('recibida', $s->fresh()->status);
        $this->assertSame(1_989_000, $p->fresh()->ejecutado());
        $this->assertDatabaseCount('supply_movements', 0);
    }

    /** Y al recibirla pasa de comprometido a ejecutado. */
    public function test_al_recibir_pasa_a_ejecutado(): void
    {
        $p = Budget::create([
            'name' => 'Honorarios', 'year' => 2026, 'amount' => 30_000_000,
            'status' => 'vigente',
        ]);

        $s = $this->solicitudDe(1_989_000, 0);
        $jefa = $this->quien();

        $compras = app(PurchasingService::class);
        $compras->aprobar($s, $jefa, $p);
        $compras->marcarEnCompra($s->fresh());

        $linea = $s->fresh()->items->first();
        $compras->recibir($s->fresh(), [$linea->id => 1], $jefa);

        $this->assertSame(0, $p->fresh()->comprometido());
        $this->assertSame(1_989_000, $p->fresh()->ejecutado());
    }
}
