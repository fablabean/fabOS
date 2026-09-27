<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Llegada y salida de una reserva (§10). */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function usuario(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function activo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id, 'name' => 'Equipo ' . uniqid(),
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 120, 'max_minutes' => 720,
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    /** Reserva que empieza dentro de N minutos (negativo = ya empezó). */
    private function reserva(User $u, Asset $a, int $empiezaEnMinutos, string $estado = 'confirmada'): Reservation
    {
        $inicio = now()->addMinutes($empiezaEnMinutos);

        return Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $a->id,
            'user_id' => $u->id, 'status' => $estado, 'mode' => 'directa',
            'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHours(2),
        ]);
    }

    // ----------------------------------------------------------------- llegada

    public function test_registra_la_llegada_dentro_de_la_ventana(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), 5);

        $r = $this->servicio()->checkIn($r);

        $this->assertSame('en_curso', $r->status);
        $this->assertNotNull($r->checked_in_at);
    }

    public function test_permite_llegar_un_poco_antes(): void
    {
        $u = $this->usuario();
        // La ventana abre 15 minutos antes; a 10 ya se puede.
        $r = $this->reserva($u, $this->activo(), 10);

        $this->assertSame('en_curso', $this->servicio()->checkIn($r)->status);
    }

    public function test_no_deja_llegar_demasiado_temprano(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), 120);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/temprano/');

        $this->servicio()->checkIn($r);
    }

    public function test_pasada_la_tolerancia_libera_el_equipo(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), -45);      // empezó hace 45 min

        try {
            $this->servicio()->checkIn($r);
            $this->fail('debía rechazar la llegada tardía');
        } catch (BookingException $e) {
            $this->assertStringContainsString('liberó', $e->getMessage());
        }

        // El equipo queda libre de inmediato, no en un proceso nocturno.
        $this->assertSame('no_show', $r->refresh()->status);
    }

    public function test_no_deja_llegar_dos_veces(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), 5);
        $this->servicio()->checkIn($r);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/Ya habías/');

        $this->servicio()->checkIn($r->refresh());
    }

    public function test_una_reserva_cancelada_no_admite_llegada(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), 5, 'cancelada');

        $this->expectException(BookingException::class);
        $this->servicio()->checkIn($r);
    }

    // ------------------------------------------------------------------ salida

    public function test_el_checkout_cierra_y_registra_el_uso_real(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), 0);

        $this->servicio()->checkIn($r);
        $r = $this->servicio()->checkOut($r->refresh());

        $this->assertSame('completada', $r->status);
        $this->assertNotNull($r->checked_out_at);
        $this->assertNotNull($this->servicio()->minutosReales($r));
    }

    public function test_no_se_puede_cerrar_lo_que_no_empezo(): void
    {
        $u = $this->usuario();
        $r = $this->reserva($u, $this->activo(), 5);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/no está en curso/');

        $this->servicio()->checkOut($r);
    }

    public function test_al_cerrar_libera_tambien_al_acompanante(): void
    {
        $u = $this->usuario();
        $colaborador = $this->usuario();
        $a = $this->activo();

        $r = $this->reserva($u, $a, 0);
        $r->update(['supervisor_id' => $colaborador->id]);

        // El bloque reservado del colaborador, en paralelo.
        $suya = Reservation::create([
            'parent_reservation_id' => $r->id,
            'reservable_type' => User::class, 'reservable_id' => $colaborador->id,
            'user_id' => $u->id, 'status' => 'confirmada', 'mode' => 'con_aprobacion',
            'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at,
        ]);

        $this->servicio()->checkIn($r);
        $this->servicio()->checkOut($r->refresh());

        // Si no se cerrara, el colaborador seguiría ocupado sin estarlo.
        $this->assertSame('completada', $suya->refresh()->status);
    }

    // ------------------------------------------------------------- red de red

    public function test_el_barrido_libera_las_ausencias_pendientes(): void
    {
        $u = $this->usuario();
        $this->reserva($u, $this->activo(), -60);        // nadie llegó
        $this->reserva($u, $this->activo(), 30);         // aún no empieza

        $liberadas = $this->servicio()->liberarAusencias();

        $this->assertSame(1, $liberadas, 'solo la vencida');
        $this->assertSame(1, Reservation::where('status', 'no_show')->count());
    }

    /**
     * Una máquina en curso que nadie cerró espera un día y se cierra sola,
     * a la hora en que terminaba, con la nota.
     */
    public function test_la_maquina_sin_salida_se_cierra_al_dia_siguiente(): void
    {
        $r = $this->reserva($this->usuario(), $this->activo(), -60, 'en_curso');
        $r->update(['checked_in_at' => $r->starts_at->copy()->addMinutes(30)]);

        // Terminó hace un rato: todavía se puede cerrar a mano.
        $this->travel(2)->hours();
        $this->servicio()->liberarAusencias();
        $this->assertSame('en_curso', $r->fresh()->status);

        $this->travel(AttendanceService::HORAS_PARA_CERRAR_MAQUINA)->hours();
        $this->servicio()->liberarAusencias();

        $this->assertSame('completada', $r->fresh()->status);
        $this->assertSame(AttendanceService::SIN_SALIDA, $r->fresh()->status_reason);
        $this->assertTrue($r->fresh()->checked_out_at->equalTo($r->fresh()->ends_at));
    }

    /** A mano: con la hora real, y sin cobro si así se decide. */
    public function test_se_cierra_a_mano_sin_cobro(): void
    {
        $r = $this->reserva($this->usuario(), $this->activo(), -60, 'en_curso');
        $r->update(['checked_in_at' => $r->starts_at]);
        $quien = $this->usuario();

        $this->servicio()->cerrarAMano($r->fresh(), now()->subMinutes(10), cobrar: false, quien: $quien);

        $this->assertSame('completada', $r->fresh()->status);
        $this->assertSame(0, (int) $r->fresh()->actual_cost_minor);
        $this->assertStringContainsString('sin cobro', $r->fresh()->status_reason);
        $this->assertStringContainsString($quien->name, $r->fresh()->status_reason);
    }

    public function test_no_se_cierra_a_mano_con_salida_en_el_futuro(): void
    {
        $r = $this->reserva($this->usuario(), $this->activo(), -60, 'en_curso');

        $this->expectException(BookingException::class);
        $this->servicio()->cerrarAMano($r, now()->addHour(), cobrar: true, quien: $this->usuario());
    }

    public function test_encuentra_la_reserva_al_escanear_el_equipo(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $r = $this->reserva($u, $a, 10);

        $this->assertSame($r->id, $this->servicio()->reservaEnCurso($u, $a)?->id);
        $this->assertNull($this->servicio()->reservaEnCurso($this->usuario(), $a), 'de otra persona, no');
    }
}
