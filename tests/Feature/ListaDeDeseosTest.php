<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Supply;
use App\Models\User;
use App\Models\Wish;
use App\Services\Purchasing\ListaDeDeseos;
use App\Services\Purchasing\PurchasingException;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * La lista de deseos: lo que hace falta y todavía no se ha pedido (§13).
 *
 * Lo que se defiende aquí es que el estado del deseo no miente nunca, porque no
 * se guarda: si la compra se cae por donde sea, el deseo vuelve solo a la lista.
 */
class ListaDeDeseosTest extends TestCase
{
    use RefreshDatabase;

    private function deseos(): ListaDeDeseos
    {
        return app(ListaDeDeseos::class);
    }

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

    private function area(string $nombre = 'Fabricación'): Area
    {
        return Area::create(['slug' => 'a-' . uniqid(), 'name' => $nombre]);
    }

    private function insumo(array $datos = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'Filamento PLA ' . uniqid(), 'unit' => 'kg',
            'stock' => 0, 'is_active' => true,
        ], $datos));
    }

    private function deseo(array $datos = []): Wish
    {
        return Wish::create(array_merge([
            'target_year' => 2027,
            'description' => 'Fresadora CNC',
            'quantity'    => 1,
            'unit_price'  => 12_000_000,
        ], $datos));
    }

    /** @param  array<int, Wish>  $deseos */
    private function lote(array $deseos): Collection
    {
        return collect($deseos);
    }

    // --------------------------------------------------------------- la cuenta

    public function test_el_estimado_es_cantidad_por_precio_en_pesos(): void
    {
        $deseo = $this->deseo(['quantity' => 6, 'unit_price' => 180_000]);

        $this->assertSame(1_080_000, $deseo->estimado());
    }

    public function test_un_deseo_sin_precio_no_se_cuenta_como_cero(): void
    {
        // Nulo y no cero: un cero suma bien y miente.
        $this->assertNull($this->deseo(['unit_price' => null])->estimado());
    }

    public function test_un_deseo_recien_apuntado_esta_abierto(): void
    {
        $this->assertSame('abierto', $this->deseo()->estado());
    }

    // ----------------------------------------------------------- pasar a compra

    public function test_pasar_a_compra_arma_un_carrito_en_borrador_con_una_linea_por_deseo(): void
    {
        $quien = $this->persona();
        $a = $this->deseo(['description' => 'Fresadora CNC', 'unit_price' => 12_000_000]);
        $b = $this->deseo(['description' => 'Resina flexible', 'quantity' => 6, 'unit_price' => 180_000]);

        $carrito = $this->deseos()->pasarACompra($this->lote([$a, $b]), $quien);

        $this->assertSame('borrador', $carrito->status);
        $this->assertNull($carrito->budget_id, 'un carrito recién nacido no compromete presupuesto');
        $this->assertSame(2, $carrito->items()->count());
        $this->assertSame('Fresadora CNC', $carrito->items()->first()->description);
        $this->assertStringContainsString('2027', $carrito->justification);
    }

    public function test_el_deseo_queda_enlazado_a_su_linea_y_dice_en_que_solicitud_termino(): void
    {
        $deseo = $this->deseo();

        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());

        $deseo->refresh();
        $this->assertSame($carrito->items()->first()->id, $deseo->purchase_request_item_id);
        $this->assertSame('en_solicitud', $deseo->estado());
        $this->assertSame($carrito->code, $deseo->solicitud()->code);
    }

    public function test_un_deseo_que_repone_un_insumo_hereda_su_unidad_y_su_ultimo_costo(): void
    {
        $insumo = $this->insumo(['unit' => 'kg', 'last_cost' => 95_000]);
        // Sin cotizar: el catálogo sabe lo que costó la última vez.
        $deseo = $this->deseo(['supply_id' => $insumo->id, 'quantity' => 4, 'unit_price' => null]);

        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());
        $linea = $carrito->items()->first();

        $this->assertSame($insumo->id, $linea->supply_id);
        $this->assertSame('kg', $linea->unit);
        $this->assertSame(95_000.0, (float) $linea->unit_price);
    }

    public function test_el_carrito_hereda_el_area_solo_si_todos_los_deseos_la_comparten(): void
    {
        $area = $this->area();
        $otra = $this->area('Electrónica');

        $juntos = $this->deseos()->pasarACompra($this->lote([
            $this->deseo(['area_id' => $area->id]),
            $this->deseo(['area_id' => $area->id]),
        ]), $this->persona());

        $mezclados = $this->deseos()->pasarACompra($this->lote([
            $this->deseo(['area_id' => $area->id]),
            $this->deseo(['area_id' => $otra->id]),
        ]), $this->persona());

        $this->assertSame($area->id, $juntos->area_id);
        $this->assertNull($mezclados->area_id, 'con áreas mezcladas no se inventa una');
    }

    public function test_no_se_pasa_dos_veces_el_mismo_deseo(): void
    {
        $quien = $this->persona();
        $pedido = $this->deseo();
        $nuevo = $this->deseo(['description' => 'Torno']);

        $this->deseos()->pasarACompra($this->lote([$pedido]), $quien);
        $segundo = $this->deseos()->pasarACompra($this->lote([$pedido->refresh(), $nuevo]), $quien);

        // El que ya iba se salta en silencio; solo viaja el nuevo.
        $this->assertSame(1, $segundo->items()->count());
        $this->assertSame('Torno', $segundo->items()->first()->description);
    }

    public function test_pasar_a_compra_sin_ningun_deseo_en_la_lista_avisa(): void
    {
        $deseo = $this->deseo(['discarded_at' => now(), 'discarded_reason' => 'No cabe']);

        $this->expectException(PurchasingException::class);

        $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());
    }

    public function test_un_deseo_descartado_no_se_pasa_a_compra(): void
    {
        $quien = $this->persona();
        $descartado = $this->deseo(['discarded_at' => now(), 'discarded_reason' => 'No cabe']);
        $vivo = $this->deseo(['description' => 'Torno']);

        $carrito = $this->deseos()->pasarACompra($this->lote([$descartado, $vivo]), $quien);

        $this->assertSame(1, $carrito->items()->count());
        $this->assertNull($descartado->refresh()->purchase_request_item_id);
    }

    // ------------------------------------------------- el deseo vuelve a la lista

    public function test_si_se_cancela_la_solicitud_el_deseo_vuelve_solo_a_la_lista(): void
    {
        $deseo = $this->deseo();
        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());

        $this->compras()->cancelar($carrito, 'No hubo presupuesto');

        // Sigue apuntando a su línea —queda el rastro— pero vuelve a la lista.
        $this->assertSame('abierto', $deseo->refresh()->estado());
        $this->assertNotNull($deseo->purchase_request_item_id);
        $this->assertTrue(Wish::enEstado('abierto')->whereKey($deseo->id)->exists());
    }

    public function test_si_se_rechaza_la_solicitud_el_deseo_vuelve_solo_a_la_lista(): void
    {
        $quien = $this->persona();
        $deseo = $this->deseo();
        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $quien);

        $this->compras()->rechazar($carrito, $quien, 'No es prioritario este año');

        $this->assertSame('abierto', $deseo->refresh()->estado());
    }

    public function test_si_se_borra_la_linea_el_deseo_vuelve_solo_a_la_lista(): void
    {
        $deseo = $this->deseo();
        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());

        $carrito->items()->first()->delete();

        $this->assertNull($deseo->refresh()->purchase_request_item_id);
        $this->assertSame('abierto', $deseo->estado());
    }

    public function test_si_se_borra_la_solicitud_entera_el_deseo_vuelve_solo_a_la_lista(): void
    {
        $deseo = $this->deseo();
        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());

        $carrito->delete();

        $this->assertNull($deseo->refresh()->purchase_request_item_id);
        $this->assertSame('abierto', $deseo->estado());
    }

    public function test_cuando_la_linea_se_recibe_entera_el_deseo_queda_comprado(): void
    {
        $quien = $this->persona();
        $deseo = $this->deseo(['quantity' => 4, 'unit_price' => 100_000]);
        $carrito = $this->deseos()->pasarACompra($this->lote([$deseo]), $quien);
        $linea = $carrito->items()->first();

        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => (int) now()->year, 'amount' => 50_000_000, 'status' => 'vigente',
        ]);
        $this->compras()->aprobar($carrito, $quien, $presupuesto);

        $this->compras()->recibir($carrito, [$linea->id => 2], $quien);
        $this->assertSame('en_solicitud', $deseo->refresh()->estado(), 'a medio llegar sigue pedido');

        $this->compras()->recibir($carrito, [$linea->id => 2], $quien);
        $this->assertSame('comprado', $deseo->refresh()->estado());
    }

    public function test_pasar_un_deseo_al_ano_siguiente_es_cambiarle_el_ano(): void
    {
        $deseo = $this->deseo(['target_year' => 2027]);

        $deseo->update(['target_year' => 2028]);

        $this->assertSame(0, Wish::query()->porComprar()->delAno(2027)->count());
        $this->assertSame(1, Wish::query()->porComprar()->delAno(2028)->count());
        $this->assertSame('abierto', $deseo->refresh()->estado(), 'cambiar de año no lo mueve de estado');
    }

    // --------------------------------------------------- el estado nunca miente

    public function test_el_estado_derivado_y_los_scopes_dicen_lo_mismo(): void
    {
        $quien = $this->persona();

        // Una de cada situación posible.
        $suelto = $this->deseo(['description' => 'Suelto']);
        $descartado = $this->deseo(['description' => 'Descartado', 'discarded_at' => now(), 'discarded_reason' => 'No']);

        $pedido = $this->deseo(['description' => 'Pedido']);
        $this->deseos()->pasarACompra($this->lote([$pedido]), $quien);

        $cancelado = $this->deseo(['description' => 'Cancelado']);
        $suCarrito = $this->deseos()->pasarACompra($this->lote([$cancelado]), $quien);
        $this->compras()->cancelar($suCarrito, 'No hubo plata');

        $recibido = $this->deseo(['description' => 'Recibido', 'quantity' => 2, 'unit_price' => 50_000]);
        $carritoRecibido = $this->deseos()->pasarACompra($this->lote([$recibido]), $quien);
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => (int) now()->year, 'amount' => 50_000_000, 'status' => 'vigente',
        ]);
        $this->compras()->aprobar($carritoRecibido, $quien, $presupuesto);
        $this->compras()->recibir($carritoRecibido, [$carritoRecibido->items()->first()->id => 2], $quien);

        $huerfano = $this->deseo(['description' => 'Huérfano']);
        $suyo = $this->deseos()->pasarACompra($this->lote([$huerfano]), $quien);
        $suyo->items()->first()->delete();

        // La lectura de una fila y la consulta de la tabla son la misma regla
        // dicha dos veces. Si se separan, el badge dice una cosa y el filtro otra.
        foreach (array_keys(Wish::ESTADOS) as $estado) {
            $porFila = Wish::all()->filter(fn (Wish $d) => $d->estado() === $estado)->pluck('id')->sort()->values();
            $porConsulta = Wish::enEstado($estado)->pluck('id')->sort()->values();

            $this->assertEquals(
                $porFila->all(),
                $porConsulta->all(),
                "el estado «{$estado}» no se lee igual en la fila que en la consulta",
            );
        }

        // Y que el reparto sea el esperado, no que coincidan en el vacío.
        $this->assertSame('abierto', $suelto->refresh()->estado());
        $this->assertSame('descartado', $descartado->refresh()->estado());
        $this->assertSame('en_solicitud', $pedido->refresh()->estado());
        $this->assertSame('abierto', $cancelado->refresh()->estado());
        $this->assertSame('comprado', $recibido->refresh()->estado());
        $this->assertSame('abierto', $huerfano->refresh()->estado());
    }

    // ------------------------------------------------------ el año que viene

    public function test_el_resumen_suma_el_estimado_por_area_del_ano_destino(): void
    {
        $fabricacion = $this->area('Fabricación');
        $electronica = $this->area('Electrónica');

        $this->deseo(['area_id' => $fabricacion->id, 'unit_price' => 12_000_000]);
        $this->deseo(['area_id' => $fabricacion->id, 'quantity' => 2, 'unit_price' => 850_000]);
        $this->deseo(['area_id' => $electronica->id, 'unit_price' => 3_120_000]);
        $this->deseo(['area_id' => null, 'unit_price' => 640_000]);

        $resumen = Wish::resumenDelAno(2027);

        $this->assertSame(17_460_000, $resumen['estimado']);
        $this->assertSame(4, $resumen['cuantos']);
        // Las áreas en orden alfabético, y lo que no es de nadie en particular
        // al final: primero se reparte por área, que es como se pide la plata.
        $this->assertSame('Electrónica', $resumen['areas'][0]['area']);
        $this->assertSame(3_120_000, $resumen['areas'][0]['estimado']);
        $this->assertSame('Fabricación', $resumen['areas'][1]['area']);
        $this->assertSame(13_700_000, $resumen['areas'][1]['estimado']);
        $this->assertNull($resumen['areas'][2]['area']);
        $this->assertSame(640_000, $resumen['areas'][2]['estimado']);
    }

    public function test_el_resumen_ensena_tambien_la_cifra_con_impuesto(): void
    {
        config(['fabos.money.tax_rate' => 0.19]);
        $this->deseo(['unit_price' => 1_000_000]);

        $resumen = Wish::resumenDelAno(2027);

        // Compras trabaja con el valor con IVA; el subtotal a secas hace creer
        // que el presupuesto alcanza para más.
        $this->assertSame(1_000_000, $resumen['estimado']);
        $this->assertSame(1_190_000, $resumen['conImpuesto']);
    }

    public function test_el_resumen_dice_aparte_cuantos_deseos_no_tienen_estimado(): void
    {
        $this->deseo(['unit_price' => 1_000_000]);
        $this->deseo(['description' => 'Licencia por averiguar', 'unit_price' => null]);

        $resumen = Wish::resumenDelAno(2027);

        $this->assertSame(1_000_000, $resumen['estimado'], 'lo no cotizado no entra en la suma');
        $this->assertSame(1, $resumen['sinEstimar'], 'pero se dice, para que nadie tome el total por completo');
    }

    public function test_el_resumen_no_vuelve_a_presupuestar_lo_ya_comprado_ni_lo_descartado(): void
    {
        $quien = $this->persona();
        $this->deseo(['unit_price' => 1_000_000]);
        $this->deseo(['description' => 'Descartado', 'unit_price' => 5_000_000, 'discarded_at' => now(), 'discarded_reason' => 'No']);

        $comprado = $this->deseo(['description' => 'Comprado', 'quantity' => 1, 'unit_price' => 9_000_000]);
        $carrito = $this->deseos()->pasarACompra($this->lote([$comprado]), $quien);
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => (int) now()->year, 'amount' => 50_000_000, 'status' => 'vigente',
        ]);
        $this->compras()->aprobar($carrito, $quien, $presupuesto);
        $this->compras()->recibir($carrito, [$carrito->items()->first()->id => 1], $quien);

        $resumen = Wish::resumenDelAno(2027);

        $this->assertSame(1_000_000, $resumen['estimado']);
        $this->assertSame(1, $resumen['cuantos']);
    }

    public function test_un_deseo_en_solicitud_sigue_contando_para_el_ano(): void
    {
        $deseo = $this->deseo(['unit_price' => 1_000_000]);
        $this->deseos()->pasarACompra($this->lote([$deseo]), $this->persona());

        // Pedido no es comprado: hasta que llegue, hay que tener con qué pagarlo.
        $this->assertSame(1_000_000, Wish::resumenDelAno(2027)['estimado']);
    }

    public function test_otro_ano_no_se_mezcla(): void
    {
        $this->deseo(['target_year' => 2027, 'unit_price' => 1_000_000]);
        $this->deseo(['target_year' => 2028, 'unit_price' => 7_000_000]);

        $this->assertSame(1_000_000, Wish::resumenDelAno(2027)['estimado']);
        $this->assertSame(7_000_000, Wish::resumenDelAno(2028)['estimado']);
    }

    // ------------------------------------------------------- el presupuesto

    public function test_presupuestar_crea_el_presupuesto_en_borrador_con_el_monto_precargado(): void
    {
        $area = $this->area();
        $this->deseo(['area_id' => $area->id, 'unit_price' => 1_000_000]);

        $presupuesto = $this->deseos()->presupuestar(2027, $area, 'Deseos 2027', 1_190_000);

        $this->assertSame('borrador', $presupuesto->status, 'es una propuesta, no plata asignada');
        $this->assertSame('gasto', $presupuesto->kind);
        $this->assertSame(2027, (int) $presupuesto->year);
        $this->assertSame($area->id, $presupuesto->area_id);
        $this->assertSame(1_190_000, (int) $presupuesto->amount);
    }

    public function test_el_presupuesto_creado_dice_de_donde_salio_la_cifra(): void
    {
        $this->deseo(['unit_price' => 1_000_000]);
        $this->deseo(['description' => 'Sin cotizar', 'unit_price' => null]);

        $presupuesto = $this->deseos()->presupuestar(2027, null, 'Deseos 2027', 1_190_000);

        $this->assertStringContainsString('lista de deseos', $presupuesto->notes);
        $this->assertStringContainsString('2027', $presupuesto->notes);
        $this->assertStringContainsString('sin cotizar', $presupuesto->notes);
    }

    public function test_el_presupuesto_nace_sin_comprometer_ni_ejecutar_nada(): void
    {
        $this->deseo(['unit_price' => 1_000_000]);

        $presupuesto = $this->deseos()->presupuestar(2027, null, 'Deseos 2027', 1_190_000);

        $this->assertSame(0, $presupuesto->comprometido());
        $this->assertSame(0, $presupuesto->ejecutado());
        $this->assertSame(1_190_000, $presupuesto->disponible());
    }

    public function test_el_ano_por_defecto_es_el_que_viene(): void
    {
        $proximo = (int) now()->year + 1;
        $this->deseo(['target_year' => $proximo]);

        $this->assertSame($proximo, Wish::anoPorDefecto());
    }

    public function test_si_no_hay_deseos_del_ano_que_viene_manda_el_ultimo_que_haya(): void
    {
        // Abrir la pantalla en un año vacío teniendo cosas escritas al lado
        // parece una avería y lleva a comprobar si se borró algo.
        $this->deseo(['target_year' => 2030]);

        $this->assertSame(2030, Wish::anoPorDefecto());
    }
}
