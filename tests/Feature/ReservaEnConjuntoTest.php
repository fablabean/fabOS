<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo que no sirve por separado se reserva junto (§7).
 *
 * Unas gafas de realidad virtual sin la sala donde están no sirven de nada:
 * quien las reserva ocupa el sitio, lo pida o no. Y hay cosas que dependen de
 * quien reserva —las mismas gafas, con computador o sin él— y por eso se
 * preguntan en vez de imponerse.
 */
class ReservaEnConjuntoTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;
    private Asset $gafas;
    private User $quien;

    protected function setUp(): void
    {
        parent::setUp();

        $area = Area::create(['slug' => 'vr', 'name' => 'VR']);

        $this->sala = Space::create([
            'slug' => 'sala-vr', 'name' => 'Sala VR', 'capacity' => 6, 'is_reservable' => true,
        ]);

        $this->gafas = Asset::create([
            'name' => 'Gafas VR', 'slug' => 'gafas-vr', 'area_id' => $area->id,
            'kind' => 'herramienta', 'status' => 'operativo', 'is_reservable' => true,
            'space_id' => $this->sala->id, 'reserva_con_espacio' => true,
            'min_minutes' => 30, 'max_minutes' => 480, 'autonomous_minutes' => 480,
        ]);

        $colaborador = User::factory()->create(['status' => 'activo']);
        WorkSchedule::create([
            'user_id' => $colaborador->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);

        $this->quien = User::factory()->create(['status' => 'activo']);

        Certifab::create([
            'public_code' => 'CF-VR', 'user_id' => $this->quien->id,
            'asset_id' => $this->gafas->id, 'level' => 'autonomo',
            'granted_at' => now()->subMonth(),
        ]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))
            ->next(Carbon::MONDAY)
            ->setTimeFromTimeString($hhmm);
    }

    private function computador(string $modo): Asset
    {
        $pc = Asset::create([
            'name' => 'Computador VR', 'slug' => 'pc-vr',
            'area_id' => $this->gafas->area_id, 'kind' => 'computador',
            'status' => 'operativo', 'is_reservable' => true, 'space_id' => $this->sala->id,
        ]);

        $this->gafas->dependencies()->attach($pc->id, ['modo' => $modo]);

        return $pc;
    }

    // ------------------------------------------------------- el espacio

    public function test_reservar_las_gafas_reserva_tambien_su_sala(): void
    {
        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertDatabaseHas('reservations', [
            'parent_reservation_id' => $r->id,
            'reservable_type'       => Space::class,
            'reservable_id'         => $this->sala->id,
        ]);
    }

    /** Y entonces la sala deja de estar libre para otro grupo. */
    public function test_la_sala_queda_ocupada_para_los_demas(): void
    {
        app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
        );

        $ocupada = Reservation::where('reservable_type', Space::class)
            ->where('reservable_id', $this->sala->id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->exists();

        $this->assertTrue($ocupada);
    }

    /** Sin la marca, el equipo no arrastra nada: es una decisión por equipo. */
    public function test_sin_la_marca_no_se_reserva_el_espacio(): void
    {
        $this->gafas->update(['reserva_con_espacio' => false]);

        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertSame(0, Reservation::where('parent_reservation_id', $r->id)->count());
    }

    // --------------------------------------------------- lo que va junto

    public function test_lo_marcado_como_junto_se_reserva_siempre(): void
    {
        $pc = $this->computador('junto');

        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertDatabaseHas('reservations', [
            'parent_reservation_id' => $r->id,
            'reservable_id'         => $pc->id,
        ]);
    }

    // ------------------------------------------------------ lo opcional

    /** Lo opcional no se impone: solo si quien reserva lo marcó. */
    public function test_lo_opcional_no_se_reserva_si_no_se_pide(): void
    {
        $pc = $this->computador('opcional');

        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertSame(0, Reservation::where('parent_reservation_id', $r->id)
            ->where('reservable_id', $pc->id)
            ->count());
    }

    public function test_lo_opcional_se_reserva_si_se_pide(): void
    {
        $pc = $this->computador('opcional');

        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
            complementos: [$pc->id],
        );

        $this->assertDatabaseHas('reservations', [
            'parent_reservation_id' => $r->id,
            'reservable_id'         => $pc->id,
        ]);
    }

    /**
     * El formulario se puede manipular: aceptar cualquier identificador
     * convertiría una casilla en una forma de reservar lo que sea.
     */
    public function test_no_se_puede_colar_un_equipo_que_no_es_complemento(): void
    {
        $ajeno = Asset::create([
            'name' => 'Fresadora', 'slug' => 'fresadora',
            'area_id' => $this->gafas->area_id, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
        ]);

        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
            complementos: [$ajeno->id],
        );

        $this->assertSame(0, Reservation::where('parent_reservation_id', $r->id)
            ->where('reservable_id', $ajeno->id)
            ->count());
    }

    /** Y «tiene que estar operativo» sigue sin reservar nada. */
    public function test_una_dependencia_de_solo_estado_no_se_reserva(): void
    {
        $compresor = $this->computador('operativo');

        $r = app(BookingService::class)->reservar(
            $this->quien, $this->gafas, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertSame(0, Reservation::where('parent_reservation_id', $r->id)
            ->where('reservable_id', $compresor->id)
            ->count());
    }
}
