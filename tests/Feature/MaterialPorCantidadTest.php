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
            ->assertDontSee('No: traigo el mío');

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

    // ------------------------------------------------- el láser: tiempo, y material a elegir

    /**
     * La cortadora láser como queda: 8 FabCoins la media hora, sin garantía,
     * y MDF por cm² para quien quiera comprarlo —con 20 × 20 del de 5 mm de regalo—.
     *
     * @return array{0: Asset, 1: RateCard, 2: RateCard} el equipo, el MDF de 5 y el de 2,7
     */
    private function laser(): array
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Corte y grabado']);
        $rf = RiskFamily::create(['area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Láser CO₂']);

        RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'Láser CO₂', 'rateable_type' => RiskFamily::class, 'rateable_id' => $rf->id,
            'basis' => 'tiempo', 'unit' => 'hora', 'price_minor' => 1600, 'rounding_minutes' => 15,
        ]);

        $mdf = fn (string $nombre, float $precio, float $regalo = 0) => RateCard::create([
            'slug' => 'm-' . uniqid(), 'name' => $nombre, 'rateable_type' => RiskFamily::class, 'rateable_id' => $rf->id,
            'basis' => 'unidad', 'unit' => 'cm2', 'price_minor' => $precio, 'capture_currency' => 'pesos',
            'offer_on_booking' => true, 'free_quantity' => $regalo,
        ]);

        $equipo = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id, 'name' => 'Máquina de corte Láser',
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 720, 'max_minutes' => 720,
        ]);

        return [$equipo, $mdf('MDF 5.5 (cm2)', 0.4, 400), $mdf('MDF 2.7 (cm2)', 0.2)];
    }

    public function test_en_el_laser_se_paga_el_tiempo_ocho_la_media_hora(): void
    {
        [$equipo] = $this->laser();
        $u = $this->persona($equipo->riskFamily);
        $q = app(QuoteService::class);

        foreach ([30 => 800, 60 => 1600, 90 => 2400, 180 => 4800, 20 => 800] as $minutos => $menor) {
            $this->assertSame($menor, $q->cotizar($u, $equipo, $minutos)->totalMenor, $minutos . ' min');
        }

        // Sin garantía aparte: se retiene lo que cuesta el tiempo.
        $this->assertSame(1600, $q->cotizar($u, $equipo, 60)->comprometidoMenor());
        $this->assertNull(MaterialDelEquipo::tarifa($equipo), 'aquí el material no es obligatorio');
        $this->assertCount(2, MaterialDelEquipo::ofrecidas($equipo));
    }

    public function test_el_material_del_laser_se_elige_y_veinte_por_veinte_van_de_regalo(): void
    {
        [$equipo, $mdf5, $mdf2] = $this->laser();

        // 4 pesos el cm²: el regalo son 400 cm², y lo que pase se cobra.
        foreach ([400 => 0, 300 => 0, 401 => 100, 900 => 200, 1600 => 500] as $cm2 => $menor) {
            $this->assertSame($menor, MaterialDelEquipo::costo($mdf5, (float) $cm2), $cm2 . ' cm² del de 5');
        }

        // El de 2,7 no trae regalo.
        $this->assertSame(100, MaterialDelEquipo::costo($mdf2, 400));
        $this->assertSame('0,4 FBC por cada 100 cm2', MaterialDelEquipo::regla($mdf5));
        $this->assertTrue(MaterialDelEquipo::esSuperficie($mdf5));

        $u = $this->persona($equipo->riskFamily);
        $q = app(QuoteService::class)->cotizar($u, $equipo, 60, material: 900, tarifaMaterial: $mdf5);

        $this->assertSame(1800, $q->totalMenor, 'una hora y 500 cm² de más');
        $linea = collect($q->lineas)->last();
        $this->assertSame('Material · MDF 5.5 (cm2)', $linea['concepto']);
        $this->assertStringContainsString('400 de regalo', $linea['detalle']);

        // Una tarifa de otro equipo no se cuela por el identificador.
        $ajena = RateCard::create(['slug' => 'ajena', 'name' => 'Ajena', 'basis' => 'unidad', 'unit' => 'g', 'price_minor' => 9999, 'offer_on_booking' => true]);
        $this->assertNull(MaterialDelEquipo::elegida($equipo, $ajena->id));
    }

    public function test_reservar_el_laser_retiene_el_tiempo_y_el_material_y_sin_saldo_no_deja(): void
    {
        [$equipo, $mdf5] = $this->laser();
        $u = $this->persona($equipo->riskFamily, 2000);
        $this->actingAs($u);

        $this->get(route('reservas.show', $equipo))
            ->assertOk()
            ->assertSee('¿Compras material del laboratorio?')
            ->assertSee('No: traigo el mío')
            ->assertSee('400 cm2 de regalo')
            ->assertDontSee('Material que vas a usar');

        // La cuenta que pide la página al cambiar la duración o el material.
        $this->getJson(route('reservas.cotizar', $equipo) . '?duracion=90')
            ->assertOk()
            ->assertJson(['total' => '24,00', 'retiene' => '24,00', 'falta' => '4,00', 'faltaEnPesos' => '$4.000', 'tiempo' => '1 hora 30 minutos']);

        $this->getJson(route('reservas.cotizar', $equipo) . '?duracion=60&material=900&material_tarifa=' . $mdf5->id)
            ->assertOk()
            ->assertJson(['total' => '18,00', 'retiene' => '18,00', 'falta' => null]);

        $cuando = ['fecha' => now()->addDay()->format('Y-m-d'), 'inicio' => '10:00'];

        // Hora y media son 24: no le alcanza.
        $this->from(route('reservas.show', $equipo))
            ->post(route('reservas.store', $equipo), $cuando + ['duracion' => 90])
            ->assertSessionHasErrors('fecha');
        $this->assertSame(0, Reservation::count());

        // Elegir material sin decir cuánto no pasa.
        $this->from(route('reservas.show', $equipo))
            ->post(route('reservas.store', $equipo), $cuando + ['duracion' => 60, 'material_tarifa' => $mdf5->id])
            ->assertSessionHasErrors('material');

        // Una hora con 900 cm² de MDF: 16 + 2.
        $this->post(route('reservas.store', $equipo), $cuando + ['duracion' => 60, 'material_tarifa' => $mdf5->id, 'material' => 900])
            ->assertSessionHasNoErrors();

        $reserva = Reservation::first();
        $this->assertSame($mdf5->id, $reserva->material_rate_card_id);
        $this->assertSame(900.0, $reserva->material_estimated);
        $this->assertSame(1800, $reserva->estimated_cost_minor);
        $this->assertSame(200, $this->saldo($u));
    }

    public function test_sin_comprar_material_el_laser_solo_cobra_el_tiempo_y_al_cerrar_el_real(): void
    {
        [$equipo, $mdf5, $mdf2] = $this->laser();
        $equipo->update(['qr_token' => 'qr-' . uniqid()]);
        $u = $this->persona($equipo->riskFamily, 5000);

        $desde = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(2));

        $this->assertNull($reserva->material_rate_card_id);
        $this->assertSame(3200, $reserva->estimated_cost_minor);
        $this->assertSame(1800, $this->saldo($u), 'dos horas retenidas');

        $this->travelTo($desde);
        app(AttendanceService::class)->checkIn($reserva->refresh());
        $this->travel(30)->minutes();

        $this->actingAs($u);
        $this->get(route('escaneo.equipo', $equipo->qr_token))->assertOk()->assertSee('¿Compraste material del laboratorio?');

        // Usó media hora y al final compró 400 cm² del de 2,7: 8 + 1.
        $this->post(route('escaneo.checkout', $reserva), ['material_tarifa' => $mdf2->id, 'material_used' => 400])
            ->assertSessionHasNoErrors();

        $reserva->refresh();
        $this->assertSame('completada', $reserva->status);
        $this->assertSame($mdf2->id, $reserva->material_rate_card_id);
        $this->assertSame(900, $reserva->actual_cost_minor);
        $this->assertSame(4100, $this->saldo($u), 'lo que sobró del tiempo vuelve');
    }
}
