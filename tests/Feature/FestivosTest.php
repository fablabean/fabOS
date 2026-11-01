<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Bloqueo;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\EspacioBookingService;
use App\Services\Booking\Festivos;
use App\Support\BloqueoDeReservas;
use App\Support\DiasHabiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Los festivos de Colombia cierran las reservas. */
class FestivosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        // Domingo 1 de noviembre de 2026: mañana es el lunes de Todos los Santos.
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00', config('fabos.lab.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lunes(string $hora): Carbon
    {
        return Carbon::parse('2026-11-02 ' . $hora, config('fabos.lab.timezone'));
    }

    public function test_se_conocen_los_festivos_y_su_nombre(): void
    {
        $tz = config('fabos.lab.timezone');

        $this->assertSame('Todos los Santos', DiasHabiles::festivo($this->lunes('08:00')));
        $this->assertSame('Corpus Christi', DiasHabiles::festivo(Carbon::parse('2027-05-31 12:00', $tz)));
        $this->assertNull(DiasHabiles::festivo(Carbon::parse('2026-11-03 12:00', $tz)));
        $this->assertSame(
            ['2026-11-02' => 'Todos los Santos', '2026-11-16' => 'Independencia de Cartagena'],
            DiasHabiles::festivosEntre(now(), Carbon::parse('2026-11-30', $tz)),
        );
    }

    public function test_el_festivo_queda_bloqueado_y_no_se_reserva(): void
    {
        $nuevos = app(Festivos::class)->bloquear(20);

        $this->assertCount(2, $nuevos, 'El 2 y el 16 de noviembre.');
        $this->assertTrue(BloqueoDeReservas::cubre($this->lunes('10:00'), $this->lunes('11:00')));
        $this->assertFalse(BloqueoDeReservas::cubre($this->lunes('10:00')->addDay(), $this->lunes('11:00')->addDay()));
        $this->assertStringContainsString('Todos los Santos', BloqueoDeReservas::textoDe(BloqueoDeReservas::elQueCubre($this->lunes('10:00'))));

        $this->expectException(BookingException::class);
        app(EspacioBookingService::class)->reservar(new User(['name' => 'Ana']), new Space, $this->lunes('10:00'), $this->lunes('11:00'));
    }

    public function test_no_se_duplica_y_el_levantado_no_vuelve(): void
    {
        app(Festivos::class)->bloquear(20);
        $this->assertCount(0, app(Festivos::class)->bloquear(20));

        // Ese festivo sí se abre: se levanta, y el barrido del día siguiente no lo repone.
        Bloqueo::where('festivo', '2026-11-02')->first()->update(['levantado_at' => now()]);
        $this->artisan('fabos:bloquear-festivos', ['--dias' => 20])->assertSuccessful();

        $this->assertSame(1, Bloqueo::where('festivo', '2026-11-02')->count());
        $this->assertFalse(BloqueoDeReservas::cubre($this->lunes('10:00'), $this->lunes('11:00')));
    }

    /**
     * Las que se hicieron antes de que el sistema conociera los festivos: se
     * cancelan y a cada quien se le escribe una vez. Producir no se toca.
     */
    public function test_las_reservas_hechas_en_el_festivo_se_cancelan_y_se_avisa(): void
    {
        $sala = Space::create(['slug' => 'sala', 'name' => 'Sala de corte', 'type' => 'fisico', 'is_reservable' => true]);
        $ana = User::create(['name' => 'Ana Torres', 'email' => 'ana@test.co', 'status' => 'activo']);

        $reserva = fn (array $mas = []) => Reservation::create($mas + [
            'reservable_type' => Space::class, 'reservable_id' => $sala->id,
            'user_id' => $ana->id, 'mode' => 'directa', 'status' => 'confirmada',
            'starts_at' => $this->lunes('10:00'), 'ends_at' => $this->lunes('12:00'),
        ]);

        $enFestivo = $reserva();
        $martes = $reserva(['starts_at' => $this->lunes('10:00')->addDay(), 'ends_at' => $this->lunes('12:00')->addDay()]);
        $produccion = $reserva(['starts_at' => $this->lunes('14:00'), 'ends_at' => $this->lunes('16:00'), 'is_production' => true]);

        app(Festivos::class)->bloquear(20);

        $this->artisan('fabos:cancelar-reservas-en-festivo', ['--ver' => true])->assertSuccessful();
        $this->assertSame('confirmada', $enFestivo->fresh()->status, 'Con --ver no se toca nada.');

        $this->artisan('fabos:cancelar-reservas-en-festivo')->assertSuccessful();

        $this->assertSame('cancelada', $enFestivo->fresh()->status);
        $this->assertSame('confirmada', $martes->fresh()->status);
        $this->assertSame('confirmada', $produccion->fresh()->status);

        $aviso = NotificationLog::where('key', 'reserva.cancelada_por_festivo')->sole();
        $this->assertSame($ana->id, $aviso->user_id);

        // Correrlo otra vez no vuelve a escribir.
        $this->artisan('fabos:cancelar-reservas-en-festivo')->assertSuccessful();
        $this->assertSame(1, NotificationLog::where('key', 'reserva.cancelada_por_festivo')->count());
    }
}
