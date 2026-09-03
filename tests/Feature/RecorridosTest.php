<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El laboratorio entero: recorridos y cierres (§7).
 *
 * Un recorrido ocupa todo el laboratorio sin cerrarlo: caben treinta
 * personas a la vez, en grupos de quince, y las máquinas siguen trabajando.
 * Eso no cabía en «reservar un espacio es tenerlo en exclusiva», y por eso
 * la base deja pasar los recorridos y el servicio es quien suma.
 *
 * La operación es lo contrario y es rara: lo toma completo, y mientras dure
 * no se reserva ni una sala ni una máquina.
 */
class RecorridosTest extends TestCase
{
    use RefreshDatabase;

    private Space $todo;
    private Space $taller;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->todo = Space::todoElLaboratorio();
        $this->assertNotNull($this->todo, 'la migración siembra el laboratorio entero');

        $this->taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'capacity' => 12, 'is_reservable' => true]);

        // Alguien en jornada presencial los lunes: sin cobertura no se atiende.
        $c = User::factory()->create(['status' => 'activo']);
        WorkSchedule::create([
            'user_id' => $c->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
    }

    private function espacios(): EspacioBookingService
    {
        return app(EspacioBookingService::class);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))->next(Carbon::MONDAY)->setTimeFromTimeString($hhmm);
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function recorrido(int $personas, string $desde = '10:00', string $hasta = '11:00'): Reservation
    {
        return $this->espacios()->reservar($this->alguien(), $this->todo, $this->hora($desde), $this->hora($hasta), $personas);
    }

    // ------------------------------------------------------------ recorridos

    public function test_un_recorrido_no_bloquea_el_laboratorio(): void
    {
        $r = $this->recorrido(12);

        $this->assertTrue($r->esRecorrido());
        $this->assertFalse($r->esCierreTotal());

        // El taller se sigue reservando a la misma hora: el recorrido pasa por
        // ahí, no lo cierra.
        $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('10:00'), $this->hora('11:00'), 4);

        $this->assertSame(2, Reservation::count());
    }

    /** Dos grupos a la vez caben: son los dos recorridos simultáneos de siempre. */
    public function test_se_cuenta_cuanta_gente_hay_en_recorrido_a_esa_hora(): void
    {
        $this->recorrido(15);
        $this->recorrido(15);

        $this->assertSame(30, $this->espacios()->personasEnRecorrido($this->hora('10:00'), $this->hora('11:00')));
    }

    /**
     * El aforo del laboratorio entero es una guía, no un tope.
     *
     * Un recorrido de cuarenta y cinco se parte en tres grupos que rotan; eso
     * lo organiza quien lo lleva. El sistema no lo impide: sugiere los grupos
     * y deja la nota en la reserva, para que se lea al preparar la visita.
     */
    public function test_un_recorrido_grande_entra_y_el_sistema_sugiere_los_grupos(): void
    {
        $r = $this->recorrido(45);

        $this->assertSame('confirmada', $r->status);
        $this->assertStringContainsString('3 grupos', $r->status_reason);
    }

    /** Y si a esa hora ya hay otro recorrido, se avisa; no se cierra la puerta. */
    public function test_otro_recorrido_a_la_misma_hora_se_avisa_sin_impedirlo(): void
    {
        $this->recorrido(15);
        $this->recorrido(10);

        $r = $this->recorrido(10);

        $this->assertSame('confirmada', $r->status);
        $this->assertStringContainsString('ya hay otro recorrido con 25', $r->status_reason);
    }

    /** En operación el aforo manda, también en el laboratorio entero. */
    public function test_una_operacion_no_pasa_del_aforo(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/caben 30/');

        $this->espacios()->reservar(
            $this->alguien(), $this->todo, $this->hora('10:00'), $this->hora('12:00'), 40, [], 'Montaje',
            EspacioBookingService::OPERACION,
        );
    }

    // ------------------------------------------------- recorridos en una sala

    /** Una sala también se recorre: sin tope, sin bloquearla, con los grupos sugeridos. */
    public function test_una_sala_en_recorrido_no_tiene_tope_ni_bloquea(): void
    {
        $r = $this->espacios()->reservar(
            $this->alguien(), $this->taller, $this->hora('10:00'), $this->hora('11:00'), 25, [], null,
            EspacioBookingService::RECORRIDO,
        );

        $this->assertTrue($r->esRecorrido());
        $this->assertStringContainsString('3 grupos de hasta 12', $r->status_reason);

        // Y otra actividad en el taller a la misma hora entra igual.
        $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('10:00'), $this->hora('11:00'), 4);

        $this->assertSame(2, Reservation::count());
    }

    /** Dos salas de a diez para veinte: en operación caben, porque el aforo que cuenta es la suma. */
    public function test_con_varias_salas_el_aforo_es_la_suma(): void
    {
        $vr = Space::create(['slug' => 'vr', 'name' => 'Lab. VR', 'capacity' => 10, 'is_reservable' => true]);
        $impresion = Space::create(['slug' => '3d', 'name' => 'Lab. Impresión 3D', 'capacity' => 10, 'is_reservable' => true]);

        $madre = $this->espacios()->reservarVarios(
            $this->alguien(), [$impresion, $vr], $this->hora('10:00'), $this->hora('12:00'), 20,
        );

        $this->assertSame('confirmada', $madre->status);
        $this->assertSame(1, Reservation::where('parent_reservation_id', $madre->id)->count());

        // Veinticinco ya no caben ni sumando: se dice con la suma delante.
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/caben 20 personas/');

        $this->espacios()->reservarVarios(
            $this->alguien(), [$impresion, $vr], $this->hora('14:00'), $this->hora('16:00'), 25,
        );
    }

    /** Un grupo que cabe en uno no recibe ninguna nota: no hay nada que organizar. */
    public function test_un_grupo_pequeno_no_lleva_nota(): void
    {
        $this->assertNull($this->recorrido(12)->status_reason);
    }

    /** Fuera de la franja, el aforo vuelve a estar libre. */
    public function test_lo_que_no_se_solapa_no_cuenta(): void
    {
        $this->recorrido(30, '09:00', '10:00');
        $this->recorrido(30, '10:00', '11:00');

        $this->assertSame(2, Reservation::where('mode', 'recorrido')->count());
    }

    // ---------------------------------------------------------------- cierres

    public function test_cerrar_el_laboratorio_deja_fuera_salas_y_maquinas(): void
    {
        $cierre = $this->espacios()->reservar(
            $this->alguien(), $this->todo, $this->hora('10:00'), $this->hora('12:00'), 8, [], 'Montaje',
            EspacioBookingService::OPERACION,
        );

        $this->assertTrue($cierre->esCierreTotal());

        // Ni una sala...
        try {
            $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('11:00'), $this->hora('12:00'));
            $this->fail('el taller no debería reservarse durante el cierre');
        } catch (BookingException $e) {
            $this->assertStringContainsString('reservado entero', $e->getMessage());
        }

        // ...ni un recorrido...
        try {
            $this->recorrido(5, '10:30', '11:30');
            $this->fail('un recorrido no debería entrar durante el cierre');
        } catch (BookingException $e) {
            $this->assertStringContainsString('reservado entero', $e->getMessage());
        }

        // ...ni una máquina.
        $area = Area::create(['slug' => 'a', 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create(['area_id' => $area->id, 'slug' => 'fdm', 'name' => 'FDM', 'required_course_level' => 'byte', 'requires_companion' => false]);
        $equipo = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id, 'name' => 'Prusa', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
        $persona = $this->alguien();
        \App\Models\Certifab::create(['user_id' => $persona->id, 'risk_family_id' => $rf->id, 'level' => 'byte']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/reservado entero/');

        app(BookingService::class)->reservar($persona, $equipo, $this->hora('10:00'), $this->hora('11:00'));
    }

    /** Y al revés: con una sala tomada no se cierra el laboratorio. */
    public function test_no_se_cierra_el_laboratorio_con_una_sala_reservada(): void
    {
        $this->espacios()->reservar($this->alguien(), $this->taller, $this->hora('10:00'), $this->hora('11:00'));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/1 reserva/');

        $this->espacios()->reservar(
            $this->alguien(), $this->todo, $this->hora('09:00'), $this->hora('12:00'), 5, [], null,
            EspacioBookingService::OPERACION,
        );
    }

    /** Desde el sitio, el laboratorio entero es un recorrido: no se puede cerrar desde ahí. */
    public function test_sin_decir_modalidad_el_laboratorio_entero_es_un_recorrido(): void
    {
        $r = $this->espacios()->reservar($this->alguien(), $this->todo, $this->hora('10:00'), $this->hora('11:00'), 20);

        $this->assertTrue($r->esRecorrido());
    }

    // ------------------------------------------------------------ acompañantes

    public function test_los_acompanantes_quedan_anotados_y_son_del_equipo(): void
    {
        $practicante = User::create(['name' => 'Practicante', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $practicante->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        $externo = $this->alguien();

        $r = $this->espacios()->reservar(
            $this->alguien(), $this->todo, $this->hora('10:00'), $this->hora('11:00'), 15, [], null,
            null, [$practicante->id, $externo->id],
        );

        $this->assertSame([$practicante->id], $r->companions->pluck('id')->all(), 'solo el equipo acompaña');
    }

    public function test_sin_acompanantes_tambien_vale(): void
    {
        $r = $this->recorrido(10);

        $this->assertCount(0, $r->companions);
    }

    // -------------------------------------------------------------- la pantalla

    public function test_la_pantalla_de_espacios_ofrece_el_recorrido_primero(): void
    {
        $this->actingAs($this->alguien())
            ->get(route('espacios.index'))
            ->assertOk()
            ->assertSeeInOrder(['Recorrido por todo el laboratorio', 'Taller'])
            ->assertSee('No interrumpe lo que esté en marcha');
    }
}
