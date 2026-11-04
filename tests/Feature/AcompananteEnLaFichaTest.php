<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cambiar en la ficha a quien supervisa mueve su tiempo apartado: quien se
 * quita queda libre, y el nuevo queda ocupado.
 */
class AcompananteEnLaFichaTest extends TestCase
{
    use RefreshDatabase;

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-11-05 ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function conAcompanante(User $quien): Reservation
    {
        $robot = Asset::create(['name' => 'Robot', 'slug' => 'robot', 'status' => 'operativo', 'is_reservable' => true]);
        $pide = User::create(['name' => 'Pide', 'email' => 'pide@test.co', 'status' => 'activo']);

        $madre = Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $robot->id, 'user_id' => $pide->id,
            'supervisor_id' => $quien->id, 'status' => 'confirmada', 'mode' => 'con_aprobacion',
            'starts_at' => $this->hora('07:00'), 'ends_at' => $this->hora('16:00'), 'purpose' => 'Evento',
        ]);
        Reservation::create([
            'parent_reservation_id' => $madre->id, 'reservable_type' => User::class, 'reservable_id' => $quien->id,
            'user_id' => $pide->id, 'status' => 'confirmada', 'mode' => 'con_aprobacion',
            'starts_at' => $this->hora('07:00'), 'ends_at' => $this->hora('16:00'), 'purpose' => 'Acompañamiento en Robot',
        ]);

        return $madre;
    }

    public function test_otro_supervisor_se_lleva_el_tiempo_apartado(): void
    {
        $erick = User::create(['name' => 'Erick', 'email' => 'erick@lab.co', 'status' => 'activo']);
        $jhon = User::create(['name' => 'Jhonatan', 'email' => 'jhon@lab.co', 'status' => 'activo']);
        $madre = $this->conAcompanante($erick);
        $reservas = app(BookingService::class);

        $this->assertFalse($reservas->personaLibre($erick, $this->hora('12:30'), $this->hora('13:00')));

        $madre->update(['supervisor_id' => $jhon->id]);

        $this->assertTrue($reservas->personaLibre($erick, $this->hora('12:30'), $this->hora('13:00')));
        $this->assertFalse($reservas->personaLibre($jhon, $this->hora('12:30'), $this->hora('13:00')));
    }

    public function test_sin_supervisor_el_tiempo_se_suelta(): void
    {
        $erick = User::create(['name' => 'Erick', 'email' => 'erick@lab.co', 'status' => 'activo']);
        $madre = $this->conAcompanante($erick);

        $madre->update(['supervisor_id' => null]);

        $this->assertTrue(app(BookingService::class)->personaLibre($erick, $this->hora('12:30'), $this->hora('13:00')));
        $this->assertSame('cancelada', Reservation::where('parent_reservation_id', $madre->id)->value('status'));
    }

    public function test_si_el_nuevo_ya_estaba_ocupado_se_suelta_y_se_dice(): void
    {
        $erick = User::create(['name' => 'Erick', 'email' => 'erick@lab.co', 'status' => 'activo']);
        $jhon = User::create(['name' => 'Jhonatan', 'email' => 'jhon@lab.co', 'status' => 'activo']);
        $madre = $this->conAcompanante($erick);
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $jhon->id, 'user_id' => $erick->id,
            'status' => 'confirmada', 'mode' => 'asesoria', 'starts_at' => $this->hora('10:00'), 'ends_at' => $this->hora('11:00'),
        ]);

        $madre->update(['supervisor_id' => $jhon->id]);

        $bloque = Reservation::where('parent_reservation_id', $madre->id)->first();
        $this->assertSame('cancelada', $bloque->status);
        $this->assertStringContainsString('Jhonatan ya tenía algo a esa hora', $bloque->status_reason);
        $this->assertTrue(app(BookingService::class)->personaLibre($erick, $this->hora('12:30'), $this->hora('13:00')));
    }
}
