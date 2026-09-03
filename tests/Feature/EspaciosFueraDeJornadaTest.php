<?php

namespace Tests\Feature;

use App\Filament\Pages\Bandeja;
use App\Models\Reservation;
use App\Models\ShiftAssignment;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\ApprovalService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Espacios fuera de la jornada del equipo, y varios en una reserva (§7, §10).
 *
 * Dentro de la jornada una sala se confirma sola: no cuesta nada. Fuera, abrirla
 * son horas extras de alguien, y eso lo decide una persona. Antes se rechazaba
 * a secas y el pedido se perdía en un chat; ahora queda como solicitud en la
 * bandeja, igual que una máquina pedida fuera de la franja.
 *
 * Y quién cuenta como equipo depende de qué se atiende: un espacio físico lo
 * abre alguien presencial; uno virtual lo atiende quien esté en jornada aunque
 * sea desde casa.
 */
class EspaciosFueraDeJornadaTest extends TestCase
{
    use RefreshDatabase;

    private Space $taller;
    private Space $vr;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Lunes por la mañana, quieto: las jornadas de prueba son de lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $this->taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'type' => 'fisico', 'capacity' => 12, 'is_reservable' => true]);
        $this->vr = Space::create(['slug' => 'vr', 'name' => 'Lab. VR', 'type' => 'virtual', 'capacity' => 10, 'is_reservable' => true]);
    }

    private function espacios(): EspacioBookingService
    {
        return app(EspacioBookingService::class);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /** Alguien del equipo con jornada los lunes de 08:00 a 18:00. */
    private function colaborador(string $modalidad = WorkSchedule::PRESENCIAL): User
    {
        $u = User::create(['name' => 'Colaborador ' . $modalidad, 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => $modalidad, 'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    // ------------------------------------------------------- dentro y fuera

    public function test_dentro_de_la_jornada_la_sala_se_confirma_sola(): void
    {
        $this->colaborador();

        $r = $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('10:00'), $this->hora('12:00'));

        $this->assertSame('confirmada', $r->status);
    }

    public function test_fuera_de_la_jornada_se_pide_y_no_bloquea(): void
    {
        $this->colaborador();

        $r = $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('19:00'), $this->hora('21:00'));

        $this->assertSame('solicitada', $r->status);
        $this->assertSame('solo_solicitud', $r->mode);
        $this->assertSame(EspacioBookingService::FUERA_DE_JORNADA, $r->status_reason);

        // No bloquea: otra solicitud a la misma hora entra igual. Quien decide
        // verá las dos y elegirá.
        $otra = $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('19:00'), $this->hora('21:00'));

        $this->assertSame('solicitada', $otra->status);
    }

    /**
     * Lo tangible lo abre alguien presencial; lo virtual lo atiende quien esté
     * en jornada, aunque sea desde casa.
     */
    public function test_lo_virtual_lo_cubre_quien_esta_en_remoto_y_lo_fisico_no(): void
    {
        $this->colaborador(WorkSchedule::REMOTA);

        $vr = $this->espacios()->reservar($this->alguien(), $this->vr, $this->hora('10:00'), $this->hora('12:00'));
        $taller = $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('10:00'), $this->hora('12:00'));

        $this->assertSame('confirmada', $vr->status, 'una sala virtual se atiende desde casa');
        $this->assertSame('solicitada', $taller->status, 'un taller no lo abre nadie desde casa');
    }

    // ------------------------------------------------------------ aprobación

    public function test_aprobar_la_confirma_y_le_programa_la_jornada_a_quien_la_abre(): void
    {
        $quienAbre = $this->colaborador();
        $jefa = $this->colaborador();

        $s = $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('19:00'), $this->hora('21:00'));

        $r = app(ApprovalService::class)->aprobar($s, $quienAbre, $jefa);

        $this->assertSame('confirmada', $r->status);
        $this->assertSame($quienAbre->id, $r->supervisor_id);

        // Abrir fuera de horario es una jornada programada: son las horas
        // extras que la decisión cuesta.
        $this->assertTrue(
            ShiftAssignment::where('user_id', $quienAbre->id)
                ->where('starts_at', '<=', $this->hora('19:00')->utc())
                ->where('ends_at', '>=', $this->hora('21:00')->utc())
                ->exists(),
        );
    }

    public function test_la_bandeja_lista_la_solicitud_del_espacio(): void
    {
        $this->colaborador();
        $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('19:00'), $this->hora('21:00'), 6, [], 'Charla');

        $jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $jefa->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get(Bandeja::getUrl())
            ->assertOk()
            ->assertSee('Taller')
            ->assertSee('espacio')
            ->assertSee('Fuera de la jornada del equipo');
    }

    // ------------------------------------------------------- varios espacios

    public function test_varios_espacios_van_en_una_sola_reserva_y_se_cancelan_juntos(): void
    {
        $this->colaborador();

        $madre = $this->espacios()->reservarVarios(
            $this->alguien(), [$this->taller, $this->vr], $this->hora('10:00'), $this->hora('12:00'), 8,
        );

        $hija = Reservation::where('parent_reservation_id', $madre->id)->firstOrFail();

        $this->assertSame($this->vr->id, $hija->reservable_id);
        $this->assertSame('confirmada', $madre->status);
        $this->assertSame('confirmada', $hija->status);

        app(BookingService::class)->cancelar($madre, 'Se cayó la actividad');

        $this->assertSame('cancelada', $hija->fresh()->status, 'las hijas se van con la madre');
    }

    /** Una decisión para el conjunto: si alguno cae fuera, la reserva entera se pide. */
    public function test_si_un_espacio_cae_fuera_de_jornada_la_reserva_entera_es_solicitud(): void
    {
        $this->colaborador(WorkSchedule::REMOTA);

        // VR lo cubre el remoto; el taller no. La actividad es una: se pide entera.
        $madre = $this->espacios()->reservarVarios(
            $this->alguien(), [$this->vr, $this->taller], $this->hora('10:00'), $this->hora('12:00'),
        );

        $this->assertSame('solicitada', $madre->status);
        $this->assertSame('solicitada', Reservation::where('parent_reservation_id', $madre->id)->value('status'));
    }

    public function test_todo_el_laboratorio_no_se_combina_con_otros(): void
    {
        $this->colaborador();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya incluye/');

        $this->espacios()->reservarVarios(
            $this->alguien(), [Space::todoElLaboratorio(), $this->taller], $this->hora('10:00'), $this->hora('12:00'),
        );
    }

    /** Y desde la página, marcando otro espacio a la vez. */
    public function test_desde_la_pantalla_se_marcan_otros_espacios(): void
    {
        $this->colaborador();
        $persona = $this->alguien();

        $this->actingAs($persona)
            ->get(route('espacios.show', $this->taller))
            ->assertOk()
            ->assertSee('¿También otro espacio, a la misma hora?')
            ->assertSee('Lab. VR');

        $this->actingAs($persona)
            ->post(route('espacios.store', $this->taller), [
                'fecha' => '2026-08-24', 'inicio' => '10:00', 'duracion' => 120,
                'participantes' => 5, 'espacios' => [$this->vr->id],
            ])
            ->assertRedirect(route('home'));

        $this->assertSame(2, Reservation::count());
        $this->assertSame(1, Reservation::whereNotNull('parent_reservation_id')->count());
    }
}
