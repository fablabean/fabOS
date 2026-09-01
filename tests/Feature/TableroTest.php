<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Budget;
use App\Models\Certifab;
use App\Models\Project;
use App\Models\RiskFamily;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Projects\ProjectService;
use App\Services\Reports\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** El tablero de indicadores (§17). */
class TableroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function tablero(): DashboardService
    {
        return app(DashboardService::class);
    }

    /**
     * Quien lo ve todo.
     *
     * Las alertas y el dinero preguntan a la matriz de accesos, así que una
     * llamada sin persona no devuelve nada -que es el fallo seguro-. Las
     * pruebas que miran el CONTENIDO piden con quien puede verlo; las que
     * miran el cierre, con quien no.
     */
    private function admin(): User
    {
        return $this->persona(User::ROL_ADMINISTRADOR);
    }

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    // ---------------------------------------------------------------- ahora

    public function test_cuenta_lo_que_esta_pasando_hoy(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHour());
        app(AttendanceService::class)->checkIn($reserva->refresh());

        $ahora = $this->tablero()->ahora();

        $this->assertSame(1, $ahora['en_uso']);
        $this->assertSame(1, $ahora['reservas_hoy']);
        $this->assertSame(1, $ahora['personas_hoy']);
        $this->assertSame(0, $ahora['en_mantenimiento']);
    }

    // -------------------------------------------------------------- alertas

    public function test_un_laboratorio_al_dia_no_inventa_alertas(): void
    {
        $this->equipo();

        $this->assertTrue($this->tablero()->alertas($this->admin())->isEmpty());
    }

    public function test_avisa_de_un_equipo_detenido(): void
    {
        $equipo = $this->equipo();
        app(MaintenanceService::class)->reportarFalla($equipo, $this->persona(), 'No calienta', detieneElEquipo: true);

        $alerta = $this->tablero()->alertas($this->admin())->firstWhere('titulo', 'Equipos fuera de servicio');

        $this->assertNotNull($alerta);
        $this->assertSame(1, $alerta['cuantos']);
        $this->assertSame('/admin/work-orders', $alerta['url'], 'cada alerta lleva a donde se resuelve');
    }

    public function test_avisa_de_insumos_bajo_minimos(): void
    {
        Supply::create(['name' => 'Filamento', 'unit' => 'kg', 'stock' => 1, 'reorder_point' => 5]);
        Supply::create(['name' => 'Resina', 'unit' => 'ml', 'stock' => 900, 'reorder_point' => 500]);

        $alerta = $this->tablero()->alertas($this->admin())->firstWhere('titulo', 'Insumos bajo mínimos');

        $this->assertSame(1, $alerta['cuantos']);
    }

    public function test_avisa_de_proyectos_sin_responsable(): void
    {
        app(ProjectService::class)->registrarIdea(['name' => 'Idea suelta']);

        $conResponsable = app(ProjectService::class)->registrarIdea(['name' => 'Con dueño']);
        $conResponsable->update(['lead_id' => $this->persona()->id]);

        $alerta = $this->tablero()->alertas($this->admin())->firstWhere('titulo', 'Proyectos sin responsable');

        $this->assertSame(1, $alerta['cuantos']);
        $this->assertSame(1, Project::whereNotNull('lead_id')->count());
    }

    // ------------------------------------------------------------ tendencia

    public function test_la_tendencia_cubre_las_semanas_pedidas(): void
    {
        $tendencia = $this->tablero()->tendencia(8);

        $this->assertCount(8, $tendencia);
        $this->assertSame(0, $tendencia->sum('minutos'), 'sin sesiones, todo en cero');
    }

    public function test_la_tendencia_cuenta_el_uso_real(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHours(3));

        $asistencia = app(AttendanceService::class);
        $asistencia->checkIn($reserva->refresh());
        $this->travel(45)->minutes();
        $asistencia->checkOut($reserva->refresh());
        $this->travelBack();

        // 45 minutos de reloj, no las tres horas reservadas.
        $this->assertSame(45, $this->tablero()->tendencia()->sum('minutos'));
    }

    // ------------------------------------------------------------ pantalla

    public function test_el_tablero_carga_y_es_del_backoffice(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        Supply::create(['name' => 'Filamento', 'unit' => 'kg', 'stock' => 1, 'reorder_point' => 5]);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/tablero')
            ->assertOk()
            ->assertSee('Qué necesita atención')
            ->assertSee('Insumos bajo mínimos')
            ->assertSee('Ahora mismo');
    }

    public function test_quien_no_entra_al_backoffice_no_ve_el_tablero(): void
    {
        $this->actingAs($this->persona())
            ->get('/admin/tablero')
            ->assertForbidden();
    }

    // ------------------------------------------------------- lo que no se ve

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        return $this;
    }

    /**
     * El consultor tal como está configurado en el laboratorio: una sola llave.
     *
     * Se sincroniza el ROL y no la persona. Es la distinción que hacía pasar
     * esta prueba sin que nada estuviera cerrado: los permisos directos se
     * suman a los del rol, no lo sustituyen, y el consultor seguía entrando
     * con todo lo que su rol trae por defecto.
     */
    private function consultorConSolaLlave(): User
    {
        Role::findByName(User::ROL_CONSULTOR, 'web')->syncPermissions(['ver.tablero']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->persona(User::ROL_CONSULTOR)->fresh();
    }

    private function conPresupuesto(): void
    {
        Budget::create([
            'name' => 'Presupuesto 2026', 'year' => 2026,
            'amount' => 87_650_000, 'status' => 'vigente',
        ]);
    }

    /**
     * El tablero es la entrada del panel y lo abre casi todo el mundo.
     *
     * Estaba resumiendo aquí, en una pantalla abierta, lo que sus propias
     * secciones tienen cerrado: un practicante entraba y leía cuánto dinero
     * hay, cuánto se comprometió y cuánto queda. Se supo porque lo contaron.
     */
    public function test_quien_no_abre_presupuestos_no_ve_el_dinero_en_el_tablero(): void
    {
        $this->conPresupuesto();

        $practicante = $this->persona(User::ROL_PRACTICANTE);

        $this->entra($practicante)->get('/admin/tablero')
            ->assertOk()
            ->assertSee('Ahora mismo')
            ->assertDontSee('presupuesto vigente')
            ->assertDontSee('comprometido')
            ->assertDontSee('87.650.000');
    }

    /** Y el consultor tampoco: mira la operación, no la caja. */
    public function test_el_consultor_tampoco_ve_el_dinero(): void
    {
        $this->conPresupuesto();

        $consultor = $this->consultorConSolaLlave();

        $this->entra($consultor)->get('/admin/tablero')
            ->assertOk()
            ->assertDontSee('87.650.000');
    }

    /** Quien sí lo abre lo sigue viendo: esto cierra, no rompe. */
    public function test_quien_abre_presupuestos_si_ve_el_dinero(): void
    {
        $this->conPresupuesto();

        $this->entra($this->admin())->get('/admin/tablero')
            ->assertOk()
            ->assertSee('presupuesto vigente')
            ->assertSee('87.650.000');
    }

    /**
     * No basta con esconderlo en la vista: la cifra viajaría igual al navegador
     * y se leería abriendo el inspector. Lo que no se puede ver, no se calcula.
     */
    public function test_el_dinero_ni_siquiera_se_calcula(): void
    {
        $this->conPresupuesto();

        $this->assertNull($this->tablero()->finanzas($this->persona(User::ROL_PRACTICANTE)));
        $this->assertNull($this->tablero()->finanzas(null));
        $this->assertNotNull($this->tablero()->finanzas($this->admin()));
    }

    /**
     * Y una alerta que lleva a una sección cerrada es dos cosas malas: un
     * callejón sin salida, y la cifra de algo que no se debía mirar.
     */
    public function test_no_se_avisa_de_lo_que_no_se_puede_abrir(): void
    {
        Supply::create(['name' => 'Filamento', 'unit' => 'kg', 'stock' => 1, 'reorder_point' => 5]);

        $consultor = $this->consultorConSolaLlave();

        $this->assertTrue(
            $this->tablero()->alertas($consultor)->isEmpty(),
            'con una sola llave -el tablero- no debería llegarle ninguna alerta',
        );

        $this->assertNotNull(
            $this->tablero()->alertas($this->admin())->firstWhere('titulo', 'Insumos bajo mínimos'),
            'y quien sí abre insumos la sigue recibiendo',
        );
    }
}
