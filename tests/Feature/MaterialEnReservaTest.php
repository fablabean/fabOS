<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\RateCard;
use App\Models\ReservationSupply;
use App\Models\RiskFamily;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Material consumido en una reserva (§12, §7).
 *
 * Cierra el círculo entre la existencia del insumo, lo que la persona paga y el
 * costo real de la sesión: hasta ahora esas tres cosas vivían separadas.
 */
class MaterialEnReservaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante',
            'can_reserve' => true, 'rate_factor' => 1,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        $equipo = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Impresora ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'Tarifa', 'basis' => 'tiempo', 'unit' => 'hora',
            'rateable_type' => Asset::class, 'rateable_id' => $equipo->id,
            'price_minor' => 2000, 'rounding_minutes' => 15,
        ]);

        return $equipo;
    }

    private function insumo(Asset $equipo, array $datos = []): Supply
    {
        return Supply::create(array_merge([
            'area_id' => $equipo->area_id,
            'name' => 'Filamento PLA', 'unit' => 'g',
            'stock' => 1000, 'last_cost' => 90, 'is_active' => true,
        ], $datos));
    }

    /** Reserva, llega, trabaja los minutos indicados y cierra declarando material. */
    private function sesion(User $u, Asset $equipo, int $minutos, array $materiales = [])
    {
        Certifab::firstOrCreate(
            ['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id],
            ['level' => 'byte'],
        );

        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHours(3));

        $asistencia = app(AttendanceService::class);
        $asistencia->checkIn($reserva->refresh());
        $this->travel($minutos)->minutes();
        $cerrada = $asistencia->checkOut($reserva->refresh(), $materiales);
        $this->travelBack();

        return $cerrada;
    }

    public function test_declarar_material_lo_saca_del_inventario(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $insumo = $this->insumo($equipo, ['stock' => 1000]);

        $this->sesion($u, $equipo, 60, [$insumo->id => 120]);

        $this->assertSame(880.0, (float) $insumo->fresh()->stock);
        $this->assertSame(1, ReservationSupply::count());
    }

    public function test_el_material_se_suma_al_costo_de_la_sesion(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        // 90 pesos el gramo + 30% de margen, a 1.000 pesos por FabCoin = 0,12 FBC/g.
        $insumo = $this->insumo($equipo, ['last_cost' => 90]);

        $cerrada = $this->sesion($u, $equipo, 60, [$insumo->id => 100]);

        // 20,00 de máquina + 12,00 de filamento.
        $this->assertSame(3200, $cerrada->actual_cost_minor);
    }

    public function test_sin_material_declarado_solo_se_cobra_el_tiempo(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $insumo = $this->insumo($equipo);

        $cerrada = $this->sesion($u, $equipo, 60);

        $this->assertSame(2000, $cerrada->actual_cost_minor);
        $this->assertSame(1000.0, (float) $insumo->fresh()->stock, 'el inventario no se mueve solo');
    }

    public function test_el_precio_del_material_queda_congelado(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $insumo = $this->insumo($equipo, ['last_cost' => 90]);

        $this->sesion($u, $equipo, 60, [$insumo->id => 100]);

        // Que suba el filamento mañana no reescribe lo que costó ayer.
        $insumo->update(['last_cost' => 500]);

        $this->assertSame(12, ReservationSupply::first()->unit_price_minor);
    }

    public function test_no_se_puede_declarar_mas_material_del_que_hay(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $insumo = $this->insumo($equipo, ['stock' => 50]);

        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);
        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHour());
        app(AttendanceService::class)->checkIn($reserva->refresh());

        try {
            app(AttendanceService::class)->checkOut($reserva->refresh(), [$insumo->id => 200]);
            $this->fail('debió rechazar el cierre');
        } catch (BookingException) {
            // Se descubre con la persona todavía delante del equipo, y la
            // reserva sigue abierta para corregir la cantidad.
            $this->assertSame('en_curso', $reserva->fresh()->status);
            $this->assertSame(50.0, (float) $insumo->fresh()->stock);
        }
    }

    public function test_con_el_cobro_activo_el_material_sale_del_saldo(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $u = $this->persona();
        app(ChargeService::class)->dotar($u, 50_000, '2026-08');
        $equipo = $this->equipo();
        $insumo = $this->insumo($equipo, ['last_cost' => 90]);

        $this->sesion($u, $equipo, 60, [$insumo->id => 100]);

        // 500,00 de dotación − 20,00 de máquina − 12,00 de filamento.
        $this->assertSame(46_800, app(LedgerService::class)->saldoDe($u));
    }

    public function test_la_pantalla_del_equipo_ofrece_declarar_material(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $this->insumo($equipo, ['name' => 'Filamento PLA negro']);

        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);
        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHour());
        app(AttendanceService::class)->checkIn($reserva->refresh());

        $this->actingAs($u)
            ->get(route('escaneo.equipo', $equipo->qr_token))
            ->assertOk()
            ->assertSee('¿Usaste material?')
            ->assertSee('Filamento PLA negro');
    }

    public function test_se_declara_material_cerrando_desde_el_equipo(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $insumo = $this->insumo($equipo, ['stock' => 1000]);

        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);
        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHour());
        app(AttendanceService::class)->checkIn($reserva->refresh());

        // Con coma decimal, como lo escribiría alguien aquí.
        $this->actingAs($u)
            ->post(route('escaneo.checkout', $reserva), ['material' => [$insumo->id => '45,5']])
            ->assertRedirect(route('reservas.index'));

        $this->assertSame(954.5, (float) $insumo->fresh()->stock);
        $this->assertSame('completada', $reserva->fresh()->status);
    }
}
