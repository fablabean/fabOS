<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\NotificationLog;
use App\Models\ProductionJob;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Shop\ProductionService;
use App\Services\Shop\ShopException;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** La cola de producción: encargos de la tienda (§14). */
class EncargosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function produccion(): ProductionService
    {
        return app(ProductionService::class);
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'profesor'],
            ['name' => 'Profesor', 'can_reserve' => true, 'rate_factor' => 1],
        );

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function encargo(?User $quien = null): ProductionJob
    {
        return $this->produccion()->pedir($quien ?? $this->persona(), [
            'title'       => '40 piezas para la clase de diseño',
            'description' => 'Corte en acrílico de 3 mm, archivo adjunto.',
            'quantity'    => 40,
        ]);
    }

    /** Encargo cotizado, aceptado, producido y listo. */
    private function hastaListo(User $cliente, int $valor = 15_000): ProductionJob
    {
        $encargo = $this->encargo($cliente);
        $this->produccion()->cotizar($encargo, $valor, 120, now()->addDays(3)->toDateString());
        $this->produccion()->aceptar($encargo->refresh());
        $this->produccion()->iniciar($encargo->refresh(), $this->persona());

        return $this->produccion()->terminar($encargo->refresh());
    }

    // --------------------------------------------------------------- pedir

    public function test_cualquiera_pide_un_trabajo_sin_saber_operar_la_maquina(): void
    {
        $encargo = $this->encargo();

        // Un profesor que necesita cuarenta piezas no se va a certificar en
        // corte láser: entrega el archivo y recoge las piezas.
        $this->assertSame('solicitado', $encargo->status);
        $this->assertSame('ENC-' . now()->year . '-0001', $encargo->code);
        $this->assertSame(0, (int) $encargo->fresh()->quoted_total_minor, 'todavía no compromete nada');
    }

    // ------------------------------------------------------------ cotizar

    public function test_no_se_produce_sin_cotizar_y_aceptar(): void
    {
        $encargo = $this->encargo();

        try {
            $this->produccion()->iniciar($encargo, $this->persona());
            $this->fail('no debería entrar a producción sin aceptar');
        } catch (ShopException) {
            $this->assertSame('solicitado', $encargo->fresh()->status);
        }
    }

    public function test_cotizar_avisa_a_quien_pidio(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $encargo = $this->encargo();

        $cotizado = $this->produccion()->cotizar($encargo, 15_000, 120, now()->addDays(3)->toDateString(), 'Incluye el acrílico.');

        $this->assertSame('cotizado', $cotizado->status);
        $this->assertSame(150.0, $cotizado->total());

        $aviso = NotificationLog::where('key', 'encargo.cotizado')->first();
        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('150,00', $aviso->body);
        $this->assertStringContainsString('Incluye el acrílico', $aviso->body);
    }

    public function test_una_cotizacion_sin_valor_no_se_guarda(): void
    {
        $this->expectException(ShopException::class);
        $this->produccion()->cotizar($this->encargo(), 0);
    }

    public function test_aceptar_lo_pone_en_la_cola(): void
    {
        $encargo = $this->encargo();
        $this->produccion()->cotizar($encargo, 15_000);

        $this->assertSame('en_cola', $this->produccion()->aceptar($encargo->refresh())->status);
        $this->assertNotNull($encargo->fresh()->accepted_at);
    }

    // ------------------------------------------------------------ producir

    public function test_el_camino_completo_hasta_listo(): void
    {
        $encargo = $this->hastaListo($this->persona());

        $this->assertSame('listo', $encargo->status);
        $this->assertNotNull($encargo->assigned_to);
        $this->assertNotNull($encargo->started_at);
        $this->assertNotNull($encargo->finished_at);
    }

    public function test_terminar_avisa_que_esta_listo(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $this->hastaListo($this->persona());

        $this->assertSame('enviado', NotificationLog::where('key', 'encargo.listo')->first()?->status);
    }

    // ------------------------------------------------------------ entregar

    public function test_entregar_genera_la_venta_y_cobra(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 100_000, '2026-08');

        $encargo = $this->hastaListo($cliente, 15_000);
        $entregado = $this->produccion()->entregar($encargo);

        $this->assertSame('entregado', $entregado->status);
        $this->assertNotNull($entregado->sale_id);
        $this->assertSame('pagada', Sale::find($entregado->sale_id)->status);

        // 1.000,00 de dotación menos 150,00 del encargo.
        $this->assertSame(85_000, app(LedgerService::class)->saldoDe($cliente));
        $this->assertSame(15_000, app(LedgerService::class)->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor());
    }

    public function test_el_material_declarado_sale_del_inventario(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 100_000, '2026-08');

        $insumo = Supply::create([
            'name' => 'Acrílico 3 mm', 'unit' => 'hoja',
            'stock' => 20, 'last_cost' => 25_000, 'is_active' => true,
        ]);

        $encargo = $this->hastaListo($cliente, 15_000);
        $this->produccion()->entregar($encargo, [$insumo->id => 4]);

        // El material sale del inventario pero NO se vuelve a cobrar: la
        // cotización ya lo incluía, y cobrarlo otra vez sería cobrar dos veces
        // el mismo acrílico.
        $this->assertSame(16.0, (float) $insumo->fresh()->stock);
        $this->assertSame(1, Sale::first()->items()->count(), 'solo la línea del servicio');
        $this->assertSame(15_000, (int) Sale::first()->total_minor);
    }

    public function test_se_cobra_lo_cotizado_y_no_un_recalculo(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 100_000, '2026-08');

        $encargo = $this->hastaListo($cliente, 15_000);
        $entregado = $this->produccion()->entregar($encargo);

        // Cambiar el precio al entregar sería cambiar el trato.
        $servicio = Sale::find($entregado->sale_id)->items()->where('unit', 'servicio')->first();

        $this->assertSame(15_000, $servicio->unit_price_minor);
        $this->assertStringContainsString($encargo->code, $servicio->description);
    }

    public function test_no_se_entrega_lo_que_no_esta_listo(): void
    {
        $encargo = $this->encargo();
        $this->produccion()->cotizar($encargo, 15_000);

        $this->expectException(ShopException::class);
        $this->produccion()->entregar($encargo->refresh());
    }

    public function test_sin_saldo_la_entrega_falla_sin_dejar_a_medias(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $cliente = $this->persona();     // sin dotación
        $insumo = Supply::create([
            'name' => 'Acrílico', 'unit' => 'hoja', 'stock' => 20,
            'last_cost' => 25_000, 'is_active' => true,
        ]);

        $encargo = $this->hastaListo($cliente, 15_000);

        try {
            $this->produccion()->entregar($encargo, [$insumo->id => 4]);
            $this->fail('debió rechazar la entrega');
        } catch (ShopException) {
            // Ni el inventario ni el encargo quedan tocados.
            $this->assertSame(20.0, (float) $insumo->fresh()->stock);
            $this->assertSame('listo', $encargo->fresh()->status);
            $this->assertSame(0, Sale::where('status', 'pagada')->count());
        }
    }

    // ---------------------------------------------------------------- cola

    public function test_la_cola_pone_primero_lo_vencido_y_lo_urgente(): void
    {
        $cliente = $this->persona();

        $normal = $this->encargo($cliente);
        $this->produccion()->cotizar($normal, 1000, null, now()->addDays(10)->toDateString());
        $this->produccion()->aceptar($normal->refresh());

        $urgente = $this->encargo($cliente);
        $this->produccion()->cotizar($urgente, 1000, null, now()->addDays(5)->toDateString());
        $this->produccion()->aceptar($urgente->refresh());
        $urgente->update(['priority' => 'alta']);

        $vencido = $this->encargo($cliente);
        $this->produccion()->cotizar($vencido, 1000, null, now()->subDay()->toDateString());
        $this->produccion()->aceptar($vencido->refresh());

        $cola = $this->produccion()->cola();

        // Ordenar por fecha de pedido a secas dejaría lo prometido para ayer
        // detrás de algo que nadie espera.
        $this->assertSame($vencido->code, $cola[0]->code);
        $this->assertSame($urgente->code, $cola[1]->code);
        $this->assertSame($normal->code, $cola[2]->code);
    }

    public function test_la_cola_solo_muestra_lo_que_ocupa_al_equipo(): void
    {
        $cliente = $this->persona();

        $this->encargo($cliente);                                    // solicitado
        $cotizado = $this->encargo($cliente);
        $this->produccion()->cotizar($cotizado, 1000);               // cotizado, sin aceptar
        $entregado = $this->hastaListo($cliente, 1000);
        $entregado->update(['status' => 'entregado']);

        $enCola = $this->encargo($cliente);
        $this->produccion()->cotizar($enCola, 1000);
        $this->produccion()->aceptar($enCola->refresh());

        $cola = $this->produccion()->cola();

        $this->assertCount(1, $cola);
        $this->assertSame($enCola->code, $cola->first()->code);
    }

    public function test_rechazar_y_cancelar_dejan_el_motivo(): void
    {
        $rechazado = $this->produccion()->rechazar($this->encargo(), 'No tenemos el material');
        $cancelado = $this->produccion()->cancelar($this->encargo(), 'El cliente desistió');

        $this->assertSame('rechazado', $rechazado->status);
        $this->assertSame('No tenemos el material', $rechazado->rejection_reason);
        $this->assertSame('cancelado', $cancelado->status);
    }

    public function test_un_encargo_entregado_ya_no_se_cancela(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 100_000, '2026-08');

        $entregado = $this->produccion()->entregar($this->hastaListo($cliente, 1000));

        $this->expectException(ShopException::class);
        $this->produccion()->cancelar($entregado);
    }
}
