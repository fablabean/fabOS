<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use App\Services\Booking\EspacioBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Espacios que se comparten por puestos (§7).
 *
 * Una reserva de espacio tomaba la sala completa aunque fuera para una
 * persona: en la sala de computo, alguien reservaba un puesto de tres a
 * cuatro y los otros diecinueve quedaban «ocupados». Ahora el espacio dice si
 * se comparte, y si se comparte, se llena por aforo.
 */
class EspacioCompartidoTest extends TestCase
{
    use RefreshDatabase;

    private Space $computo;

    private Space $taller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->computo = Space::create([
            'slug' => 'computo', 'name' => 'Lab. Cómputo', 'capacity' => 4,
            'is_reservable' => true, 'shares_seats' => true,
        ]);

        $this->taller = Space::create([
            'slug' => 'taller', 'name' => 'Taller', 'capacity' => 12, 'is_reservable' => true,
        ]);

        // Alguien en jornada presencial: sin cobertura el laboratorio no atiende.
        $colaborador = User::factory()->create(['status' => 'activo']);
        WorkSchedule::create([
            'user_id' => $colaborador->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);
    }

    /** Un lunes por venir, para no depender de la hora en que se ejecute. */
    private function hora(string $hhmm): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))
            ->next(Carbon::MONDAY)
            ->setTimeFromTimeString($hhmm);
    }

    private function reservar(Space $espacio, string $desde, string $hasta, int $personas = 1): Reservation
    {
        return app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']),
            $espacio, $this->hora($desde), $this->hora($hasta), participantes: $personas,
        );
    }

    /** Lo que pasó: una persona a las tres no cierra la sala para la siguiente. */
    public function test_dos_personas_caben_a_la_misma_hora(): void
    {
        $primera = $this->reservar($this->computo, '15:00', '16:00');
        $segunda = $this->reservar($this->computo, '15:00', '16:00');

        $this->assertSame('confirmada', $primera->status);
        $this->assertSame('confirmada', $segunda->status);
        $this->assertTrue($segunda->shares_seats);

        $this->assertSame(2, app(EspacioBookingService::class)->puestosLibres($this->computo, $this->hora('15:00'), $this->hora('16:00')));
    }

    /** Pero la sala se llena por aforo, y el mensaje dice cuántos quedan. */
    public function test_no_caben_mas_puestos_que_el_aforo(): void
    {
        $this->reservar($this->computo, '15:00', '16:00', 3);

        try {
            $this->reservar($this->computo, '15:30', '16:30', 2);
            $this->fail('no cabían');
        } catch (BookingException $e) {
            $this->assertStringContainsString('queda 1 puesto de 4', $e->getMessage());
            $this->assertStringContainsString('pediste 2', $e->getMessage());
        }

        // Con una sí.
        $this->assertSame('confirmada', $this->reservar($this->computo, '15:30', '16:30', 1)->status);
    }

    /** Fuera del rato ocupado, todo vuelve a estar libre. */
    public function test_los_puestos_se_cuentan_solo_donde_se_pisan(): void
    {
        $this->reservar($this->computo, '15:00', '16:00', 4);

        $this->assertSame(0, app(EspacioBookingService::class)->puestosLibres($this->computo, $this->hora('15:00'), $this->hora('16:00')));
        $this->assertSame(4, app(EspacioBookingService::class)->puestosLibres($this->computo, $this->hora('16:00'), $this->hora('17:00')));
        $this->assertSame('confirmada', $this->reservar($this->computo, '16:00', '17:00', 4)->status);
    }

    /** Lo cancelado suelta sus puestos. */
    public function test_cancelar_suelta_los_puestos(): void
    {
        $r = $this->reservar($this->computo, '15:00', '16:00', 4);
        $r->update(['status' => 'cancelada']);

        $this->assertSame(4, app(EspacioBookingService::class)->puestosLibres($this->computo, $this->hora('15:00'), $this->hora('16:00')));
    }

    /** El taller sigue siendo exclusivo: una actividad no convive con otra. */
    public function test_un_espacio_sin_compartir_sigue_cerrandose_con_la_primera(): void
    {
        $this->reservar($this->taller, '15:00', '16:00');

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/Alguien tomó ese espacio/');

        $this->reservar($this->taller, '15:00', '16:00');
    }

    /** Y una reserva anterior a que la sala se compartiera sigue ocupando lo suyo. */
    public function test_lo_reservado_antes_de_compartir_cuenta_igual(): void
    {
        $this->computo->update(['shares_seats' => false]);
        $vieja = $this->reservar($this->computo, '15:00', '16:00', 3);
        $this->assertFalse($vieja->shares_seats);

        $this->computo->update(['shares_seats' => true]);
        $computo = $this->computo->fresh();

        $this->assertSame(1, app(EspacioBookingService::class)->puestosLibres($computo, $this->hora('15:00'), $this->hora('16:00')));
        $this->assertSame('confirmada', app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']), $computo, $this->hora('15:00'), $this->hora('16:00'),
        )->status);
    }

    /** El sitio público lo dice, y el error va junto al número de personas. */
    public function test_la_pantalla_lo_dice_y_el_error_va_al_campo(): void
    {
        $this->reservar($this->computo, '15:00', '16:00', 4);
        $quien = User::factory()->create(['status' => 'activo']);

        $this->actingAs($quien)
            ->get(route('espacios.show', $this->computo))
            ->assertOk()
            ->assertSee('se comparte por puestos');

        $this->actingAs($quien)
            ->post(route('espacios.store', $this->computo), [
                'fecha'         => $this->hora('15:00')->toDateString(),
                'inicio'        => '15:00',
                'duracion'      => 60,
                'participantes' => 1,
            ])
            ->assertSessionHasErrors('participantes');
    }
}
