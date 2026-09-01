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
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Asesoría general de un área (§10).
 *
 * Una asesoría era siempre sobre **una** máquina, y hay que saber cuál antes de
 * pedirla. Quien llega con «quiero imprimir esto en 3D» todavía no sabe si le
 * toca la Prusa o la de resina: elegir la máquina es parte de lo que viene a
 * consultar. Obligarle a elegirla antes es pedirle la respuesta para dejarle
 * hacer la pregunta.
 *
 * El reparto es el mismo que ya había para los equipos: le toca a quien menos
 * lleva, y en caso de empate a quien hace más tiempo que no atiende una.
 */
class AsesoriaGeneralTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Lunes por la mañana, quieto: quien asesora trabaja los lunes, y una
        // prueba que depende de cuándo se ejecuta no prueba nada.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        $this->area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
    }

    private function equipo(string $nombre): Asset
    {
        return Asset::create([
            'area_id' => $this->area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    /** Alguien que asesora ese equipo, en jornada presencial los lunes. */
    private function asesora(Asset $equipo, string $nombre): User
    {
        $u = User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);

        AssetAdvisor::create([
            'user_id' => $u->id, 'asset_id' => $equipo->id, 'es_responsable' => false,
        ]);

        return $u->fresh();
    }

    private function alguien(): User
    {
        return User::create([
            'name' => 'Quien pregunta', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function asesorias(): AsesoriaService
    {
        return app(AsesoriaService::class);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    // ------------------------------------------------------------ quién asesora

    /**
     * Del área asesora quien asesore cualquiera de sus máquinas.
     *
     * No hay una lista aparte a propósito: sería una segunda verdad que se
     * separaría de la primera en cuanto alguien entre o salga de un equipo.
     */
    public function test_asesora_el_area_quien_asesora_alguna_de_sus_maquinas(): void
    {
        $prusa = $this->equipo('Prusa MK4');
        $resina = $this->equipo('Impresora de resina');

        $ana = $this->asesora($prusa, 'Ana');
        $beto = $this->asesora($resina, 'Beto');

        $delArea = $this->asesorias()->asesoresDe($this->area)->pluck('id');

        $this->assertTrue($delArea->contains($ana->id));
        $this->assertTrue($delArea->contains($beto->id));
    }

    public function test_un_area_sin_asesores_no_ofrece_asesoria(): void
    {
        $this->equipo('Prusa MK4');

        $this->assertFalse($this->asesorias()->seAsesora($this->area));
    }

    // ------------------------------------------------------------- el reparto

    /**
     * El mismo turno que en los equipos: le toca a quien menos lleva.
     *
     * Es lo que pidió el laboratorio, y no hace falta inventar nada: la regla
     * ya existía, solo cambia sobre qué se cuenta.
     */
    public function test_le_toca_a_quien_menos_generales_lleva(): void
    {
        $prusa = $this->equipo('Prusa MK4');
        $ana = $this->asesora($prusa, 'Ana');
        $beto = $this->asesora($prusa, 'Beto');

        // Ana ya atendió una general del área.
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $ana->id,
            'user_id' => $this->alguien()->id,
            'advisory_area_id' => $this->area->id,
            'mode' => 'asesoria', 'status' => 'confirmada',
            'starts_at' => $this->hora('08:00'), 'ends_at' => $this->hora('08:45'),
        ]);

        $elegido = $this->asesorias()->elegir(
            $this->area, $this->hora('10:00'), $this->hora('10:45'),
        );

        $this->assertSame($beto->id, $elegido?->id, 'Le tocaba a quien menos llevaba.');
    }

    /**
     * El turno de las generales se cuenta entre ellas.
     *
     * Mezclarlo con el de cada máquina haría que quien asesora mucho una Prusa
     * no cayera nunca en una general, y al revés.
     */
    public function test_las_de_una_maquina_no_cuentan_para_las_generales(): void
    {
        $prusa = $this->equipo('Prusa MK4');
        $ana = $this->asesora($prusa, 'Ana');
        $beto = $this->asesora($prusa, 'Beto');

        // Ana lleva tres de la Prusa, pero ninguna general.
        foreach (['08:00', '09:00', '11:00'] as $h) {
            Reservation::create([
                'reservable_type' => User::class, 'reservable_id' => $ana->id,
                'user_id' => $this->alguien()->id,
                'advisory_asset_id' => $prusa->id,
                'mode' => 'asesoria', 'status' => 'confirmada',
                'starts_at' => $this->hora($h), 'ends_at' => $this->hora($h)->addMinutes(45),
            ]);
        }

        // Empatan a cero generales, así que decide el desempate estable.
        $elegido = $this->asesorias()->elegir(
            $this->area, $this->hora('14:00'), $this->hora('14:45'),
        );

        $this->assertSame($ana->id, $elegido?->id);
        $this->assertNotNull($beto->id);
    }

    // ------------------------------------------------------------- agendarla

    public function test_agendarla_reserva_el_tiempo_de_quien_asesora(): void
    {
        $prusa = $this->equipo('Prusa MK4');
        $ana = $this->asesora($prusa, 'Ana');
        $quien = $this->alguien();

        $reserva = $this->asesorias()->agendar(
            $quien, $this->area, $this->hora('10:00'), $this->hora('10:45'), 'Quiero imprimir algo',
        );

        $this->assertNotNull($reserva);
        $this->assertSame($ana->id, $reserva->reservable_id);
        $this->assertSame($this->area->id, $reserva->advisory_area_id);
        $this->assertNull($reserva->advisory_asset_id, 'Una general no es de ninguna máquina.');
        $this->assertSame('General de Impresión 3D', $reserva->fresh()->sobreQue());
    }

    // ------------------------------------------------------------ la pantalla

    public function test_la_pantalla_ofrece_horas_y_se_puede_pedir(): void
    {
        $prusa = $this->equipo('Prusa MK4');
        $this->asesora($prusa, 'Ana');
        $quien = $this->alguien();

        $this->actingAs($quien)
            ->get(route('asesoria.area.show', $this->area))
            ->assertOk()
            ->assertSee('Asesoría general de Impresión 3D');

        $this->actingAs($quien)
            ->post(route('asesoria.area.store', $this->area), [
                'inicio' => $this->hora('10:00')->format('Y-m-d H:i:s'),
                'motivo' => 'Quiero imprimir una pieza',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'advisory_area_id' => $this->area->id,
            'mode' => 'asesoria',
        ]);
    }

    /** Un área sin quien asesore no tiene pantalla que ofrecer. */
    public function test_sin_asesores_la_pantalla_no_existe(): void
    {
        $this->equipo('Prusa MK4');

        $this->actingAs($this->alguien())
            ->get(route('asesoria.area.show', $this->area))
            ->assertNotFound();
    }

    /** Y se ofrece desde la página de reservas, dentro del área. */
    public function test_se_ofrece_desde_la_pagina_de_reservas(): void
    {
        $prusa = $this->equipo('Prusa MK4');
        $this->asesora($prusa, 'Ana');

        // Al elegir el área se pregunta si general o de una máquina: ahí es
        // donde se ofrece, antes de la lista.
        $this->get('/reservas?modo=asesoria&area=impresion-3d')
            ->assertOk()
            ->assertSee('General del área')
            ->assertSee(route('asesoria.area.show', $this->area), false);
    }
}
