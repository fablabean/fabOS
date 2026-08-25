<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
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
 * Reservar un espacio, y dentro de él las herramientas (§7).
 *
 * Es el uso normal del laboratorio: nadie reserva un juego de llaves suelto,
 * reserva la mesa del taller y toma lo que necesita.
 */
class ReservaDeEspaciosTest extends TestCase
{
    use RefreshDatabase;

    private Space $taller;
    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::create(['slug' => 'taller', 'name' => 'Taller']);
        $this->taller = Space::create([
            'slug' => 'taller', 'name' => 'Taller', 'capacity' => 12, 'is_reservable' => true,
        ]);
        $this->taller->areas()->attach($this->area);

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

    private function herramienta(string $nombre, ?Space $espacio = null, bool $puedeSalir = false): Asset
    {
        return Asset::create([
            'name' => $nombre, 'slug' => \Illuminate\Support\Str::slug($nombre),
            'area_id' => $this->area->id, 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true,
            'space_id' => ($espacio ?? $this->taller)->id,
            'puede_salir' => $puedeSalir,
        ]);
    }

    // ------------------------------------------------------------ lo básico

    public function test_reservar_un_espacio_guarda_los_participantes(): void
    {
        $quien = User::factory()->create(['status' => 'activo']);

        $r = app(EspacioBookingService::class)->reservar(
            $quien, $this->taller, $this->hora('10:00'), $this->hora('12:00'), participantes: 8,
        );

        $this->assertSame(Space::class, $r->reservable_type);
        $this->assertSame($this->taller->id, $r->reservable_id);
        $this->assertSame(8, $r->participants);
    }

    /**
     * El aforo es un dato del espacio, y el mensaje dice el numero: quien lo
     * lea sabra si es un limite real o uno que nadie ha revisado todavia.
     */
    public function test_no_caben_mas_personas_de_las_que_dice_el_aforo(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/caben 12/');

        app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('10:00'), $this->hora('12:00'), participantes: 25,
        );
    }

    public function test_dos_grupos_no_pueden_tener_el_mismo_espacio_a_la_vez(): void
    {
        $servicio = app(EspacioBookingService::class);

        $servicio->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('10:00'), $this->hora('12:00'),
        );

        $this->expectException(BookingException::class);

        $servicio->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('11:00'), $this->hora('13:00'),
        );
    }

    // -------------------------------------------------------- herramientas

    public function test_las_herramientas_marcadas_quedan_reservadas(): void
    {
        $llaves = $this->herramienta('Juego de llaves');
        $quien = User::factory()->create(['status' => 'activo']);

        $r = app(EspacioBookingService::class)->reservar(
            $quien, $this->taller, $this->hora('10:00'), $this->hora('12:00'),
            participantes: 3, herramientaIds: [$llaves->id],
        );

        $this->assertSame(1, Reservation::where('parent_reservation_id', $r->id)->count());
        $this->assertDatabaseHas('reservations', [
            'reservable_type' => Asset::class,
            'reservable_id'   => $llaves->id,
            'parent_reservation_id' => $r->id,
        ]);
    }

    /**
     * La regla del laboratorio: la mayoria de herramientas no salen de su sitio.
     * Prestarlas a otra sala deja sin ellas a quien trabaja alli.
     */
    public function test_una_herramienta_de_otro_espacio_no_se_puede_tomar(): void
    {
        $otro = Space::create(['slug' => 'vr', 'name' => 'Sala VR', 'capacity' => 6, 'is_reservable' => true]);
        $visor = $this->herramienta('Visor', $otro);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/no se puede usar/');

        app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('10:00'), $this->hora('12:00'),
            herramientaIds: [$visor->id],
        );
    }

    /** Salvo que esté marcada como portátil: entonces se lleva donde haga falta. */
    public function test_una_herramienta_portatil_si_se_puede_llevar(): void
    {
        $otro = Space::create(['slug' => 'vr', 'name' => 'Sala VR', 'capacity' => 6, 'is_reservable' => true]);
        $multimetro = $this->herramienta('Multímetro', $otro, puedeSalir: true);

        $r = app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('10:00'), $this->hora('12:00'),
            herramientaIds: [$multimetro->id],
        );

        $this->assertSame(1, Reservation::where('parent_reservation_id', $r->id)->count());
    }

    public function test_una_herramienta_ya_reservada_no_se_da_dos_veces(): void
    {
        $llaves = $this->herramienta('Juego de llaves');
        $servicio = app(EspacioBookingService::class);

        $servicio->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('10:00'), $this->hora('12:00'),
            herramientaIds: [$llaves->id],
        );

        $otro = Space::create(['slug' => 'aula', 'name' => 'Aula', 'capacity' => 20, 'is_reservable' => true]);
        $llaves->update(['puede_salir' => true]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya está reservada/');

        $servicio->reservar(
            User::factory()->create(['status' => 'activo']),
            $otro, $this->hora('11:00'), $this->hora('13:00'),
            herramientaIds: [$llaves->id],
        );
    }

    /**
     * Reservar el espacio NO bloquea sus maquinas: una charla en el taller no
     * tiene por que dejar parada la fresadora del rincon.
     */
    public function test_reservar_el_espacio_no_bloquea_sus_maquinas(): void
    {
        $fresadora = Asset::create([
            'name' => 'Fresadora', 'slug' => 'fresadora', 'area_id' => $this->area->id,
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'space_id' => $this->taller->id,
        ]);

        app(EspacioBookingService::class)->reservar(
            User::factory()->create(['status' => 'activo']),
            $this->taller, $this->hora('10:00'), $this->hora('12:00'),
        );

        $this->assertSame(0, Reservation::where('reservable_type', Asset::class)
            ->where('reservable_id', $fresadora->id)
            ->count());
    }

    // -------------------------------------------------------------- pantalla

    public function test_la_pantalla_lista_los_espacios_reservables(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'activo']))
            ->get(route('espacios.index'))
            ->assertOk()
            ->assertSee('Taller')
            ->assertSee('Hasta 12 personas');
    }

    public function test_se_puede_reservar_desde_la_pantalla(): void
    {
        $quien = User::factory()->create(['status' => 'activo']);
        $desde = $this->hora('10:00');

        $this->actingAs($quien)
            ->post(route('espacios.store', $this->taller), [
                'fecha'         => $desde->format('Y-m-d'),
                'inicio'        => '10:00',
                'duracion'      => 120,
                'participantes' => 5,
                'proposito'     => 'Taller de impresión',
            ])
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('reservations', [
            'reservable_type' => Space::class,
            'reservable_id'   => $this->taller->id,
            'participants'    => 5,
        ]);
    }

    public function test_pasarse_del_aforo_lo_dice_en_el_campo_correcto(): void
    {
        $desde = $this->hora('10:00');

        $this->actingAs(User::factory()->create(['status' => 'activo']))
            ->post(route('espacios.store', $this->taller), [
                'fecha'         => $desde->format('Y-m-d'),
                'inicio'        => '10:00',
                'duracion'      => 120,
                'participantes' => 30,
            ])
            ->assertSessionHasErrors('participantes');
    }
}
