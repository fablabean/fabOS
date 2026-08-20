<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
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
use App\Services\Shop\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
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

        $this->assertTrue($this->tablero()->alertas()->isEmpty());
    }

    public function test_avisa_de_un_equipo_detenido(): void
    {
        $equipo = $this->equipo();
        app(MaintenanceService::class)->reportarFalla($equipo, $this->persona(), 'No calienta', detieneElEquipo: true);

        $alerta = $this->tablero()->alertas()->firstWhere('titulo', 'Equipos fuera de servicio');

        $this->assertNotNull($alerta);
        $this->assertSame(1, $alerta['cuantos']);
        $this->assertSame('/admin/work-orders', $alerta['url'], 'cada alerta lleva a donde se resuelve');
    }

    public function test_avisa_de_insumos_bajo_minimos(): void
    {
        Supply::create(['name' => 'Filamento', 'unit' => 'kg', 'stock' => 1, 'reorder_point' => 5]);
        Supply::create(['name' => 'Resina', 'unit' => 'ml', 'stock' => 900, 'reorder_point' => 500]);

        $alerta = $this->tablero()->alertas()->firstWhere('titulo', 'Insumos bajo mínimos');

        $this->assertSame(1, $alerta['cuantos']);
    }

    public function test_avisa_de_encargos_sin_cotizar_y_vencidos(): void
    {
        $cliente = $this->persona();
        $produccion = app(ProductionService::class);

        $produccion->pedir($cliente, ['title' => 'Sin cotizar']);

        $vencido = $produccion->pedir($cliente, ['title' => 'Prometido para ayer']);
        $produccion->cotizar($vencido, 1000, null, now()->subDays(2)->toDateString());
        $produccion->aceptar($vencido->refresh());

        $alertas = $this->tablero()->alertas();

        $this->assertSame(1, $alertas->firstWhere('titulo', 'Encargos sin cotizar')['cuantos']);
        $this->assertSame(1, $alertas->firstWhere('titulo', 'Encargos pasados de fecha')['cuantos']);
    }

    public function test_avisa_de_proyectos_sin_responsable(): void
    {
        app(ProjectService::class)->registrarIdea(['name' => 'Idea suelta']);

        $conResponsable = app(ProjectService::class)->registrarIdea(['name' => 'Con dueño']);
        $conResponsable->update(['lead_id' => $this->persona()->id]);

        $alerta = $this->tablero()->alertas()->firstWhere('titulo', 'Proyectos sin responsable');

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
            ->withSession(['segundo_factor_verificado' => true])
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
}
