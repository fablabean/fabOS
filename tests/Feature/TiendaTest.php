<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\RateCard;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Money\PricingService;
use App\Services\Shop\ShopException;
use App\Services\Shop\ShopService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La tienda: insumos del inventario y servicios especiales (§14). */
class TiendaTest extends TestCase
{
    use RefreshDatabase;

    private function tienda(): ShopService
    {
        return app(ShopService::class);
    }

    private function conCobros(): ShopService
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        return $this->tienda();
    }

    private function persona(): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function insumo(array $datos = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'Filamento PLA ' . uniqid(), 'unit' => 'kg',
            'stock' => 10, 'last_cost' => 90_000, 'is_active' => true,
        ], $datos));
    }

    // --------------------------------------------------------------- precios

    public function test_el_precio_sale_del_costo_cuando_no_hay_tarifa(): void
    {
        // 90.000 pesos + 30% de margen = 117.000, a 1.000 pesos por FabCoin.
        $insumo = $this->insumo(['last_cost' => 90_000]);

        $this->assertSame(11_700, app(PricingService::class)->precioDe($insumo));
        $this->assertTrue(app(PricingService::class)->esDerivado($insumo));
    }

    public function test_una_tarifa_propia_manda_sobre_el_calculo(): void
    {
        $insumo = $this->insumo(['last_cost' => 90_000]);

        RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'Filamento', 'basis' => 'unidad', 'unit' => 'kg',
            'rateable_type' => Supply::class, 'rateable_id' => $insumo->id,
            'price_minor' => 8_000,
        ]);

        // Una decisión explícita siempre gana sobre un cálculo.
        $this->assertSame(8_000, app(PricingService::class)->precioDe($insumo));
        $this->assertFalse(app(PricingService::class)->esDerivado($insumo));
    }

    public function test_un_insumo_sin_costo_ni_tarifa_no_se_puede_vender(): void
    {
        $venta = $this->tienda()->abrirVenta($this->persona());

        $this->expectException(ShopException::class);
        $this->tienda()->agregarInsumo($venta, $this->insumo(['last_cost' => null]), 1);
    }

    // ---------------------------------------------------------------- cobrar

    public function test_cobrar_descuenta_saldo_y_existencia(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo(['stock' => 10]);

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 2);          // 2 × 117,00
        $pagada = $tienda->cobrar($venta->refresh());

        $this->assertSame('pagada', $pagada->status);
        $this->assertSame(23_400, $pagada->total_minor);
        $this->assertSame(8.0, (float) $insumo->fresh()->stock);
        $this->assertSame(26_600, app(LedgerService::class)->saldoDe($cliente));
        $this->assertSame(23_400, app(LedgerService::class)->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor());
    }

    public function test_sin_saldo_no_se_cobra_ni_se_mueve_el_inventario(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 1_000, '2026-08');
        $insumo = $this->insumo(['stock' => 10]);

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 2);

        try {
            $tienda->cobrar($venta->refresh());
            $this->fail('debió rechazar el cobro');
        } catch (ShopException) {
            // Lo que importa: la transacción deshizo también la salida de stock.
            $this->assertSame(10.0, (float) $insumo->fresh()->stock);
            $this->assertSame('abierta', $venta->fresh()->status);
        }
    }

    public function test_no_se_vende_mas_de_lo_que_hay(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 500_000, '2026-08');
        $insumo = $this->insumo(['stock' => 1]);

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 3);

        $this->expectException(ShopException::class);
        $tienda->cobrar($venta->refresh());
    }

    public function test_un_servicio_especial_no_toca_el_inventario(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarServicio($venta, 'Impresión hecha por el equipo', 1, 15_000);
        $pagada = $tienda->cobrar($venta->refresh());

        $this->assertSame(15_000, $pagada->total_minor);
        $this->assertSame(0, \App\Models\SupplyMovement::count());
    }

    public function test_con_el_cobro_apagado_la_venta_igual_mueve_el_inventario(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, false, 'finanzas');

        $cliente = $this->persona();
        $insumo = $this->insumo(['stock' => 10]);

        $venta = $this->tienda()->abrirVenta($cliente);
        $this->tienda()->agregarInsumo($venta, $insumo, 2);
        $pagada = $this->tienda()->cobrar($venta->refresh());

        // El mostrador se puede ensayar completo antes de encender el dinero.
        $this->assertSame('pagada', $pagada->status);
        $this->assertSame(8.0, (float) $insumo->fresh()->stock);
        $this->assertSame(0, app(LedgerService::class)->saldoDe($cliente));
    }

    public function test_una_venta_pagada_ya_no_admite_lineas(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo();

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 1);
        $tienda->cobrar($venta->refresh());

        $this->expectException(ShopException::class);
        $tienda->agregarInsumo($venta->refresh(), $insumo, 1);
    }

    public function test_el_precio_queda_congelado_al_cobrar(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo(['last_cost' => 90_000]);

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 1);
        $pagada = $tienda->cobrar($venta->refresh());

        // Subir el costo mañana no debe reescribir lo que se cobró ayer.
        $insumo->update(['last_cost' => 200_000]);

        $this->assertSame(11_700, $pagada->fresh()->total_minor);
        $this->assertSame(117.0, $pagada->fresh()->total());
    }

    // ---------------------------------------------------------------- anular

    public function test_anular_devuelve_saldo_y_mercancia(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo(['stock' => 10]);

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 2);
        $tienda->cobrar($venta->refresh());

        $anulada = $tienda->anular($venta->refresh(), 'El rollo venía dañado');

        $this->assertSame('anulada', $anulada->status);
        $this->assertSame(50_000, app(LedgerService::class)->saldoDe($cliente));
        $this->assertSame(10.0, (float) $insumo->fresh()->stock);

        // No se borra nada: el histórico cuenta que hubo venta y que se deshizo.
        $this->assertSame(2, $insumo->movements()->count());
        $this->assertSame(0, app(LedgerService::class)->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor());
    }

    public function test_anular_una_venta_abierta_no_mueve_nada(): void
    {
        $tienda = $this->conCobros();
        $insumo = $this->insumo(['stock' => 10]);

        $venta = $tienda->abrirVenta($this->persona());
        $tienda->agregarInsumo($venta, $insumo, 2);
        $tienda->anular($venta->refresh(), 'Se arrepintió antes de pagar');

        $this->assertSame(10.0, (float) $insumo->fresh()->stock);
        $this->assertSame(0, \App\Models\LedgerTransaction::count());
    }

    public function test_el_libro_queda_cuadrado_tras_vender_y_anular(): void
    {
        $tienda = $this->conCobros();
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 50_000, '2026-08');
        $insumo = $this->insumo();

        $venta = $tienda->abrirVenta($cliente);
        $tienda->agregarInsumo($venta, $insumo, 1);
        $tienda->cobrar($venta->refresh());
        $tienda->anular($venta->refresh(), 'Devolución');

        $this->assertSame(0, LedgerAccount::all()->sum(fn (LedgerAccount $c) => $c->saldoMenor()));
        $this->assertTrue(app(LedgerService::class)->verificarCadena()['intacta']);
    }

    // -------------------------------------------------------------- catálogo

    public function test_el_catalogo_solo_muestra_lo_que_se_puede_vender(): void
    {
        $this->insumo(['name' => 'Con existencia', 'stock' => 5]);
        $this->insumo(['name' => 'Agotado', 'stock' => 0]);
        $this->insumo(['name' => 'Sin precio', 'stock' => 5, 'last_cost' => null]);
        $this->insumo(['name' => 'Inactivo', 'stock' => 5, 'is_active' => false]);

        $catalogo = $this->tienda()->catalogo();

        $this->assertCount(1, $catalogo);
        $this->assertSame('Con existencia', $catalogo->first()['insumo']->name);
        $this->assertTrue($catalogo->first()['derivado'], 'avisa que el precio es calculado');
    }

    public function test_el_codigo_de_venta_es_consecutivo(): void
    {
        $u = $this->persona();

        $this->assertSame('VTA-' . now()->year . '-0001', $this->tienda()->abrirVenta($u)->code);
        $this->assertSame('VTA-' . now()->year . '-0002', $this->tienda()->abrirVenta($u)->code);
    }

    public function test_no_se_cobra_una_venta_vacia(): void
    {
        $this->expectException(ShopException::class);
        $this->conCobros()->cobrar(Sale::find($this->tienda()->abrirVenta($this->persona())->id));
    }
}
