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

    /** Dos horas cada día, desde una fecha, tantos días como se pida. */
    private function dosHorasDiarias(User $u, string $desde, int $dias): void
    {
        $tz = config('fabos.lab.timezone');

        for ($i = 0; $i < $dias; $i++) {
            $d = Carbon::parse($desde . ' 08:00', $tz)->addDays($i);
            $this->turnos()->programar($u, $d, $d->copy()->addHours(2), 'Turno ' . $i);
        }
    }

    public function test_acumula_las_horas_programadas(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(2);

        $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->assertSame(120, $this->extras()->minutosSemana($u, $d));
        $this->assertSame(120, $this->extras()->minutosDia($u, $d));
        $this->assertSame(12 * 60 - 120, $this->extras()->disponibleSemana($u, $d));
    }

    public function test_lo_compensado_con_tiempo_no_consume_el_tope(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(4);

        $this->turnos()->programar($u, $d, $h, 'Evento', cuentaComoExtra: false);

        $this->assertSame(0, $this->extras()->minutosSemana($u, $d));
    }

    /** Dos horas extras al día, como máximo (Ley 50 de 1990, art. 22). */
    public function test_impide_pasarse_del_tope_diario(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(2);
        $this->turnos()->programar($u, $d, $h, 'Mañana');

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ese día/');

        // Por la tarde del mismo día: ya no cabe.
        $this->turnos()->programar($u, $d->copy()->addHours(6), $d->copy()->addHours(7), 'Tarde');
    }

    public function test_una_jornada_de_mas_de_dos_horas_no_cuenta_como_extra(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(4);

        $this->expectException(BookingException::class);
        $this->turnos()->programar($u, $d, $h, 'Sábado entero');
    }

    public function test_impide_pasarse_del_tope_semanal(): void
    {
        $u = $this->persona();

        // Lunes 31 de agosto a sábado 5 de septiembre: 6 × 2 h = 12 h.
        $this->dosHorasDiarias($u, '2026-08-31', 6);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/extras disponibles esta semana|agotó su tope de horas extras esta semana/');

        // El domingo es de la misma semana.
        [$d, $h] = $this->franja(2, 1);
        $this->turnos()->programar($u, $d, $h, 'Domingo');
    }

    public function test_deja_programar_justo_hasta_el_tope(): void
    {
        $u = $this->persona();

        $this->dosHorasDiarias($u, '2026-08-31', 5);

        [$d, $h] = $this->franja(2);
        $jornada = $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->assertNotNull($jornada->id);
        $this->assertSame(0, $this->extras()->disponibleSemana($u, $d), 'queda en el límite exacto');
    }

    /** El periodo va del 16 al 15: el 15 cierra un corte y el 16 abre otro. */
    public function test_el_periodo_corta_el_15(): void
    {
        $tz = config('fabos.lab.timezone');

        [$d, $h] = OvertimeService::periodoDe(Carbon::parse('2026-09-15 22:00', $tz));
        $this->assertSame('2026-08-16', $d->toDateString());
        $this->assertSame('2026-09-15', $h->toDateString());

        [$d, $h] = OvertimeService::periodoDe(Carbon::parse('2026-09-16 00:30', $tz));
        $this->assertSame('2026-09-16', $d->toDateString());
        $this->assertSame('2026-10-15', $h->toDateString());

        // Enero: del 16 de diciembre al 15 de enero, cruzando el año.
        [$d, $h] = OvertimeService::periodoDe(Carbon::parse('2027-01-10', $tz));
        $this->assertSame('2026-12-16', $d->toDateString());
        $this->assertSame('2027-01-15', $h->toDateString());
    }

    public function test_el_tope_del_periodo_manda_aunque_la_semana_lo_permita(): void
    {
        $u = $this->persona();
        $tz = config('fabos.lab.timezone');

        // 48 horas en el corte del 16 de agosto al 15 de septiembre: cuatro
        // semanas de lunes a sábado, dos horas diarias.
        foreach (['2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07'] as $lunes) {
            $this->dosHorasDiarias($u, $lunes, 6);
        }

        $this->assertSame(0, $this->extras()->disponibleMes($u, Carbon::parse('2026-09-10', $tz)));

        // El lunes 14 abre semana nueva, pero sigue en el mismo corte.
        $d = Carbon::parse('2026-09-14 08:00', $tz);

        try {
            $this->turnos()->programar($u, $d, $d->copy()->addHours(2), 'Otro');
            $this->fail('Tenía que rechazarse: el corte ya está lleno.');
        } catch (BookingException $e) {
            $this->assertMatchesRegularExpression('/corte del 16\/08 al 15\/09/', $e->getMessage());
        }

        // El 16 empieza otro corte: ahí sí cabe.
        $d = Carbon::parse('2026-09-16 08:00', $tz);
        $this->assertNotNull($this->turnos()->programar($u, $d, $d->copy()->addHours(2), 'Nuevo corte')->id);
    }

    public function test_no_permite_dos_jornadas_cruzadas(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(1);
        $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/se cruza/');

        $this->turnos()->programar($u, $d->copy()->addMinutes(30), $h->copy()->addMinutes(30), 'Otra cosa');
    }

    public function test_ordena_los_candidatos_por_quien_menos_extras_lleva(): void
    {
        $cargado = $this->persona();
        $libre   = $this->persona();

        $this->dosHorasDiarias($cargado, '2026-08-31', 6);

        [$d, $h] = $this->franja(2, 1);
        $orden = $this->extras()->ordenarPorCarga(collect([$cargado, $libre]), $d, $h);

        // Primero el que menos lleva: así no siempre cae en la misma persona.
        $this->assertSame($libre->id, $orden->first()['persona']->id);
        $this->assertTrue($orden->first()['puede']);

        $this->assertSame($cargado->id, $orden->last()['persona']->id);
        $this->assertFalse($orden->last()['puede'], 'ya no le cabe');
        $this->assertNotNull($orden->last()['motivo']);
    }

    public function test_el_resumen_del_periodo_cuenta_por_persona(): void
    {
        $u = $this->persona();
        $tz = config('fabos.lab.timezone');

        $this->dosHorasDiarias($u, '2026-09-07', 3);

        $fila = $this->extras()->resumen(collect([$u]), Carbon::parse('2026-09-09 18:00', $tz))->first();

        $this->assertSame(360, $fila['periodo']);
        $this->assertSame(360, $fila['semana']);
        $this->assertSame(120, $fila['hoy']);
        $this->assertSame(48 * 60 - 360, $fila['disponible']);
        $this->assertSame(0, $fila['dias_excedidos']);
    }

    /** El contador se dibuja encima de las jornadas, con el corte y las horas. */
    public function test_el_contador_se_ve_con_el_corte(): void
    {
        $u = $this->persona();
        $tz = config('fabos.lab.timezone');
        $this->travelTo(Carbon::parse('2026-09-09 18:00', $tz));
        $this->dosHorasDiarias($u, '2026-09-07', 3);

        \Livewire\Livewire::test(\App\Filament\Resources\ShiftAssignments\Widgets\ContadorDeExtras::class)
            ->assertSee('corte del 16/08 al 15/09/2026')
            ->assertSee($u->name)
            ->assertSee('6 h')
            ->call('irA', '2026-09-20')
            ->assertSee('corte del 16/09 al 15/10/2026');
    }

    public function test_registra_la_aceptacion_y_el_conflicto(): void
    {
        $u = $this->persona();
        [$d, $h] = $this->franja(2);
        $j = $this->turnos()->programar($u, $d, $h, 'Acompañamiento');

        $this->assertNotNull($this->turnos()->aceptar($j)->accepted_at);

        $j2 = $this->turnos()->reportarConflicto($j, 'Tengo cita médica');
        $this->assertSame('Tengo cita médica', $j2->conflict_note);
    }
}
