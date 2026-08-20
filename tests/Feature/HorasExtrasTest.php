<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Staffing\OvertimeService;
use App\Services\Staffing\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tope de horas extras: 12 semanales, 48 mensuales (§5).
 *
 * Lo que se prueba es que el control sea PREVENTIVO —que impida programar—,
 * no que informe después.
 */
class HorasExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function persona(): User
    {
        return User::create([
            'name' => 'Colaborador ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function turnos(): ShiftService
    {
        return app(ShiftService::class);
    }

    private function extras(): OvertimeService
    {
        return app(OvertimeService::class);
    }

    /** Un sábado a las 8:00, con la duración pedida. */
    private function franja(int $horas, int $desplazarDias = 0): array
    {
        $tz = config('fabos.lab.timezone');
        $d = Carbon::parse('2026-09-05 08:00', $tz)->addDays($desplazarDias);

        return [$d, $d->copy()->addHours($horas)];
    }

    public function test_acumula_las_horas_programadas(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(4);

        $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->assertSame(240, $this->extras()->minutosSemana($u, $d));
        $this->assertSame(12 * 60 - 240, $this->extras()->disponibleSemana($u, $d));
    }

    public function test_lo_compensado_con_tiempo_no_consume_el_tope(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(4);

        $this->turnos()->programar($u, $d, $h, 'Evento', cuentaComoExtra: false);

        $this->assertSame(0, $this->extras()->minutosSemana($u, $d));
    }

    public function test_impide_pasarse_del_tope_semanal(): void
    {
        $u = $this->persona();

        // 10 horas ya programadas esa semana.
        [$d1, $h1] = $this->franja(10, -1);
        $this->turnos()->programar($u, $d1, $h1, 'Curso');

        // Pedir 4 más deja 14: por encima de las 12 permitidas.
        [$d2, $h2] = $this->franja(4);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/extras disponibles esta semana/');

        $this->turnos()->programar($u, $d2, $h2, 'Acompañamiento');
    }

    public function test_deja_programar_justo_hasta_el_tope(): void
    {
        $u = $this->persona();

        [$d1, $h1] = $this->franja(8, -1);
        $this->turnos()->programar($u, $d1, $h1, 'Curso');

        [$d2, $h2] = $this->franja(4);
        $jornada = $this->turnos()->programar($u, $d2, $h2, 'Acompañamiento');

        $this->assertNotNull($jornada->id);
        $this->assertSame(0, $this->extras()->disponibleSemana($u, $d2), 'queda en el límite exacto');
    }

    public function test_el_tope_mensual_manda_aunque_la_semana_lo_permita(): void
    {
        $u = $this->persona();
        $tz = config('fabos.lab.timezone');

        // 48 horas repartidas en cuatro semanas del mismo mes.
        foreach ([1, 8, 15, 22] as $i => $dia) {
            $d = Carbon::parse("2026-09-{$dia} 08:00", $tz);
            $this->turnos()->programar($u, $d, $d->copy()->addHours(12), 'Turno ' . $i);
        }

        $this->assertSame(0, $this->extras()->disponibleMes($u, Carbon::parse('2026-09-10', $tz)));

        // La semana del 29 está libre, pero el mes ya no da.
        $d = Carbon::parse('2026-09-29 08:00', $tz);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/este mes/');

        $this->turnos()->programar($u, $d, $d->copy()->addHours(2), 'Otro');
    }

    public function test_no_permite_dos_jornadas_cruzadas(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(4);
        $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/se cruza/');

        $this->turnos()->programar($u, $d->copy()->addHour(), $h->copy()->addHour(), 'Otra cosa');
    }

    public function test_ordena_los_candidatos_por_quien_menos_extras_lleva(): void
    {
        $cargado = $this->persona();
        $libre   = $this->persona();

        [$d1, $h1] = $this->franja(10, -1);
        $this->turnos()->programar($cargado, $d1, $h1, 'Curso');

        [$d, $h] = $this->franja(4);
        $orden = $this->extras()->ordenarPorCarga(collect([$cargado, $libre]), $d, $h);

        // Primero el que menos lleva: así no siempre cae en la misma persona.
        $this->assertSame($libre->id, $orden->first()['persona']->id);
        $this->assertTrue($orden->first()['puede']);

        $this->assertSame($cargado->id, $orden->last()['persona']->id);
        $this->assertFalse($orden->last()['puede'], 'ya no le cabe');
        $this->assertNotNull($orden->last()['motivo']);
    }

    public function test_registra_la_aceptacion_y_el_conflicto(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(3);
        $j = $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->assertNotNull($this->turnos()->aceptar($j)->accepted_at);

        $j2 = $this->turnos()->reportarConflicto($j, 'Tengo cita médica');
        $this->assertSame('Tengo cita médica', $j2->conflict_note);
    }
}
