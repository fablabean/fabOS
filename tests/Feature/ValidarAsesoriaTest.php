<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\AsistenciaDeAsesoria;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién llegó a una asesoría, y quién no (§10).
 *
 * Una asesoría no tiene QR: reserva el tiempo de una persona, no una máquina.
 * La llegada la valida quien atiende, desde su cuenta; si nadie vino, lo dice
 * esa persona; y quien pidió puede decir que no lo atendieron mientras nadie
 * haya validado. El barrido automático de ausencias no las toca: daba por no
 * presentada a gente que estaba sentada al lado del asesor.
 */
class ValidarAsesoriaTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;
    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Lunes 24/08/2026 a las 07:00, quieto: Ana atiende los lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);
        $this->equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $area->id,
            'status' => 'operativo', 'is_reservable' => true,
        ]);

        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $this->ana->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        WorkSchedule::create([
            'user_id' => $this->ana->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
        AssetAdvisor::create(['user_id' => $this->ana->id, 'asset_id' => $this->equipo->id, 'es_responsable' => true]);
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

    /** Una asesoría de 10:00 a 10:45 con Ana, pedida por alguien. */
    private function asesoria(?User $quien = null): Reservation
    {
        return app(AsesoriaService::class)->agendar(
            $quien ?? $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('10:45'),
        );
    }

    private function asistencia(): AsistenciaDeAsesoria
    {
        return app(AsistenciaDeAsesoria::class);
    }

    // ------------------------------------------------------- quien atiende

    public function test_quien_atiende_valida_la_llegada(): void
    {
        $a = $this->asesoria();

        $r = $this->asistencia()->llego($a, $this->ana, $this->hora('10:44'));

        $this->assertSame('en_curso', $r->status);
        // Validada a las 10:44, la llegada es a las 10:00: validar es decir
        // «vino a su hora», no «pulsé el botón ahora».
        $this->assertTrue($r->checked_in_at->equalTo($a->starts_at));
    }

    /** Validada al día siguiente, la llegada queda a la hora de la asesoría. */
    public function test_se_puede_validar_tarde_y_la_llegada_queda_a_su_hora(): void
    {
        $a = $this->asesoria();

        $r = $this->asistencia()->llego($a, $this->ana, $this->hora('10:00')->addDay()->setTime(15, 0));

        $this->assertSame('completada', $r->status);
        $this->assertTrue($r->checked_in_at->equalTo($a->starts_at), 'la persona vino a las diez, no al día siguiente');
    }

    public function test_quien_pidio_no_puede_validar_su_propia_llegada(): void
    {
        $quien = $this->alguien();
        $a = $this->asesoria($quien);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/quien la atiende/');

        $this->asistencia()->llego($a, $quien, $this->hora('10:05'));
    }

    public function test_quien_atiende_marca_que_no_vino_pasada_la_tolerancia(): void
    {
        $a = $this->asesoria();

        // Dentro de la tolerancia todavía puede llegar.
        try {
            $this->asistencia()->noVino($a, $this->ana, $this->hora('10:10'));
            $this->fail('dentro de la tolerancia no se marca ausente');
        } catch (BookingException $e) {
            $this->assertStringContainsString('tolerancia', $e->getMessage());
        }

        $r = $this->asistencia()->noVino($a, $this->ana, $this->hora('10:30'));

        $this->assertSame('no_show', $r->status);
        $this->assertStringContainsString('Ana', $r->status_reason);
    }

    // --------------------------------------------------------- quien pidio

    public function test_quien_pidio_dice_que_no_lo_atendieron(): void
    {
        $quien = $this->alguien();
        $a = $this->asesoria($quien);

        $r = $this->asistencia()->noMeAtendieron($a, $quien, $this->hora('10:30'));

        $this->assertSame('cancelada', $r->status);
        $this->assertStringContainsString('No lo atendieron', $r->status_reason);
    }

    /** Si quien atiende ya validó, la queja no entra por aquí: eso se habla con la coordinación. */
    public function test_validada_ya_no_se_puede_decir_que_no_lo_atendieron(): void
    {
        $quien = $this->alguien();
        $a = $this->asesoria($quien);
        $this->asistencia()->llego($a, $this->ana, $this->hora('10:05'));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya validó/');

        $this->asistencia()->noMeAtendieron($a->fresh(), $quien, $this->hora('10:30'));
    }

    // ------------------------------------------------------------ el barrido

    /**
     * El barrido de ausencias no toca asesorías.
     *
     * Pasó de verdad: la persona vino, quien atendía no validó, y a los
     * veinte minutos el sistema la dio por no presentada.
     */
    public function test_el_barrido_de_ausencias_no_cancela_una_asesoria(): void
    {
        $a = $this->asesoria();

        $this->travelTo($this->hora('11:00'));
        app(AttendanceService::class)->liberarAusencias();

        $this->assertSame('confirmada', $a->fresh()->status, 'sin QR, la ausencia la marca una persona');
    }

    // ---------------------------------------------------------- la pantalla

    public function test_mi_cuenta_ofrece_validar_a_quien_atiende_y_reportar_a_quien_pidio(): void
    {
        $quien = $this->alguien();
        $a = $this->asesoria($quien);

        $this->travelTo($this->hora('10:30'));

        $this->actingAs($this->ana)->get(route('home'))
            ->assertOk()
            ->assertSee('Llegó')
            ->assertSee('No vino');

        $this->actingAs($quien)->get(route('home'))
            ->assertOk()
            ->assertSee('No me atendieron');

        // Y por la ruta: quien atiende valida, y a quien pidió le cambia el estado.
        $this->actingAs($this->ana)->post(route('asesoria.llego', $a))->assertRedirect(route('home'));

        $this->assertNotNull($a->fresh()->checked_in_at);

        $this->actingAs($quien)->get(route('home'))->assertSee('Atendida');
    }

    /** Validar por la ruta siendo otra persona no pasa. */
    public function test_por_la_ruta_solo_valida_quien_atiende(): void
    {
        $a = $this->asesoria();
        $this->travelTo($this->hora('10:05'));

        $this->actingAs($this->alguien())
            ->post(route('asesoria.llego', $a))
            ->assertSessionHasErrors('asesoria');

        $this->assertNull($a->fresh()->checked_in_at);
    }
}
