<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\RateCard;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingService;
use App\Services\Money\ChargeService;
use App\Services\Money\HorasIncluidas;
use App\Services\Money\QuoteService;
use App\Support\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Horas incluidas a la semana con certifab (§12).
 *
 * Lo que se defiende: que el cupo sea por semana y no por reserva, que solo
 * cuente para quien está habilitado, que gratis sea gratis —sin montaje ni
 * mínimo—, y que al liquidar una reserva no se cuente contra sí misma.
 */
class HorasIncluidasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Un martes a las 9:00 en Bogotá: lejos de los bordes de la semana.
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00', config('fabos.lab.timezone')));
    }

    private function cotizador(): QuoteService
    {
        return app(QuoteService::class);
    }

    private function persona(float $factor = 1): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Categoría',
            'can_reserve' => true, 'rate_factor' => $factor,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /** Una familia con dos impresoras y su tarifa: 4/h, montaje 1, mínimo 2, 8 h incluidas. */
    private function familia(array $tarifa = []): RiskFamily
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create(['area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM']);

        foreach (['Prusa', 'Bambu'] as $nombre) {
            Asset::create([
                'area_id' => $area->id, 'risk_family_id' => $rf->id,
                'name' => $nombre . ' ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
                'autonomous_minutes' => 720, 'max_minutes' => 720,
            ]);
        }

        RateCard::create(array_merge([
            'slug' => 't-' . uniqid(), 'name' => 'FDM',
            'rateable_type' => RiskFamily::class, 'rateable_id' => $rf->id,
            'basis' => 'tiempo', 'unit' => 'hora',
            'price_minor' => 400, 'setup_minor' => 100, 'minimum_minor' => 200,
            'rounding_minutes' => 15, 'included_weekly_minutes' => 480,
        ], $tarifa));

        return $rf;
    }

    private function habilitar(User $u, RiskFamily $rf): void
    {
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $rf->id, 'level' => 'byte']);
    }

    private function reserva(User $u, Asset $equipo, int $minutos, string $estado = 'confirmada', ?Carbon $desde = null): Reservation
    {
        $desde ??= now()->addHour();

        return Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $equipo->id,
            'user_id' => $u->id, 'status' => $estado,
            'starts_at' => $desde, 'ends_at' => $desde->copy()->addMinutes($minutos),
        ]);
    }

    // ------------------------------------------------------------- el cálculo

    public function test_con_certifab_las_horas_incluidas_salen_gratis_del_todo(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);

        $q = $this->cotizador()->cotizar($u, $rf->assets->first(), 480);

        // Gratis es gratis: ni montaje ni mínimo.
        $this->assertSame(0, $q->totalMenor);
        $this->assertSame(480, $q->minutosIncluidos);
        $this->assertSame(0, $q->minutosIncluidosRestantes);
        $this->assertSame('Horas incluidas con tu certifab', $q->lineas[0]['concepto']);
        $this->assertTrue($q->tieneDesglose(), 'a quien no le cuesta le interesa saber por qué');
    }

    public function test_lo_que_pasa_del_cupo_se_cobra_con_la_tarifa_de_siempre(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);

        // Diez horas: ocho gratis, dos cobradas (800) más el montaje (100).
        $q = $this->cotizador()->cotizar($u, $rf->assets->first(), 600);

        $this->assertSame(900, $q->totalMenor);
        $this->assertSame(480, $q->minutosIncluidos);
    }

    public function test_el_cupo_sale_del_reloj_antes_de_redondear(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);

        // 8 h y 5 min: solo se redondean los 5 que sobran, a un bloque de 15.
        $q = $this->cotizador()->cotizar($u, $rf->assets->first(), 485);

        $tiempo = collect($q->lineas)->firstWhere('concepto', 'Tiempo de máquina');
        $this->assertSame(100, $tiempo['importe']);   // 15 min a 400/h
    }

    public function test_sin_certifab_se_cobra_desde_el_primer_minuto(): void
    {
        $rf = $this->familia();
        $u = $this->persona();

        $q = $this->cotizador()->cotizar($u, $rf->assets->first(), 60);

        $this->assertSame(500, $q->totalMenor);   // 400 de hora + 100 de montaje
        $this->assertSame(0, $q->minutosIncluidos);
    }

    public function test_un_certifab_vencido_o_revocado_no_incluye_nada(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $rf->id, 'level' => 'byte', 'revoked_at' => now()]);

        $this->assertSame(0, $this->cotizador()->cotizar($u, $rf->assets->first(), 60)->minutosIncluidos);
    }

    public function test_una_tarifa_sin_horas_incluidas_sigue_como_antes(): void
    {
        $rf = $this->familia(['included_weekly_minutes' => 0]);
        $u = $this->persona();
        $this->habilitar($u, $rf);

        $q = $this->cotizador()->cotizar($u, $rf->assets->first(), 60);

        $this->assertSame(500, $q->totalMenor);
        $this->assertNull($q->minutosIncluidosRestantes);
        $this->assertNotSame('Horas incluidas con tu certifab', $q->lineas[0]['concepto']);
    }

    public function test_el_acompanamiento_se_cobra_aunque_el_tiempo_este_incluido(): void
    {
        $rf = $this->familia(['supervision_hour_minor' => 1000]);
        $u = $this->persona();
        $this->habilitar($u, $rf);

        // Es el tiempo de otra persona: el cupo no lo cubre.
        $q = $this->cotizador()->cotizar($u, $rf->assets->first(), 60, conAcompanante: true);

        $this->assertSame(1000, $q->totalMenor);
    }

    // ------------------------------------------------------------- la semana

    public function test_el_cupo_es_por_semana_y_se_gasta_entre_todas_las_impresoras(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);
        [$prusa, $bambu] = $rf->assets;

        // Ya reservó 6 h en la Prusa esta semana.
        $this->reserva($u, $prusa, 360);

        // En la Bambu le quedan 2: de 3 h, una se cobra.
        $q = $this->cotizador()->cotizar($u, $bambu, 180);

        $this->assertSame(120, $q->minutosIncluidos);
        $this->assertSame(0, $q->minutosIncluidosRestantes);
        $this->assertSame(500, $q->totalMenor);   // 1 h + montaje
    }

    public function test_encadenar_reservas_no_reinicia_el_cupo(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);
        $prusa = $rf->assets->first();

        $this->reserva($u, $prusa, 480);

        // La segunda de 8 h en la misma semana se cobra entera.
        $q = $this->cotizador()->cotizar($u, $prusa, 480);

        $this->assertSame(0, $q->minutosIncluidos);
        $this->assertSame(3300, $q->totalMenor);   // 8 h + montaje
    }

    public function test_la_semana_siguiente_vuelve_a_empezar(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);
        $prusa = $rf->assets->first();

        $this->reserva($u, $prusa, 480);

        $q = $this->cotizador()->cotizar($u, $prusa, 480, cuando: now()->addWeek());

        $this->assertSame(480, $q->minutosIncluidos);
    }

    public function test_la_semana_es_la_del_inicio_de_la_reserva_en_hora_del_laboratorio(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);
        $prusa = $rf->assets->first();

        // Domingo 22:00 en Bogotá: es la semana que termina, no la que empieza.
        $domingo = Carbon::parse('2026-09-13 22:00', config('fabos.lab.timezone'));
        $this->reserva($u, $prusa, 480, desde: $domingo);

        $this->assertSame(480, app(HorasIncluidas::class)->usados($u, RateCard::para($prusa), $domingo));
        $this->assertSame(0, app(HorasIncluidas::class)->usados($u, RateCard::para($prusa), now()), 'el martes ya es otra semana');
    }

    public function test_lo_cancelado_lo_solicitado_y_lo_producido_no_gastan_cupo(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);
        $prusa = $rf->assets->first();

        $this->reserva($u, $prusa, 480, 'cancelada');
        $this->reserva($u, $prusa, 480, 'no_show');
        $this->reserva($u, $prusa, 480, 'solicitada');
        $this->reserva($u, $prusa, 480)->update(['is_production' => true]);

        $this->assertSame(0, app(HorasIncluidas::class)->usados($u, RateCard::para($prusa)));
    }

    public function test_lo_cerrado_cuenta_por_el_reloj_real(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);
        $prusa = $rf->assets->first();

        // Reservó 8 h pero cerró a las 3: gastó 3, y le quedan 5.
        $this->reserva($u, $prusa, 480, 'completada')->update([
            'checked_in_at'  => now()->subHours(4),
            'checked_out_at' => now()->subHour(),
        ]);

        $this->assertSame(180, app(HorasIncluidas::class)->usados($u, RateCard::para($prusa)));
    }

    // ------------------------------------------------------- el ciclo completo

    public function test_al_liquidar_la_reserva_no_se_cuenta_contra_si_misma(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');
        $rf = $this->familia(['deposit_minor' => 0]);
        $u = $this->persona();
        $this->habilitar($u, $rf);
        $prusa = $rf->assets->first();
        app(ChargeService::class)->dotar($u, 10000, '2026-09');

        $desde = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $prusa, $desde, $desde->copy()->addHours(6));

        // Seis horas dentro del cupo: no se compromete nada.
        $this->assertSame(0, $reserva->estimated_cost_minor);
        $this->assertSame(10000, app(\App\Services\Ledger\LedgerService::class)->saldoDe($u));

        // Llega, imprime cinco horas y cierra. Si la reserva se contara contra
        // sí misma, esas cinco horas se cobrarían como si el cupo ya estuviera
        // gastado.
        $asistencia = app(AttendanceService::class);
        $this->travelTo($desde);
        $asistencia->checkIn($reserva->refresh());
        $this->travel(5)->hours();
        $cerrada = $asistencia->checkOut($reserva->refresh());

        $this->assertSame(0, $cerrada->actual_cost_minor);
        $this->assertSame(10000, app(\App\Services\Ledger\LedgerService::class)->saldoDe($u));
    }

    public function test_la_pagina_del_equipo_explica_las_horas_incluidas(): void
    {
        $rf = $this->familia();
        $u = $this->persona();
        $this->habilitar($u, $rf);

        $this->actingAs($u)
            ->get(route('reservas.show', $rf->assets->first()))
            ->assertOk()
            ->assertSee('Horas incluidas con tu certifab')
            ->assertSee('te quedan 7 h');
    }

    public function test_la_tarifa_sembrada_de_fdm_incluye_ocho_horas(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->seed(\Database\Seeders\TariffSeeder::class);

        $this->assertSame(480, RateCard::where('slug', 'familia-fdm')->value('included_weekly_minutes'));
        $this->assertSame(0, RateCard::where('slug', 'familia-resina')->value('included_weekly_minutes'));
    }
}
