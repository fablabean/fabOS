<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Services\Booking\TraspasoDeAtencion;
use App\Services\Calendar\Calendario;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién recibe en un espacio (§10).
 *
 * Una sala reservada desde fuera no tenía a nadie del equipo detrás: quien
 * llegaba no sabía a quién buscar, y a nadie le salía que venía. Ahora alguien
 * en jornada la recibe —ubicarla, darle una herramienta—, sin que eso le
 * comprometa la hora.
 */
class QuienRecibeEnElEspacioTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        // Lunes por la mañana: el equipo trabaja los lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $this->area = Area::create(['slug' => 'computo', 'name' => 'Cómputo']);
        $this->sala = Space::create(['slug' => 'sala', 'name' => 'Sala de cómputo', 'capacity' => 12, 'is_reservable' => true]);
        $this->sala->areas()->attach($this->area);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    /** Alguien del equipo, con rol y en jornada presencial el lunes. */
    private function colaborador(string $nombre): User
    {
        $u = User::factory()->create(['name' => $nombre, 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_CONSULTOR, 'web'));

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    private function reserva(User $quien, string $inicio = '10:00', string $fin = '12:00'): Reservation
    {
        return app(EspacioBookingService::class)->reservar($quien, $this->sala, $this->hora($inicio), $this->hora($fin));
    }

    public function test_alguien_en_jornada_recibe_la_reserva(): void
    {
        $ana = $this->colaborador('Ana');
        $quien = User::factory()->create(['status' => 'activo']);

        $r = $this->reserva($quien);

        $this->assertSame($ana->id, $r->supervisor_id, 'la recibe quien está en jornada');
        $this->assertTrue($r->laRecibe($ana));
        $this->assertTrue($r->laAtiende($ana), 'y le cuenta como algo que atiende');
    }

    /** Recibir no es tiempo comprometido: Ana sigue libre para lo demás. */
    public function test_recibir_no_le_ocupa_la_hora(): void
    {
        $ana = $this->colaborador('Ana');
        $r = $this->reserva(User::factory()->create(['status' => 'activo']));

        $this->assertSame($ana->id, $r->supervisor_id);
        $this->assertTrue(app(BookingService::class)->personaLibre($ana, $this->hora('10:00'), $this->hora('12:00')));
        $this->assertNull(app(BookingService::class)->porQueNoEstaLibre($ana, $this->hora('10:30'), $this->hora('11:00')));
    }

    /** Sin nadie en jornada, la reserva sale igual: esto ayuda, no restringe. */
    public function test_sin_nadie_en_jornada_no_recibe_nadie_y_la_reserva_sigue(): void
    {
        $r = $this->reserva(User::factory()->create(['status' => 'activo']));

        $this->assertNull($r->supervisor_id);
        $this->assertSame('solicitada', $r->status, 'fuera de jornada queda como solicitud, como siempre');
    }

    /** La persona fija de la sala manda sobre el responsable del área, si está en jornada. */
    public function test_la_persona_fija_de_la_sala_va_primero(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $this->area->responsibles()->attach($ana->id);
        $this->sala->update(['host_id' => $beto->id]);

        $r = $this->reserva(User::factory()->create(['status' => 'activo']));

        $this->assertSame($beto->id, $r->supervisor_id, 'la sala tiene dueño: recibe él');

        // Sin jornada ese día, recibe quien esté: el responsable del área.
        \App\Models\WorkSchedule::where('user_id', $beto->id)->delete();
        $otra = Space::create(['slug' => 'otra', 'name' => 'Otra sala', 'capacity' => 5, 'is_reservable' => true, 'host_id' => $beto->id]);
        $otra->areas()->attach($this->area);

        $r2 = app(EspacioBookingService::class)->reservar(User::factory()->create(['status' => 'activo']), $otra, $this->hora('14:00'), $this->hora('15:00'));

        $this->assertSame($ana->id, $r2->supervisor_id);
    }

    /** A quien le cae recibir se le avisa, con quién viene y cuándo. */
    public function test_a_quien_recibe_se_le_avisa(): void
    {
        $ana = $this->colaborador('Ana');
        $quien = User::factory()->create(['name' => 'Laura Bareño', 'status' => 'activo']);

        $r = $this->reserva($quien);

        $aviso = \App\Models\NotificationLog::where('key', 'espacio.recibir')->where('user_id', $ana->id)->first();

        $this->assertNotNull($aviso, 'le llega el aviso a quien recibe');
        $this->assertStringContainsString('Laura Bareño', $aviso->body);
        $this->assertStringContainsString('Sala de cómputo', $aviso->body);
        $this->assertStringContainsString('10:00', $aviso->body);
    }

    /** Quien responde por el área de la sala va primero. */
    public function test_el_responsable_del_area_va_primero(): void
    {
        $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $this->area->responsibles()->attach($beto->id);

        $r = $this->reserva(User::factory()->create(['status' => 'activo']));

        $this->assertSame($beto->id, $r->supervisor_id);
    }

    /** Y entre iguales, por turno: quien menos recibimientos tenga por delante. */
    public function test_se_reparte_por_turno(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');

        $primera = $this->reserva(User::factory()->create(['status' => 'activo']), '09:00', '10:00');
        $segunda = $this->reserva(User::factory()->create(['status' => 'activo']), '10:00', '11:00');

        $this->assertSame($ana->id, $primera->supervisor_id, 'por nombre, la primera va a Ana');
        $this->assertSame($beto->id, $segunda->supervisor_id, 'y la siguiente a Beto, que no tenía ninguna');
    }

    /** Con acompañantes elegidos a mano no se le suma nadie más. */
    public function test_con_acompanantes_no_se_asigna_quien_recibe(): void
    {
        $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');

        $r = app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']), $this->sala,
            $this->hora('10:00'), $this->hora('12:00'), acompanantesIds: [$beto->id],
        );

        $this->assertNull($r->supervisor_id);
        $this->assertTrue($r->companions->contains('id', $beto->id));
    }

    /** A quien recibe le sale en Mi cuenta, marcado como recibir; a quien reservó, quién lo recibe. */
    public function test_le_sale_a_quien_recibe_y_a_quien_reservo(): void
    {
        $ana = $this->colaborador('Ana');
        $quien = User::factory()->create(['status' => 'activo']);
        $this->reserva($quien);

        $this->actingAs($ana)->get(route('home'))
            ->assertOk()
            ->assertSee('Acompañamientos que voy a hacer')
            ->assertSee('Sala de cómputo')
            ->assertSee('Recibir · ' . EspacioBookingService::MINUTOS_RECIBIR . ' min')
            ->assertSee($quien->name);

        $this->actingAs($quien)->get(route('home'))
            ->assertOk()
            ->assertSee('Te recibe Ana');
    }

    /** En su calendario, recibir dura lo que dura recibir. */
    public function test_en_el_calendario_recibir_dura_cinco_minutos(): void
    {
        $ana = $this->colaborador('Ana');
        $r = $this->reserva(User::factory()->create(['status' => 'activo']));

        $ics = app(Calendario::class)->deUnaReserva($r, $ana);

        $this->assertStringContainsString('Recibir', $ics);
        $this->assertStringContainsString('DTSTART:' . $this->hora('10:00')->utc()->format('Ymd\THis\Z'), $ics);
        $this->assertStringContainsString('DTEND:' . $this->hora('10:05')->utc()->format('Ymd\THis\Z'), $ics);
    }

    /** Se puede pasar a otra persona, y solo se le exige libre esos minutos. */
    public function test_se_pasa_a_otra_persona_del_equipo(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->reserva(User::factory()->create(['status' => 'activo']));
        $this->assertSame($ana->id, $r->supervisor_id);

        // Beto acompaña otra sala de 11 a 12: no importa, recibir es a las 10.
        $otra = Space::create(['slug' => 'taller', 'name' => 'Taller', 'capacity' => 10, 'is_reservable' => true]);
        app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']), $otra,
            $this->hora('11:00'), $this->hora('12:00'), acompanantesIds: [$beto->id],
        );

        $traspasos = app(TraspasoDeAtencion::class);
        $propuesta = $traspasos->proponer($r, $ana, $beto);
        $traspasos->aceptar($propuesta, $beto);

        $this->assertSame($beto->id, $r->fresh()->supervisor_id);
        $this->assertFalse($r->fresh()->laRecibe($ana));
    }
}
