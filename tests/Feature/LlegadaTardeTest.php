<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Validar la llegada de una reserva levantada (§8).
 *
 * La ventana para registrar la llegada se mide desde la hora de inicio, y eso
 * dejaba a una reserva levantada **nacida fuera de plazo**: se devuelve a las
 * nueve de la noche una que empezaba a las cinco, y el primer intento de
 * escanear la vuelve a marcar como no presentada.
 *
 * Devolver una reserva y que no sirva es peor que no poder devolverla, porque
 * parece que sí se pudo.
 */
class LlegadaTardeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    private function reserva(array $cambios = []): Reservation
    {
        $area = Area::firstOrCreate(['slug' => 'impresion'], ['name' => 'Impresión 3D']);

        $equipo = Asset::create([
            'area_id' => $area->id, 'name' => 'Elegoo Neptune', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);

        $quien = User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        return Reservation::create(array_merge([
            'reservable_type' => Asset::class, 'reservable_id' => $equipo->id,
            'user_id' => $quien->id, 'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->addHour(),
        ], $cambios));
    }

    /** Sin levantar, una reserva de hace cuatro horas ya no admite llegada. */
    public function test_una_reserva_vieja_sin_levantar_sigue_fuera_de_plazo(): void
    {
        $r = $this->reserva();

        $this->expectException(BookingException::class);

        app(AttendanceService::class)->checkIn($r);
    }

    /**
     * Levantada hace un momento, sí.
     *
     * La tolerancia se cuenta desde que volvió a estar en pie, no desde la hora
     * a la que debía haber empezado.
     */
    public function test_una_reserva_levantada_admite_llegada(): void
    {
        $r = $this->reserva(['reinstated_at' => now()->subMinutes(2)]);

        $devuelta = app(AttendanceService::class)->checkIn($r);

        $this->assertSame('en_curso', $devuelta->status);
        $this->assertNotNull($devuelta->checked_in_at);
    }

    /** Pero la tolerancia sigue existiendo: no es un permiso para siempre. */
    public function test_levantada_hace_mucho_vuelve_a_caducar(): void
    {
        $r = $this->reserva([
            'reinstated_at' => now()->subMinutes((int) config('fabos.checkin.tolerancia') + 5),
        ]);

        $this->expectException(BookingException::class);

        app(AttendanceService::class)->checkIn($r);
    }

    /**
     * Y una levantada antes de su hora no adelanta nada.
     *
     * Si alguien devuelve el lunes una reserva del miércoles, la llegada sigue
     * abriéndose a la hora de la reserva.
     */
    public function test_levantarla_antes_de_su_hora_no_adelanta_la_llegada(): void
    {
        $r = $this->reserva([
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
            'reinstated_at' => now(),
        ]);

        $this->expectException(BookingException::class);

        app(AttendanceService::class)->checkIn($r);
    }

    // -------------------------------------------------------------- la cámara

    public function test_la_camara_se_abre_desde_la_cuenta(): void
    {
        $quien = User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->actingAs($quien)
            ->get(route('escaneo.camara'))
            ->assertOk()
            ->assertSee('Apunta al QR del equipo');
    }

    /** Sin sesión no: escanear es un acto de alguien concreto. */
    public function test_la_camara_exige_sesion(): void
    {
        $this->get(route('escaneo.camara'))->assertRedirect();
    }
}
