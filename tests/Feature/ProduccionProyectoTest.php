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
    /**
     * Una produccion no se presenta, y el barrido de ausencias no la toca.
     *
     * Paso de verdad: la impresora llevaba un dia imprimiendo piezas de un
     * proyecto y el sistema la marco como «no se presento» a los quince
     * minutos de empezar, porque nadie escaneo un QR. La maquina quedo libre
     * en el catalogo con la impresion a medias. Nadie escanea para producir:
     * es el laboratorio corriendo su propia maquina.
     */
    public function test_el_barrido_de_ausencias_no_cancela_una_produccion(): void
    {
        $equipo = $this->impresora();
        $persona = $this->persona();

        // Empezo hace una hora y nadie registro llegada.
        $produccion = app(ProduccionService::class)->programar(
            $equipo, $persona, now()->subHour(), now()->addHours(5), $this->proyecto(), 'Perro Go2',
        );

        // Y una reserva normal en las mismas condiciones, para comparar: esa
        // si se libera, que para eso existe el barrido.
        $otra = $this->impresora();
        Certifab::create(['user_id' => $persona->id, 'risk_family_id' => $otra->risk_family_id, 'level' => 'byte']);
        $normal = \App\Models\Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $otra->id,
            'user_id' => $persona->id, 'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2),
        ]);

        $liberadas = app(\App\Services\Booking\AttendanceService::class)->liberarAusencias();

        $this->assertSame(1, $liberadas);
        $this->assertSame('no_show', $normal->refresh()->status);
        $this->assertSame('confirmada', $produccion->refresh()->status, 'la impresora sigue trabajando: no se suelta');
    }

    public function test_producir_saca_la_maquina_de_la_lista(): void
    {
        $equipo = $this->impresora();
        $desde = now()->addDay()->setTime(18, 0);
        $hasta = now()->addDay()->setTime(23, 59);

        $reservas = app(BookingService::class);

        $this->assertTrue($reservas->estaLibre(Asset::class, $equipo->id, $desde, $hasta));

        app(ProduccionService::class)->programar(
            $equipo, $this->persona(), $desde, $hasta, $this->proyecto(), 'Carcasa v3, 4 piezas',
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
            $equipo, $this->persona(), $desde, $hasta, $this->proyecto(),
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

        $servicio->programar($equipo, $quien,
            now()->addDay()->setTime(8, 0), now()->addDay()->setTime(14, 0), $p);

        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('ya está ocupada');

        $servicio->programar($equipo, $quien,
            now()->addDay()->setTime(12, 0), now()->addDay()->setTime(16, 0), $p);
    }

    /** Cancelarla suelta la máquina: se cayó la impresión, no se perdió el turno. */
    public function test_cancelar_una_produccion_suelta_la_maquina(): void
    {
        $equipo = $this->impresora();
        $desde = now()->addDay()->setTime(18, 0);
        $hasta = now()->addDay()->setTime(22, 0);

        $produccion = app(ProduccionService::class)->programar(
            $equipo, $this->persona(), $desde, $hasta, $this->proyecto(),
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
            $equipo, $sinCertifab,
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(23, 0),
            $this->proyecto(),
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
            $this->impresora(), $this->persona(),
            now()->addDay()->setTime(22, 0),
            now()->addDays(2)->setTime(4, 0),
            $this->proyecto(),
        );

        $this->assertSame('confirmada', $produccion->status);
        $this->assertSame(360, (int) $produccion->starts_at->diffInMinutes($produccion->ends_at));
    }

    public function test_el_rango_al_reves_no_pasa(): void
    {
        $this->expectException(ProjectException::class);

        app(ProduccionService::class)->programar(
            $this->impresora(), $this->persona(),
            now()->addDay()->setTime(14, 0), now()->addDay()->setTime(10, 0),
            $this->proyecto(),
        );
    }

    // ----------------------------------------- la pieza de un estudiante

    /**
     * El caso más común del laboratorio, y el que no tenía sitio: alguien llega
     * con un archivo, pasa por asesoría, y hay que apartar seis horas de
     * máquina. **La pieza es suya** —la reserva queda a su nombre y le aparece
     * en su cuenta— aunque la opere el asesor.
     *
     * Sin proyecto: exigir uno habría obligado a inventar un proyecto por cada
     * pieza, y los proyectos inventados ensucian el único sitio donde se mira
     * si el laboratorio entrega.
     */
    public function test_se_produce_para_un_estudiante_sin_proyecto(): void
    {
        $estudiante = $this->persona();
        $asesor = $this->persona();
        $equipo = $this->impresora();

        $produccion = app(ProduccionService::class)->programar(
            $equipo, $estudiante,
            now()->addDay()->setTime(18, 0), now()->addDays(2)->setTime(0, 0),
            null, 'Carcasa v3, 4 piezas', $asesor,
        );

        $this->assertNull($produccion->project_id, 'No hace falta inventar un proyecto.');
        $this->assertSame($estudiante->id, $produccion->user_id, 'La pieza es del estudiante.');
        $this->assertSame($asesor->id, $produccion->supervisor_id, 'Y la opera quien asesoró.');
        $this->assertTrue($produccion->esProduccion());

        // Y ocupa la máquina igual que cualquier otra.
        $this->assertFalse(app(BookingService::class)->estaLibre(
            Asset::class, $equipo->id,
            now()->addDay()->setTime(20, 0), now()->addDay()->setTime(21, 0),
        ));
    }

    /** Se cotiza con la tarifa de quien recibe la pieza, no la de quien opera. */
    public function test_se_cotiza_con_la_categoria_de_quien_recibe(): void
    {
        $barata = UserCategory::create([
            'slug' => 'estudiante-' . uniqid(), 'name' => 'Estudiante',
            'can_reserve' => true, 'rate_factor' => 0.5,
        ]);

        $estudiante = User::create([
            'name' => 'Quien pide', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $barata->id,
        ]);

        $equipo = $this->impresora();

        \App\Models\RateCard::create([
            'slug' => 'tarifa-' . uniqid(), 'name' => 'Impresión FDM',
            'rateable_type' => Asset::class, 'rateable_id' => $equipo->id,
            'basis' => 'tiempo', 'unit' => 'hora',
            'price_minor' => 1000, 'rounding_minutes' => 60, 'is_active' => true,
        ]);

        $produccion = app(ProduccionService::class)->programar(
            $equipo, $estudiante,
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(20, 0),
            null, null, $this->persona(),
        );

        // Dos horas a 1000, con factor 0,5.
        $this->assertSame(1000, (int) $produccion->estimated_cost_minor);
    }

    // ------------------------------------------------------------ el enlace

    /** Programar producción con un equipo lo declara en el proyecto. */
    public function test_producir_declara_el_equipo_en_el_proyecto(): void
    {
        $p = $this->proyecto();
        $equipo = $this->impresora();

        $this->assertCount(0, $p->assets);

        app(ProduccionService::class)->programar(
            $equipo, $this->persona(),
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(22, 0),
            $p,
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

        $servicio->programar($equipo, $quien,
            now()->addDay()->setTime(8, 0), now()->addDay()->setTime(10, 0), $p);
        $servicio->programar($equipo, $quien,
            now()->addDay()->setTime(11, 0), now()->addDay()->setTime(13, 0), $p);

        $this->assertCount(1, $p->fresh()->assets);
        $this->assertCount(2, $p->fresh()->producciones);
    }

    /** Terminar antes cuesta menos: se cobra lo que ocupó, no lo que se pidió. */
    public function test_terminar_antes_ajusta_el_costo(): void
    {
        $produccion = app(ProduccionService::class)->programar(
            $this->impresora(), $this->persona(),
            now()->subHours(4), now()->addHours(4),
            $this->proyecto(),
        );

        $produccion->update(['estimated_cost_minor' => 8000]);

        app(ProduccionService::class)->terminar($produccion->fresh(), now());

        $cerrada = $produccion->fresh();

        $this->assertSame('completada', $cerrada->status);
        $this->assertSame(4000, (int) $cerrada->actual_cost_minor, 'Duró la mitad: cuesta la mitad.');
    }

    /**
     * El material se anota al cerrar y no al programar: se consume cuando la
     * máquina corre. Descontarlo por adelantado dejaría el inventario mintiendo
     * durante las seis horas que dura la impresión, y peor aún si se cancela.
     */
    public function test_el_material_sale_del_inventario_al_cerrar(): void
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);

        $filamento = \App\Models\Supply::create([
            'area_id' => $area->id, 'name' => 'Filamento PLA', 'unit' => 'g',
            'stock' => 1000, 'last_cost' => 100, 'is_active' => true,
        ]);

        $produccion = app(ProduccionService::class)->programar(
            $this->impresora(), $this->persona(),
            now()->subHours(2), now()->addHours(2),
        );

        app(ProduccionService::class)->terminar(
            $produccion, now(), [$filamento->id => 250],
        );

        $this->assertEqualsWithDelta(750, (float) $filamento->fresh()->stock, 0.001);

        $linea = \App\Models\ReservationSupply::where('reservation_id', $produccion->id)->firstOrFail();
        $this->assertEqualsWithDelta(250, (float) $linea->quantity, 0.001);

        // El precio queda congelado en la linea: subir el costo del filamento
        // el año que viene no puede reescribir lo que costó esta pieza.
        $this->assertGreaterThan(0, (int) $linea->unit_price_minor);
        $this->assertGreaterThan(0, (int) $produccion->fresh()->actual_cost_minor);
    }

    /** Y no se puede gastar lo que no hay. */
    public function test_no_se_cierra_con_mas_material_del_que_existe(): void
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);

        $filamento = \App\Models\Supply::create([
            'area_id' => $area->id, 'name' => 'Filamento PLA', 'unit' => 'g',
            'stock' => 10, 'last_cost' => 100, 'is_active' => true,
        ]);

        $produccion = app(ProduccionService::class)->programar(
            $this->impresora(), $this->persona(),
            now()->subHours(2), now()->addHours(2),
        );

        $this->expectException(ProjectException::class);

        app(ProduccionService::class)->terminar($produccion, now(), [$filamento->id => 250]);
    }

    // -------------------------------------------------------- los archivos

    /**
     * Los archivos definitivos: el .stl y el .gcode que salieron de la máquina.
     * Son lo único que permite repetir el trabajo dentro de seis meses sin
     * volver a empezar, y valen igual con proyecto detrás o sin él.
     */
    public function test_una_produccion_guarda_sus_archivos(): void
    {
        $produccion = app(ProduccionService::class)->programar(
            $this->impresora(), $this->persona(),
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(23, 0),
        );

        $archivo = $produccion->evidence()->create([
            'kind'          => 'archivo',
            'file_path'     => 'producciones/abc123.stl',
            'original_name' => 'carcasa-v3-final.stl',
        ]);

        $this->assertCount(1, $produccion->fresh()->evidence);
        $this->assertSame('carcasa-v3-final.stl', $archivo->comoSeLlama());

        // Disco privado: la URL es la ruta con sesión, no /storage.
        $this->assertStringNotContainsString('/storage/', $archivo->enlace());
    }

    public function test_el_tablero_muestra_la_produccion(): void
    {
        $p = $this->proyecto();

        app(ProduccionService::class)->programar(
            $this->impresora(), $this->persona(),
            now()->addDay()->setTime(18, 0), now()->addDay()->setTime(23, 0),
            $p, 'Carcasa v3, 4 piezas',
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
