<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Asesorías (§10).
 *
 * La puerta para quien todavía no tiene el certifab. El sistema ya se la
 * prometía —«Asesoría con el responsable del equipo»— y no existía forma de
 * pedirla.
 */
class AsesoriasTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);

        $this->equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $area->id,
            'status' => 'operativo', 'is_reservable' => true,
        ]);
    }

    /** Lunes 24/08/2026, hora del laboratorio. */
    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    /** Alguien del equipo, en jornada presencial el lunes. */
    private function colaborador(string $nombre, string $modalidad = WorkSchedule::PRESENCIAL): User
    {
        $u = User::factory()->create(['name' => $nombre, 'status' => 'activo']);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => $modalidad,
            'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    private function asesora(User $u, bool $responsable = false): void
    {
        AssetAdvisor::create([
            'user_id' => $u->id, 'asset_id' => $this->equipo->id,
            'es_responsable' => $responsable,
        ]);
    }

    private function certificar(User $u): void
    {
        \App\Models\Certifab::create([
            'public_code' => 'CF-' . $u->id,
            'user_id'     => $u->id,
            'asset_id'    => $this->equipo->id,
            'level'       => 'autonomo',
            'granted_at'  => now()->subMonth(),
        ]);
    }

    private function alguien(): User
    {
        return User::factory()->create(['status' => 'activo']);
    }

    // ------------------------------------------------------------ lo básico

    public function test_sin_asesores_declarados_no_hay_a_quien_asignar(): void
    {
        $r = app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNull($r);
    }

    public function test_agendar_reserva_el_tiempo_de_quien_asesora(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        $r = app(AsesoriaService::class)->agendar(
            $quien, $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNotNull($r);
        $this->assertSame(User::class, $r->reservable_type);
        $this->assertSame($ana->id, $r->reservable_id);
        $this->assertSame($quien->id, $r->user_id);
        $this->assertSame($this->equipo->id, $r->advisory_asset_id);
        $this->assertSame('asesoria', $r->mode);
    }

    /** La máquina no se bloquea: muchas asesorías son de consulta. */
    public function test_la_asesoria_no_ocupa_el_equipo(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertSame(0, Reservation::where('reservable_type', Asset::class)
            ->where('reservable_id', $this->equipo->id)
            ->count());
    }

    // ------------------------------------------------------- quién la atiende

    public function test_si_hay_responsable_siempre_es_suya(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $this->asesora($ana);
        $this->asesora($beto, responsable: true);

        $servicio = app(AsesoriaService::class);

        foreach ([9, 11, 13, 15] as $h) {
            $r = $servicio->agendar(
                $this->alguien(), $this->equipo,
                $this->hora($h . ':00'), $this->hora($h . ':45'),
            );

            $this->assertSame($beto->id, $r->reservable_id, 'Una asesoría no fue a la responsable.');
        }
    }

    /** El reparto: cada una va teniendo la suya hasta completar la vuelta. */
    public function test_con_varios_asesores_el_reparto_es_equitativo(): void
    {
        foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
            $this->asesora($this->colaborador($nombre));
        }

        $servicio = app(AsesoriaService::class);
        $asignados = [];

        foreach ([9, 10, 11, 12, 13, 14] as $h) {
            $r = $servicio->agendar(
                $this->alguien(), $this->equipo,
                $this->hora($h . ':00'), $this->hora($h . ':45'),
            );

            $asignados[] = $r->reservable_id;
        }

        // Seis asesorías entre tres personas: dos cada una, sin excepción.
        $conteo = array_count_values($asignados);

        $this->assertCount(3, $conteo);
        $this->assertSame([2, 2, 2], array_values($conteo));
    }

    // -------------------------------------------------- quién NO puede atender

    public function test_quien_no_esta_en_jornada_no_recibe_asesorias(): void
    {
        $this->asesora($this->colaborador('Ana'));

        // El martes nadie tiene jornada.
        $r = app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo,
            Carbon::parse('2026-08-25 10:00', config('fabos.lab.timezone')),
            Carbon::parse('2026-08-25 11:00', config('fabos.lab.timezone')),
        );

        $this->assertNull($r);
    }

    /** Quien trabaja desde casa cumple su jornada, pero no atiende a nadie. */
    public function test_quien_esta_en_remoto_no_recibe_asesorias(): void
    {
        $this->asesora($this->colaborador('Ana', WorkSchedule::REMOTA));

        $r = app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNull($r);
    }

    public function test_no_se_asigna_a_alguien_que_ya_tiene_esa_hora_ocupada(): void
    {
        $this->asesora($this->colaborador('Ana'));

        $servicio = app(AsesoriaService::class);

        $this->assertNotNull($servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));

        $this->assertNull($servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));
    }

    public function test_nadie_se_asesora_a_si_mismo(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        $r = app(AsesoriaService::class)->agendar(
            $ana, $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNull($r);
    }

    /** Las canceladas no cuentan para el reparto: no se atendieron. */
    public function test_una_asesoria_cancelada_no_cuenta_en_el_reparto(): void
    {
        $this->asesora($this->colaborador('Ana'));
        $this->asesora($this->colaborador('Beto'));

        $servicio = app(AsesoriaService::class);

        $primera = $servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('09:00'), $this->hora('09:45'),
        );

        $primera->update(['status' => 'cancelada']);

        // Con la primera cancelada, la siguiente vuelve a quien la tenía.
        $segunda = $servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('11:00'), $this->hora('11:45'),
        );

        $this->assertSame($primera->reservable_id, $segunda->reservable_id);
    }

    // ------------------------------- asesoria y acompanamiento, en los dos sentidos

    /**
     * Una asesoria ocupa a una persona en el laboratorio.
     *
     * Si esa misma persona hacia falta para acompanar una maquina que lo exige,
     * las dos cosas se pisan — y en los dos sentidos. La garantia de fondo no
     * esta en el codigo sino en la base: la restriccion EXCLUDE impide dos
     * reservas solapadas sobre el mismo reservable, y tanto la asesoria como el
     * acompanamiento reservan a la persona.
     */
    public function test_quien_esta_en_una_asesoria_no_puede_acompanar_una_maquina(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $this->certificar($ana);

        // Antes de la asesoria, Ana puede acompanar.
        $antes = app(BookingService::class)
            ->acompanantesDisponibles($this->equipo, $this->hora('10:00'), $this->hora('11:00'));
        $this->assertTrue($antes->contains('id', $ana->id));

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        // Despues, ya no.
        $despues = app(BookingService::class)
            ->acompanantesDisponibles($this->equipo, $this->hora('10:00'), $this->hora('11:00'));
        $this->assertFalse($despues->contains('id', $ana->id));

        // Y sigue libre en otra franja: se ocupa la hora, no el dia.
        $otra = app(BookingService::class)
            ->acompanantesDisponibles($this->equipo, $this->hora('12:00'), $this->hora('13:00'));
        $this->assertTrue($otra->contains('id', $ana->id));
    }

    public function test_quien_acompana_una_maquina_no_recibe_asesorias_a_esa_hora(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        // Acompanamiento: el tiempo de Ana reservado por otra via.
        Reservation::create([
            'reservable_type' => User::class,
            'reservable_id'   => $ana->id,
            'user_id'         => $this->alguien()->id,
            'status'          => 'confirmada',
            'mode'            => 'directa',
            'starts_at'       => $this->hora('10:00'),
            'ends_at'         => $this->hora('11:00'),
            'purpose'         => 'Acompanamiento',
        ]);

        $this->assertNull(app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));

        // Media hora despues tampoco: se solapan aunque no coincidan exactas.
        $this->assertNull(app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:30'), $this->hora('11:30'),
        ));

        // Pero mas tarde si.
        $this->assertNotNull(app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('11:00'), $this->hora('12:00'),
        ));
    }

    /** La red de seguridad: aunque el codigo fallara, la base no lo permite. */
    public function test_la_base_de_datos_rechaza_doblar_a_una_persona(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        Reservation::create([
            'reservable_type' => User::class,
            'reservable_id'   => $ana->id,
            'user_id'         => $this->alguien()->id,
            'status'          => 'confirmada',
            'mode'            => 'directa',
            'starts_at'       => $this->hora('10:30'),
            'ends_at'         => $this->hora('11:30'),
        ]);
    }
}
