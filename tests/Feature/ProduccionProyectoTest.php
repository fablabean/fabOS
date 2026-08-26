<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Project;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Projects\ProduccionService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Producir con una máquina para un proyecto (§10, §11).
 *
 * **Una producción es una reserva.** Fabricar ocupa el equipo exactamente igual
 * que practicar con él, así que vive en la misma tabla: si viviera aparte
 * habría dos calendarios, y tarde o temprano alguien reservaría la impresora
 * para las tres mientras una pieza de seis horas sigue dentro.
 *
 * Lo que se comprueba aquí es justo esa frontera: que ocupa de verdad, que no
 * se le exige lo que a una reserva de aprendizaje, y que lo que fabrica se le
 * carga al proyecto.
 */
class ProduccionProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1],
        );

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function impresora(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);

        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'name' => 'Bambu X1 ' . uniqid(), 'slug' => 'bambu-' . uniqid(),
            'area_id' => $area->id, 'risk_family_id' => $familia->id,
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 720, 'max_minutes' => 1440,
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function proyecto(): Project
    {
        return app(ProjectService::class)->registrarIdea(['name' => 'Carcasa del robot']);
    }

    // ---------------------------------------------------------------- ocupar

    /**
     * El punto de todo: la máquina deja de estar disponible. No hay nada que
     * programar para conseguirlo —es la misma tabla y la misma restricción—,
     * pero justo por eso conviene fijarlo con una prueba.
     */
    public function test_producir_saca_la_maquina_de_la_lista(): void
    {
        $equipo = $this->impresora();
        $desde = now()->addDay()->setTime(18, 0);
        $hasta = now()->addDay()->setTime(23, 59);

        $reservas = app(BookingService::class);

        $this->assertTrue($reservas->estaLibre(Asset::class, $equipo->id, $desde, $hasta));

        app(ProduccionService::class)->programar(
            $this->proyecto(), $equipo, $this->persona(), $desde, $hasta, 'Carcasa v3, 4 piezas',
        );

        $this->assertFalse(
            $reservas->estaLibre(Asset::class, $equipo->id, $desde, $hasta),
            'La impresora sigue apareciendo libre mientras imprime.',
        );
    }

    /** Y quien intente reservarla en ese rango se topa con ello. */
    public function test_nadie_puede_reservar_encima_de_una_produccion(): void
    {
        $equipo = $this->impresora();
        $desde = now()->addDay()->setTime(18, 0);
        $hasta = now()->addDay()->setTime(22, 0);

        app(ProduccionService::class)->programar(
            $this->proyecto(), $equipo, $this->persona(), $desde, $hasta,
        );

        $quiereReservar = $this->persona();
        Certifab::create([
            'user_id' => $quiereReservar->id, 'asset_id' => $equipo->id,
            'level' => 'autonomo', 'status' => 'vigente', 'granted_on' => now()->subMonth(),
        ]);

        $this->expectException(BookingException::class);

        app(BookingService::class)->reservar(
            $quiereReservar, $equipo,
            now()->addDay()->setTime(19, 0),
            now()->addDay()->setTime(20, 0),
        );
    }

    /** Dos producciones tampoco pueden pisarse entre ellas. */
    public function test_dos_producciones_no_se_pisan(): void
    {
        $equipo = $this->impresora();
        $p = $this->proyecto();
        $quien = $this->persona();
        $servicio = app(ProduccionService::class);

        $servicio->programar($p, $equipo, $quien,
            now()->addDay()->setTime(8, 0), now()->addDay()->setTime(14, 0));

        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('ya está ocupada');

        $servicio->programar($p, $equipo, $quien,
            now()->addDay()->setTime(12, 0), now()->addDay()->setTime(16, 0));
    }

    /** Cancelarla suelta la máquina: se cayó la impresión, no se perdió el turno. */
    public function test_cancelar_una_produccion_suelta_la_maquina(): void
    {
        $equipo = $this->impresora();
        $desde = now()->addDay()->setTime(18, 0);
        $hasta = now()->addDay()->setTime(22, 0);

        $produccion = app(ProduccionService::class)->programar(
            $this->proyecto(), $equipo, $this->persona(), $desde, $hasta,
        );

        app(ProduccionService::class)->cancelar($produccion, 'Se cayó la impresión.');

        $this->assertTrue(
            app(BookingService::class)->estaLibre(Asset::class, $equipo->id, $desde, $hasta),
        );
    }

    // ------------------------------------------------------- lo que no pide

    /**
     * No hay nadie aprendiendo: hay un trabajo que sale. Exigir certifab para
     * que el laboratorio use su propia máquina no protegería a nadie.
     */
    public function test_producir_no_exige_certifab(): void
    {
        $equipo = $this->impresora();

        $sinCertifab = $this->persona();

        $produccion = app(ProduccionService::class)->programar(
            $this->proyecto(), $equipo, $sinCertifab,
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(23, 0),
        );

        $this->assertSame('confirmada', $produccion->status);
        $this->assertTrue($produccion->esProduccion());
    }

    /**
     * Una impresión de seis horas empieza a las seis de la tarde y termina de
     * madrugada. Obligarla a caber en el horario de atención sería obligar al
     * laboratorio a producir peor.
     */
    public function test_una_produccion_puede_correr_de_madrugada(): void
    {
        $produccion = app(ProduccionService::class)->programar(
            $this->proyecto(), $this->impresora(), $this->persona(),
            now()->addDay()->setTime(22, 0),
            now()->addDays(2)->setTime(4, 0),
        );

        $this->assertSame('confirmada', $produccion->status);
        $this->assertSame(360, (int) $produccion->starts_at->diffInMinutes($produccion->ends_at));
    }

    public function test_el_rango_al_reves_no_pasa(): void
    {
        $this->expectException(ProjectException::class);

        app(ProduccionService::class)->programar(
            $this->proyecto(), $this->impresora(), $this->persona(),
            now()->addDay()->setTime(14, 0), now()->addDay()->setTime(10, 0),
        );
    }

    // ------------------------------------------------------------ el enlace

    /** Programar producción con un equipo lo declara en el proyecto. */
    public function test_producir_declara_el_equipo_en_el_proyecto(): void
    {
        $p = $this->proyecto();
        $equipo = $this->impresora();

        $this->assertCount(0, $p->assets);

        app(ProduccionService::class)->programar(
            $p, $equipo, $this->persona(),
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(22, 0),
        );

        $this->assertTrue($p->fresh()->assets->contains($equipo));
    }

    /** Y programar dos veces no lo declara dos veces. */
    public function test_el_equipo_no_se_declara_por_duplicado(): void
    {
        $p = $this->proyecto();
        $equipo = $this->impresora();
        $quien = $this->persona();
        $servicio = app(ProduccionService::class);

        $servicio->programar($p, $equipo, $quien,
            now()->addDay()->setTime(8, 0), now()->addDay()->setTime(10, 0));
        $servicio->programar($p, $equipo, $quien,
            now()->addDay()->setTime(11, 0), now()->addDay()->setTime(13, 0));

        $this->assertCount(1, $p->fresh()->assets);
        $this->assertCount(2, $p->fresh()->producciones);
    }

    /** Terminar antes cuesta menos: se cobra lo que ocupó, no lo que se pidió. */
    public function test_terminar_antes_ajusta_el_costo(): void
    {
        $produccion = app(ProduccionService::class)->programar(
            $this->proyecto(), $this->impresora(), $this->persona(),
            now()->subHours(4), now()->addHours(4),
        );

        $produccion->update(['estimated_cost_minor' => 8000]);

        app(ProduccionService::class)->terminar($produccion->fresh(), now());

        $cerrada = $produccion->fresh();

        $this->assertSame('completada', $cerrada->status);
        $this->assertSame(4000, (int) $cerrada->actual_cost_minor, 'Duró la mitad: cuesta la mitad.');
    }

    public function test_el_tablero_muestra_la_produccion(): void
    {
        $p = $this->proyecto();

        app(ProduccionService::class)->programar(
            $p, $this->impresora(), $this->persona(),
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(23, 0),
            'Carcasa v3, 4 piezas',
        );

        $mira = $this->persona();
        $mira->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_CONSULTOR, 'web'));

        $this->actingAs($mira->fresh())
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Máquina')
            ->assertSee('Carcasa v3, 4 piezas')
            ->assertSee('no aparece libre para nadie más');
    }
}
