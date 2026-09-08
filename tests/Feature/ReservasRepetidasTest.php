<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Una persona no pide dos veces lo mismo (§7, §10).
 *
 * Una solicitud no bloquea el recurso —esta esperando decision— y por eso
 * la restriccion de la base no la frena: alguien pulso catorce veces «pedir»
 * en dos segundos y la bandeja amanecio con catorce solicitudes iguales. Y
 * como las de espacios no salian en Mi cuenta, no las veia y seguia.
 */
class ReservasRepetidasTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
        $this->sala = Space::create(['slug' => 'lab-3d', 'name' => 'Lab. Impresión 3D', 'capacity' => 20, 'is_reservable' => true]);

        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'fdm', 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);
        $this->equipo = Asset::create([
            'name' => 'Prusa', 'area_id' => $area->id, 'risk_family_id' => $rf->id, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        // Alguien en jornada el lunes de 8 a 17: lo de la tarde-noche queda como solicitud.
        $colaborador = User::factory()->create(['status' => 'activo']);
        WorkSchedule::create([
            'user_id' => $colaborador->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '17:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::factory()->create(['status' => 'activo', 'user_category_id' => $cat->id]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    /** Lo que paso: la misma solicitud de sala, muchas veces seguidas. */
    public function test_una_solicitud_de_espacio_no_se_repite(): void
    {
        $jhonatan = $this->persona();

        $primera = app(EspacioBookingService::class)->reservar($jhonatan, $this->sala, $this->hora('16:00'), $this->hora('20:00'));
        $this->assertSame('solicitada', $primera->status, 'fuera de la jornada queda como solicitud');

        try {
            app(EspacioBookingService::class)->reservar($jhonatan, $this->sala, $this->hora('16:00'), $this->hora('20:00'));
            $this->fail('no debía dejar pedir lo mismo dos veces');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Ya tienes una solicitud de Lab. Impresión 3D', $e->getMessage());
            $this->assertStringContainsString('esperando decisión', $e->getMessage());
        }

        // Ni pisando la franja por un rato.
        $this->expectException(BookingException::class);
        app(EspacioBookingService::class)->reservar($jhonatan, $this->sala, $this->hora('19:00'), $this->hora('21:00'));
    }

    /** Otra persona si puede pedir la misma sala: la regla es por persona. */
    public function test_otra_persona_si_puede_pedirla(): void
    {
        app(EspacioBookingService::class)->reservar($this->persona(), $this->sala, $this->hora('16:00'), $this->hora('20:00'));

        $otra = app(EspacioBookingService::class)->reservar($this->persona(), $this->sala, $this->hora('16:00'), $this->hora('20:00'));

        $this->assertSame('solicitada', $otra->status);
    }

    /** Y con un equipo, igual. */
    public function test_una_reserva_de_equipo_no_se_repite(): void
    {
        $persona = $this->persona();
        Certifab::create(['user_id' => $persona->id, 'risk_family_id' => $this->equipo->risk_family_id, 'level' => 'byte']);

        app(BookingService::class)->reservar($persona, $this->equipo, $this->hora('10:00'), $this->hora('12:00'));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Ya tienes una reserva de Prusa');

        app(BookingService::class)->reservar($persona, $this->equipo, $this->hora('11:00'), $this->hora('13:00'));
    }

    /** Reservar para diez y ser dos: se cambia el numero, sin cancelar y volver a pedir. */
    public function test_se_cambia_el_numero_de_personas_sin_perder_la_reserva(): void
    {
        $jhonatan = $this->persona();
        $reserva = app(EspacioBookingService::class)->reservar($jhonatan, $this->sala, $this->hora('10:00'), $this->hora('12:00'), participantes: 10);

        $this->actingAs($jhonatan)->get(route('home'))->assertOk()->assertSee('Cambiar personas');

        $this->actingAs($jhonatan)
            ->post(route('reservas.personas', $reserva), ['participantes' => 2])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, $reserva->fresh()->participants);
        $this->assertSame('confirmada', $reserva->fresh()->status, 'la reserva sigue siendo la misma');

        // Mas de lo que cabe, no.
        $this->actingAs($jhonatan)
            ->post(route('reservas.personas', $reserva), ['participantes' => 25])
            ->assertSessionHasErrors('reserva');
        $this->assertSame(2, $reserva->fresh()->participants);

        // Y la de otra persona, ni verla.
        $this->actingAs($this->persona())
            ->post(route('reservas.personas', $reserva), ['participantes' => 1])
            ->assertForbidden();
    }

    /** En una sala compartida por puestos, se cuenta lo de los demas pero no lo propio. */
    public function test_en_una_sala_compartida_se_respetan_los_puestos_de_los_demas(): void
    {
        $this->sala->update(['shares_seats' => true, 'capacity' => 6]);
        $sala = $this->sala->fresh();

        $mia = app(EspacioBookingService::class)->reservar($this->persona(), $sala, $this->hora('10:00'), $this->hora('12:00'), participantes: 2);
        app(EspacioBookingService::class)->reservar($this->persona(), $sala, $this->hora('10:00'), $this->hora('12:00'), participantes: 3);

        // Quedan 1 libre + mis 2 = hasta 3 para mi.
        $this->assertSame(3, app(EspacioBookingService::class)->cambiarParticipantes($mia, 3)->participants);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('quedan 3 puestos');

        app(EspacioBookingService::class)->cambiarParticipantes($mia->fresh(), 4);
    }

    /** La solicitud de la sala se ve en Mi cuenta, y se puede cancelar desde ahi. */
    public function test_la_solicitud_de_espacio_se_ve_y_se_cancela_desde_mi_cuenta(): void
    {
        $jhonatan = $this->persona();
        $solicitud = app(EspacioBookingService::class)->reservar($jhonatan, $this->sala, $this->hora('16:00'), $this->hora('20:00'), participantes: 20);

        $this->actingAs($jhonatan)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Lab. Impresión 3D')
            ->assertSee('20 personas')
            ->assertSee('Esperando decisión de la coordinación')
            ->assertSee('Cancelar');

        $this->actingAs($jhonatan)
            ->post(route('reservas.cancel', $solicitud))
            ->assertRedirect();

        $this->assertSame('cancelada', $solicitud->fresh()->status);

        // Cancelada, ya puede pedirla de nuevo.
        $this->assertSame('solicitada', app(EspacioBookingService::class)->reservar($jhonatan, $this->sala, $this->hora('16:00'), $this->hora('20:00'))->status);
    }
}
