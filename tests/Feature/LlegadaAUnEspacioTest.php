<?php

namespace Tests\Feature;

use App\Filament\Pages\Bandeja;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\EspacioBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Validar la llegada a un espacio, y a todo lo que se tomó dentro (§7, §10).
 *
 * Una sala no tiene QR: el código lo llevan los equipos, no las paredes. Y sin
 * embargo el barrido de ausencias las marcaba «no se presentó» a los quince
 * minutos, o sea castigaba por algo que no se podía hacer.
 *
 * Y la llegada se guardaba en una fila. Quien reserva una sala y toma dos
 * herramientas dentro llega UNA vez y son tres reservas: validar la sala
 * dejaba las herramientas esperando a alguien que ya estaba dentro.
 */
class LlegadaAUnEspacioTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    private Asset $multimetro;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        // Lunes, dentro de la jornada: las reservas se confirman solas.
        $this->travelTo(Carbon::parse('2026-08-24 09:00', config('fabos.lab.timezone')));

        $area = Area::create(['slug' => 'electronica', 'name' => 'Electrónica']);

        $this->sala = Space::create([
            'slug' => 'lab-electronica', 'name' => 'Lab electrónica', 'type' => 'fisico',
            'capacity' => 8, 'is_reservable' => true,
        ]);

        $this->multimetro = Asset::create([
            'area_id' => $area->id, 'name' => 'Multímetro 1', 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'space_id' => $this->sala->id,
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        // Alguien del equipo en jornada, para que se confirme sola.
        $u = User::create(['name' => 'Colaborador', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /** @param list<int> $herramientas */
    private function reservar(array $herramientas = []): Reservation
    {
        $desde = Carbon::parse('2026-08-24 10:00', config('fabos.lab.timezone'));

        return app(EspacioBookingService::class)->reservar(
            $this->alguien(), $this->sala, $desde, $desde->copy()->addHours(2), 1, $herramientas,
        );
    }

    private function asistencia(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    // ------------------------------------------------ validar el combo entero

    public function test_validar_la_sala_vale_por_lo_que_se_tomo_dentro(): void
    {
        $sala = $this->reservar([$this->multimetro->id]);
        $herramienta = Reservation::where('parent_reservation_id', $sala->id)->firstOrFail();

        $this->travelTo(Carbon::parse('2026-08-24 10:05', config('fabos.lab.timezone')));

        $this->asistencia()->checkIn($sala);

        $this->assertSame('en_curso', $sala->fresh()->status);
        $this->assertSame('en_curso', $herramienta->fresh()->status, 'la herramienta entró con la sala');
        $this->assertNotNull($herramienta->fresh()->checked_in_at);
    }

    /**
     * Y al revés: la herramienta es muchas veces la única puerta, porque la
     * sala no tiene QR que escanear.
     */
    public function test_validar_la_herramienta_vale_por_la_sala(): void
    {
        $sala = $this->reservar([$this->multimetro->id]);
        $herramienta = Reservation::where('parent_reservation_id', $sala->id)->firstOrFail();

        $this->travelTo(Carbon::parse('2026-08-24 10:05', config('fabos.lab.timezone')));

        $this->asistencia()->checkIn($herramienta);

        $this->assertSame('en_curso', $sala->fresh()->status, 'la sala se validó con la herramienta');
        $this->assertNotNull($sala->fresh()->checked_in_at);
    }

    /** Anotarla a mano desde el panel arrastra igual. */
    public function test_anotar_la_llegada_a_mano_arrastra_a_las_herramientas(): void
    {
        $sala = $this->reservar([$this->multimetro->id]);
        $herramienta = Reservation::where('parent_reservation_id', $sala->id)->firstOrFail();

        $this->travelTo(Carbon::parse('2026-08-24 10:30', config('fabos.lab.timezone')));

        $quien = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $this->asistencia()->llegoATiempo($sala, $quien);

        $this->assertNotNull($herramienta->fresh()->checked_in_at);
        $this->assertSame('en_curso', $herramienta->fresh()->status);
    }

    // ------------------------------------------ la sala sola no se penaliza

    public function test_una_sala_sola_no_se_marca_como_no_presentada(): void
    {
        $sala = $this->reservar();

        // Muy pasada la tolerancia, y la sala todavía corriendo.
        $this->travelTo(Carbon::parse('2026-08-24 11:00', config('fabos.lab.timezone')));
        $this->asistencia()->liberarAusencias();

        $this->assertSame('confirmada', $sala->fresh()->status, 'no hay QR que escanear en una pared');

        // Cuando su hora pasa, se cierra sin mancha.
        $this->travelTo(Carbon::parse('2026-08-24 12:30', config('fabos.lab.timezone')));
        $this->asistencia()->liberarAusencias();

        $this->assertSame('completada', $sala->fresh()->status);
        $this->assertStringContainsString('no se valida al llegar', $sala->fresh()->status_reason);
    }

    /**
     * Con herramientas dentro sí se mira: una herramienta apartada que nadie
     * usó es una herramienta perdida para quien la necesitaba.
     */
    public function test_una_sala_con_herramientas_si_se_marca(): void
    {
        $sala = $this->reservar([$this->multimetro->id]);
        $herramienta = Reservation::where('parent_reservation_id', $sala->id)->firstOrFail();

        $this->travelTo(Carbon::parse('2026-08-24 11:00', config('fabos.lab.timezone')));
        $this->asistencia()->liberarAusencias();

        $this->assertSame('no_show', $sala->fresh()->status);
        $this->assertSame('no_show', $herramienta->fresh()->status);
    }

    // ------------------------------------------------------ volver a Reservas

    public function test_desde_solicitudes_se_vuelve_a_reservas(): void
    {
        $migas = app(Bandeja::class)->getBreadcrumbs();

        $this->assertContains('Reservas', $migas, 'el rastro de vuelta a donde cuelga');
        $this->assertArrayHasKey(
            \App\Filament\Resources\Reservations\ReservationResource::getUrl(),
            $migas,
        );
    }
}
