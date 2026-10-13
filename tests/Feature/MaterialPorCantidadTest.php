<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\RateCard;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Money\MaterialDelEquipo;
use App\Services\Money\QuoteService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El material del equipo se cobra por cantidad (§12).
 *
 * Lo que se defiende: que 60 g sean 8 FabCoins y lo demás salga en
 * proporción y al FabCoin siguiente; que se retenga eso y no una garantía
 * fija; que sin saldo no se reserve; y que al cerrar se cobre lo que se gastó
 * de verdad.
 */
class MaterialPorCantidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00', config('fabos.lab.timezone')));
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');
    }

    private function saldo(User $u): int
    {
        return app(LedgerService::class)->saldoDe($u);
    }

    /** Habilitada en la familia, con el saldo que se diga (en unidades menores). */
    private function persona(RiskFamily $rf, int $saldo = 0): User
    {
        $cat = UserCategory::create(['slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1]);
        $u = User::create(['name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo', 'user_category_id' => $cat->id]);

        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $rf->id, 'level' => 'byte']);

        if ($saldo > 0) {
            app(ChargeService::class)->dotar($u, $saldo, '2026-09');
        }

        return $u;
    }

    /**
     * Impresión FDM como está en el laboratorio: el tiempo sale gratis por las
     * horas del certifab, la tarifa trae 5 de garantía, y el filamento va a
     * 8 FabCoins por cada 60 g.
     */
    private function impresora(bool $conMaterial = true): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create(['area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM']);

        RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'FDM', 'rateable_type' => RiskFamily::class, 'rateable_id' => $rf->id,
            'basis' => 'tiempo', 'unit' => 'hora', 'price_minor' => 400, 'deposit_minor' => 500,
            'rounding_minutes' => 15, 'included_weekly_minutes' => 480,
        ]);

        if ($conMaterial) {
            RateCard::create([
                'slug' => 'm-' . uniqid(), 'name' => 'Filamento', 'rateable_type' => RiskFamily::class, 'rateable_id' => $rf->id,
                'basis' => 'unidad', 'unit' => 'g', 'unit_quantity' => 60, 'price_minor' => 800, 'ask_on_booking' => true,
            ]);
        }

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id, 'name' => 'Creality ' . uniqid(),
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 720, 'max_minutes' => 720,
        ]);
    }

    // ------------------------------------------------------------ la cuenta

    public function test_sesenta_gramos_son_ocho_y_lo_demas_va_en_proporcion_al_fabcoin_siguiente(): void
    {
        $tarifa = MaterialDelEquipo::tarifa($this->impresora());

        $this->assertNotNull($tarifa);
        $this->assertSame('8 FBC por cada 60 g', MaterialDelEquipo::regla($tarifa));

        foreach ([60 => 800, 30 => 400, 90 => 1200, 120 => 1600, 31 => 500, 1 => 100, 7.5 => 100, 61 => 900, 0 => 0] as $gramos => $menor) {
            $this->assertSame($menor, MaterialDelEquipo::costo($tarifa, (float) $gramos), $gramos . ' g');
        }
    }

    public function test_una_tarifa_por_unidad_solo_se_pide_al_reservar_si_asi_se_marco(): void
    {
        $equipo = $this->impresora(conMaterial: false);

        // Sin destino, aunque esté marcada: no vuelve a todo el laboratorio de gramos.
        RateCard::create(['slug' => 'suelta', 'name' => 'Suelta', 'basis' => 'unidad', 'unit' => 'g', 'price_minor' => 100, 'ask_on_booking' => true]);

        // Y un precio de material sobre el área —el MDF por cm² de la sala de
        // láser— es para cotizar: no se le pregunta a quien reserva.
        RateCard::create([
            'slug' => 'mdf', 'name' => 'MDF 5.5 (cm2)', 'rateable_type' => Area::class, 'rateable_id' => $equipo->area_id,
            'basis' => 'unidad', 'unit' => 'cm2', 'price_minor' => 0.4,
        ]);

        $this->assertNull(MaterialDelEquipo::tarifa($equipo));

        $u = $this->persona($equipo->riskFamily);
        $this->assertSame(500, app(QuoteService::class)->cotizar($u, $equipo, 60)->depositoMenor, 'la garantía sigue donde estaba');

        $this->actingAs($u)->get(route('reservas.show', $equipo))->assertOk()->assertDontSee('Material que vas a usar');
    }

    public function test_donde_hay_material_se_retiene_lo_que_cuesta_y_no_una_garantia(): void
    {
        $con = $this->impresora();
        $u = $this->persona($con->riskFamily);

        $q = app(QuoteService::class)->cotizar($u, $con, 60, material: 60);

        $this->assertSame(800, $q->totalMenor);
        $this->assertSame(0, $q->depositoMenor, 'ya no hay garantía fija');
        $this->assertSame(800, $q->comprometidoMenor());
        $this->assertSame('Material', collect($q->lineas)->last()['concepto']);
        $this->assertStringContainsString('60 g', collect($q->lineas)->last()['detalle']);

        // Donde no hay material tarifado, todo sigue como estaba.
        $sin = $this->impresora(conMaterial: false);
        $otra = $this->persona($sin->riskFamily);

        $this->assertSame(500, app(QuoteService::class)->cotizar($otra, $sin, 60, material: 60)->depositoMenor);
        $this->assertSame(0, app(QuoteService::class)->cotizar($otra, $sin, 60, material: 60)->totalMenor);
    }

    // ------------------------------------------------------------ reservar

    public function test_al_reservar_se_retiene_el_material_y_al_cerrar_se_cobra_lo_gastado(): void
    {
        $equipo = $this->impresora();
        $u = $this->persona($equipo->riskFamily, 2000);

        $desde = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(2), material: 60);

        $this->assertSame('confirmada', $reserva->status);
        $this->assertSame(60.0, $reserva->material_estimated);
        $this->assertSame(800, $reserva->estimated_cost_minor);
        $this->assertSame(1200, $this->saldo($u), 'se retienen los 8 del material, no 5');

        // Gastó menos de lo que dijo: 30 g son 4, y los otros 4 vuelven.
        $this->travelTo($desde);
        app(AttendanceService::class)->checkIn($reserva->refresh());
        $this->travel(1)->hours();

        $reserva->update(['material_used' => 30]);
        $cerrada = app(AttendanceService::class)->checkOut($reserva->refresh());

        $this->assertSame(400, $cerrada->actual_cost_minor);
        $this->assertSame(1600, $this->saldo($u));
    }

    public function test_si_gasto_mas_de_lo_que_dijo_la_diferencia_se_cobra(): void
    {
        $equipo = $this->impresora();
        $u = $this->persona($equipo->riskFamily, 2000);

        $desde = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(2), material: 60);

        $this->travelTo($desde);
        app(AttendanceService::class)->checkIn($reserva->refresh());
        $this->travel(1)->hours();

        $reserva->update(['material_used' => 100]);   // 13,33 → 14
        $cerrada = app(AttendanceService::class)->checkOut($reserva->refresh());

        $this->assertSame(1400, $cerrada->actual_cost_minor);
        $this->assertSame(600, $this->saldo($u));
    }

    public function test_sin_saldo_para_el_material_no_se_reserva_y_se_dice_cuanto_falta(): void
    {
        $equipo = $this->impresora();
        $u = $this->persona($equipo->riskFamily, 500);   // los 5 de antes ya no bastan

        $desde = now()->addMinutes(5);

        try {
            app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(2), material: 60);
            $this->fail('no debía dejar reservar');
        } catch (BookingException $e) {
            $this->assertStringContainsString('necesita 8,00 FBC', $e->getMessage());
            $this->assertStringContainsString('te faltan 3,00 FBC', $e->getMessage());
            $this->assertStringContainsString('$3.000', $e->getMessage());
        }

        $this->assertSame(0, Reservation::count());
        $this->assertSame(500, $this->saldo($u), 'no se retuvo nada');

        // Con lo justo sí.
        $this->assertSame('confirmada', app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(2), material: 37)->status);
        $this->assertSame(0, $this->saldo($u));
    }

    // ------------------------------------------------------------ las pantallas

    public function test_la_pagina_pide_los_gramos_y_sin_ellos_no_reserva(): void
    {
        $equipo = $this->impresora();
        $u = $this->persona($equipo->riskFamily, 2000);
        $this->actingAs($u);

        $this->get(route('reservas.show', $equipo))
            ->assertOk()
            ->assertSee('Material que vas a usar')
            ->assertSee('8 FBC por cada 60 g')
            ->assertDontSee('garantía; el resto se cobra al cerrar');

        $cuando = ['fecha' => now()->addDay()->format('Y-m-d'), 'inicio' => '10:00', 'duracion' => 60];

        $this->from(route('reservas.show', $equipo))
            ->post(route('reservas.store', $equipo), $cuando)
            ->assertSessionHasErrors('material');

        $this->assertSame(0, Reservation::count());

        $this->post(route('reservas.store', $equipo), $cuando + ['material' => 45])
            ->assertSessionHasNoErrors();

        $reserva = Reservation::first();
        $this->assertSame(45.0, $reserva->material_estimated);
        $this->assertSame(600, $reserva->estimated_cost_minor);

        // Un equipo sin material tarifado no lo pide.
        $sin = $this->impresora(conMaterial: false);
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $sin->risk_family_id, 'level' => 'byte']);

        $this->get(route('reservas.show', $sin))->assertOk()->assertDontSee('Material que vas a usar');
    }

    public function test_al_cerrar_se_confirma_lo_gastado_y_si_no_alcanza_no_se_cierra(): void
    {
        $equipo = $this->impresora();
        $equipo->update(['qr_token' => 'qr-' . uniqid()]);
        $u = $this->persona($equipo->riskFamily, 1000);

        $desde = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(2), material: 60);

        $this->travelTo($desde);
        app(AttendanceService::class)->checkIn($reserva->refresh());
        $this->travel(1)->hours();

        $this->actingAs($u);
        $pagina = route('escaneo.equipo', $equipo->qr_token);

        $this->get($pagina)->assertOk()->assertSee('¿Cuánto material gastaste?')->assertSee('Al reservar dijiste 60 g');

        // Sin decirlo no se cierra.
        $this->from($pagina)->post(route('escaneo.checkout', $reserva))->assertSessionHasErrors('material_used');
        $this->assertSame('en_curso', $reserva->fresh()->status);

        // Gastó 200 g —27 FabCoins— y tiene 10: no se cierra, ni queda anotado a medias.
        $this->from($pagina)->post(route('escaneo.checkout', $reserva), ['material_used' => 200])
            ->assertRedirect($pagina)
            ->assertSessionHasErrors('reserva');

        $reserva->refresh();
        $this->assertSame('en_curso', $reserva->status);
        $this->assertNull($reserva->material_used);
        $this->assertSame(200, $this->saldo($u));

        // Con 75 g —10 FabCoins— sí: se cobran los 2 que faltaban.
        $this->post(route('escaneo.checkout', $reserva), ['material_used' => 75])->assertSessionHasNoErrors();

        $reserva->refresh();
        $this->assertSame('completada', $reserva->status);
        $this->assertSame(75.0, $reserva->material_used);
        $this->assertSame(1000, $reserva->actual_cost_minor);
        $this->assertSame(0, $this->saldo($u));
    }
}
