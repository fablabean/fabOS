<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Supply;
use App\Models\User;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\PurchasingException;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Presupuesto, solicitudes de compra y entrada al inventario (§13). */
class ComprasTest extends TestCase
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

    private function presupuesto(int $monto = 10_000_000): Budget
    {
        return Budget::create([
            'name' => 'Insumos ' . uniqid(), 'year' => 2026,
            'area_id' => Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área'])->id,
            'amount' => $monto, 'status' => 'vigente',
        ]);
    }

    private function insumo(array $datos = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'Filamento PLA ' . uniqid(), 'unit' => 'kg',
            'stock' => 0, 'is_active' => true,
        ], $datos));
    }

    // ------------------------------------------------------------- el carrito

    public function test_el_codigo_es_consecutivo_por_ano(): void
    {
        $u = $this->persona();

        $this->assertSame('COM-' . now()->year . '-0001', $this->compras()->abrirCarrito($u)->code);
        $this->assertSame('COM-' . now()->year . '-0002', $this->compras()->abrirCarrito($u)->code);
    }

    public function test_una_linea_hereda_la_unidad_y_el_ultimo_costo_del_insumo(): void
    {
        $insumo = $this->insumo(['unit' => 'kg', 'last_cost' => 95_000]);
        $carrito = $this->compras()->abrirCarrito($this->persona());

        // Pedir filamento no debería obligar a buscar la factura anterior.
        $linea = $this->compras()->agregar($carrito, 'Filamento PLA negro', 4, insumo: $insumo);

        $this->assertSame('kg', $linea->unit);
        $this->assertSame(95_000.0, (float) $linea->unit_price);
        $this->assertSame(380_000.0, $linea->total());
    }

    public function test_el_total_incluye_el_impuesto(): void
    {
        $carrito = $this->compras()->abrirCarrito($this->persona());
        $this->compras()->agregar($carrito, 'Resina', 2, 100_000);

        $carrito->load('items');

        // Compras trabaja con el valor con IVA: mostrar el subtotal a secas hace
        // creer que el presupuesto alcanza para más de lo que alcanza.
        $this->assertSame(200_000, $carrito->subtotal());
        $this->assertSame(238_000, $carrito->totalEstimado());
    }

    public function test_no_se_envia_un_carrito_vacio(): void
    {
        $this->expectException(PurchasingException::class);
        $this->compras()->enviar($this->compras()->abrirCarrito($this->persona()));
    }

    public function test_una_solicitud_aprobada_ya_no_admite_cambios(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto();
        $carrito = $this->compras()->abrirCarrito($u, $p);
        $this->compras()->agregar($carrito, 'Acrílico', 10, 30_000);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->refresh(), $u);

        $this->expectException(PurchasingException::class);
        $this->compras()->agregar($carrito->refresh(), 'Un extra colado', 1, 5_000);
    }

    public function test_el_carrito_se_puede_llenar_con_lo_que_esta_bajo_minimos(): void
    {
        $this->insumo(['stock' => 1, 'reorder_point' => 3, 'last_cost' => 90_000]);
        $this->insumo(['stock' => 10, 'reorder_point' => 3]);   // este sobra
        $this->insumo(['stock' => 0, 'reorder_point' => null]); // sin mínimo, no se pide solo

        $carrito = $this->compras()->abrirCarrito($this->persona());

        $this->assertSame(1, $this->compras()->llenarConLoQueFalta($carrito));
        $this->assertSame(5.0, (float) $carrito->items()->first()->quantity, 'repone y deja colchón');
    }

    // --------------------------------------------------------- el presupuesto

    public function test_aprobar_compromete_presupuesto_sin_ejecutarlo(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto(1_000_000);
        $carrito = $this->compras()->abrirCarrito($u, $p);
        $this->compras()->agregar($carrito, 'Resina', 1, 100_000);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->refresh(), $u);

        $this->assertSame(119_000, $p->comprometido());
        $this->assertSame(0, $p->ejecutado());
        $this->assertSame(881_000, $p->disponible());
    }

    public function test_no_se_aprueba_por_encima_del_disponible(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto(100_000);
        $carrito = $this->compras()->abrirCarrito($u, $p);
        $this->compras()->agregar($carrito, 'Impresora', 1, 5_000_000);
        $this->compras()->enviar($carrito);

        // Aprobar de más no es un detalle contable: es una compra que después
        // no se puede pagar.
        $this->expectException(PurchasingException::class);
        $this->compras()->aprobar($carrito->refresh(), $u);
    }

    public function test_una_solicitud_rechazada_libera_el_presupuesto(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto(1_000_000);
        $carrito = $this->compras()->abrirCarrito($u, $p);
        $this->compras()->agregar($carrito, 'Resina', 1, 100_000);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->refresh(), $u);
        $this->compras()->rechazar($carrito->refresh(), $u, 'Se consigue prestada');

        $this->assertSame(1_000_000, $p->disponible());
    }

    // ------------------------------------------------------------ recepción

    public function test_recibir_mete_el_insumo_al_inventario(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto();
        $insumo = $this->insumo(['stock' => 2]);

        $carrito = $this->compras()->abrirCarrito($u, $p);
        $linea = $this->compras()->agregar($carrito, 'Filamento PLA', 4, 90_000, $insumo);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->refresh(), $u);

        $cerrada = $this->compras()->recibir($carrito->refresh(), [$linea->id => 4], $u);

        $this->assertSame('recibida', $cerrada->status);
        $this->assertSame(6.0, (float) $insumo->fresh()->stock);
        $this->assertSame(90_000, $insumo->fresh()->last_cost, 'queda el costo para la próxima compra');
    }

    public function test_una_recepcion_parcial_deja_la_solicitud_abierta(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto();
        $insumo = $this->insumo();

        $carrito = $this->compras()->abrirCarrito($u, $p);
        $linea = $this->compras()->agregar($carrito, 'Filamento PLA', 10, 90_000, $insumo);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->refresh(), $u);

        $parcial = $this->compras()->recibir($carrito->refresh(), [$linea->id => 6], $u);

        $this->assertSame('recibida_parcial', $parcial->status);
        $this->assertSame(6.0, (float) $insumo->fresh()->stock);
        $this->assertSame(4.0, $linea->fresh()->pendiente());

        // Lo recibido ejecuta presupuesto; lo que falta sigue comprometido.
        $this->assertSame(642_600, $p->ejecutado());
        $this->assertSame(428_400, $p->comprometido());

        $completa = $this->compras()->recibir($parcial, [$linea->id => 4], $u);

        $this->assertSame('recibida', $completa->status);
        $this->assertSame(10.0, (float) $insumo->fresh()->stock);
        $this->assertSame(0, $p->comprometido());
    }

    public function test_no_se_puede_recibir_mas_de_lo_pedido(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto();
        $insumo = $this->insumo();

        $carrito = $this->compras()->abrirCarrito($u, $p);
        $linea = $this->compras()->agregar($carrito, 'Filamento PLA', 2, 90_000, $insumo);
        $this->compras()->enviar($carrito);
        $this->compras()->aprobar($carrito->refresh(), $u);

        try {
            $this->compras()->recibir($carrito->refresh(), [$linea->id => 5], $u);
            $this->fail('debió rechazar la recepción');
        } catch (PurchasingException) {
            // Y sobre todo: no debe haber entrado nada al inventario.
            $this->assertSame(0.0, (float) $insumo->fresh()->stock);
        }
    }

    // ------------------------------------------------------------ existencias

    public function test_la_existencia_solo_se_mueve_con_movimientos(): void
    {
        $insumo = $this->insumo(['stock' => 0]);
        $stock = app(StockService::class);

        $stock->entrada($insumo, 10, 'Compra inicial');
        $stock->salida($insumo, 3, 'Consumo en un curso');

        $this->assertSame(7.0, (float) $insumo->fresh()->stock);
        $this->assertSame(2, $insumo->movements()->count());
        $this->assertSame(7.0, (float) $insumo->movements()->latest('id')->first()->balance_after);
    }

    public function test_no_se_puede_sacar_mas_de_lo_que_hay(): void
    {
        $insumo = $this->insumo(['stock' => 2]);

        $this->expectException(StockException::class);
        app(StockService::class)->salida($insumo, 5);
    }

    public function test_el_ajuste_registra_la_diferencia_no_el_resultado(): void
    {
        $insumo = $this->insumo(['stock' => 10]);

        $mov = app(StockService::class)->ajustar($insumo, 8.5, 'Conteo físico de agosto');

        $this->assertSame(-1.5, (float) $mov->quantity);
        $this->assertSame(8.5, (float) $insumo->fresh()->stock);
        $this->assertSame('ajuste', $mov->kind);
    }

    public function test_un_conteo_que_coincide_no_ensucia_el_historico(): void
    {
        $insumo = $this->insumo(['stock' => 10]);

        $this->assertNull(app(StockService::class)->ajustar($insumo, 10, 'Conteo'));
        $this->assertSame(0, $insumo->movements()->count());
    }

    public function test_avisa_cuando_un_insumo_esta_bajo_minimos(): void
    {
        $this->assertTrue($this->insumo(['stock' => 2, 'reorder_point' => 3])->bajoMinimos());
        $this->assertFalse($this->insumo(['stock' => 5, 'reorder_point' => 3])->bajoMinimos());
        $this->assertFalse($this->insumo(['stock' => 0])->bajoMinimos(), 'sin mínimo no hay alarma');
    }

    public function test_el_estado_de_la_solicitud_recorre_el_camino_completo(): void
    {
        $u = $this->persona();
        $p = $this->presupuesto();
        $carrito = $this->compras()->abrirCarrito($u, $p);
        $linea = $this->compras()->agregar($carrito, 'Acrílico', 5, 30_000);

        $this->assertSame('borrador', $carrito->status);
        $this->assertSame('enviada', $this->compras()->enviar($carrito)->status);
        $this->assertSame('aprobada', $this->compras()->aprobar($carrito->refresh(), $u)->status);
        $this->assertSame('en_compra', $this->compras()->marcarEnCompra($carrito->refresh())->status);
        $this->assertSame('recibida', $this->compras()->recibir($carrito->refresh(), [$linea->id => 5], $u)->status);

        $this->assertNotNull(PurchaseRequest::find($carrito->id)->closed_at);
    }
}
