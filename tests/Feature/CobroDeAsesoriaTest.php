<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\AsistenciaDeAsesoria;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La asesoría cuesta (§12).
 *
 * Lo que se paga es el tiempo de alguien del equipo: un precio plano, que se
 * retiene al pedirla y se causa cuando quien atiende valida que la persona
 * vino. Si no vino, o no la atendieron, vuelve. Sin saldo no hay asesoría.
 */
class CobroDeAsesoriaTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;

    private User $asesor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);
        $this->equipo = Asset::create(['name' => 'Cortadora láser', 'area_id' => $area->id, 'status' => 'operativo', 'is_reservable' => true]);

        $this->asesor = User::factory()->create(['name' => 'Asesor', 'status' => 'activo']);
        WorkSchedule::create([
            'user_id' => $this->asesor->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
        AssetAdvisor::create(['user_id' => $this->asesor->id, 'asset_id' => $this->equipo->id, 'es_responsable' => true]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function conSaldo(int $menor): User
    {
        $u = User::factory()->create(['status' => 'activo']);

        if ($menor > 0) {
            app(ChargeService::class)->dotar($u, $menor, '2026-08');
        }

        return $u;
    }

    private function saldo(User $u): int
    {
        return app(LedgerService::class)->saldoDe($u);
    }

    public function test_de_fabrica_cuesta_dos_fabcoins(): void
    {
        $this->assertSame(200, Settings::precioDeAsesoriaMenor());
    }

    public function test_pedirla_retiene_el_precio_y_validar_la_llegada_lo_causa(): void
    {
        $u = $this->conSaldo(1000);

        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        $this->assertSame(200, $asesoria->estimated_cost_minor);
        $this->assertSame(800, $this->saldo($u), 'retenido al pedirla');

        $this->travelTo($this->hora('10:50'));
        app(AsistenciaDeAsesoria::class)->llego($asesoria, $this->asesor);

        $this->assertSame(800, $this->saldo($u), 'lo retenido se causó: no vuelve');
        $this->assertSame(200, $asesoria->fresh()->actual_cost_minor);
    }

    public function test_si_no_vino_vuelve(): void
    {
        $u = $this->conSaldo(1000);
        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        $this->travelTo($this->hora('10:30'));
        app(AsistenciaDeAsesoria::class)->noVino($asesoria, $this->asesor);

        // No se penaliza la ausencia, igual que con las máquinas.
        $this->assertSame(1000, $this->saldo($u));
    }

    public function test_si_no_lo_atendieron_vuelve(): void
    {
        $u = $this->conSaldo(1000);
        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        $this->travelTo($this->hora('11:00'));
        app(AsistenciaDeAsesoria::class)->noMeAtendieron($asesoria, $u);

        $this->assertSame(1000, $this->saldo($u));
    }

    public function test_si_la_cancela_vuelve(): void
    {
        $u = $this->conSaldo(1000);
        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        app(BookingService::class)->cancelar($asesoria);

        $this->assertSame(1000, $this->saldo($u));
    }

    public function test_sin_saldo_no_hay_asesoria_y_se_dice_con_el_importe(): void
    {
        $u = $this->conSaldo(100);

        try {
            app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));
            $this->fail('tenía 1 y cuesta 2');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Saldo insuficiente', $e->getMessage());
        }

        $this->assertSame(0, \App\Models\Reservation::count(), 'no quedó ninguna asesoría a medias');
    }

    public function test_si_nadie_la_valida_en_el_plazo_se_cierra_y_devuelve(): void
    {
        $u = $this->conSaldo(1000);
        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));
        $this->assertSame(800, $this->saldo($u));

        // Al dia siguiente todavia no: quien atiende tiene tres dias.
        $this->assertSame(0, app(\App\Services\Booking\AttendanceService::class)->cerrarAsesoriasSinValidar($this->hora('10:45')->addDay()));
        $this->assertSame('confirmada', $asesoria->fresh()->status);

        // Al cuarto dia, el barrido de siempre la cierra y devuelve.
        app(\App\Services\Booking\AttendanceService::class)->liberarAusencias($this->hora('10:45')->addDays(4));

        $asesoria->refresh();
        $this->assertSame('cancelada', $asesoria->status);
        $this->assertStringContainsString('Nadie validó', $asesoria->status_reason);
        $this->assertSame(1000, $this->saldo($u), 'lo retenido volvió');
    }

    public function test_con_los_cobros_apagados_no_mueve_saldo(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, false, 'finanzas');
        $u = $this->conSaldo(0);

        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        $this->assertNotNull($asesoria);
        $this->assertSame(200, $asesoria->estimated_cost_minor, 'se anota lo que costaría, para el día que se encienda');
        $this->assertSame(0, $this->saldo($u));
    }

    public function test_el_precio_se_edita_y_cero_es_gratis(): void
    {
        Setting::put(Settings::ASESORIA_PRECIO, 0, 'finanzas');
        $u = $this->conSaldo(0);

        $asesoria = app(AsesoriaService::class)->agendar($u, $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        $this->assertNotNull($asesoria);
        $this->assertSame(0, $this->saldo($u));
    }

    public function test_la_pantalla_dice_lo_que_cuesta_antes_de_elegir_hora(): void
    {
        $u = $this->conSaldo(500);

        $this->actingAs($u)
            ->get(route('asesoria.show', $this->equipo))
            ->assertOk()
            ->assertSee('Cuesta <strong>2,00', false)
            ->assertSee('Tu saldo: <strong>5,00', false);
    }

    public function test_desde_el_sitio_sin_saldo_se_explica_al_lado_de_la_hora(): void
    {
        $u = $this->conSaldo(0);

        $this->actingAs($u)
            ->post(route('asesoria.store', $this->equipo), ['inicio' => $this->hora('10:00')->toDateTimeString()])
            ->assertRedirect()
            ->assertSessionHasErrors('inicio');
    }
}
