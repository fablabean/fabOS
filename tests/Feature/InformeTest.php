<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Budget;
use App\Models\Certifab;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** El informe de cierre que recibe la Universidad (§17). */
class InformeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function informes(): ReportService
    {
        return app(ReportService::class);
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

    private function equipo(string $area = 'Impresión 3D'): Asset
    {
        $a = Area::firstOrCreate(['slug' => str($area)->slug()->value()], ['name' => $area]);
        $rf = RiskFamily::create([
            'area_id' => $a->id, 'slug' => 'f-' . uniqid(), 'name' => 'Familia',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'area_id' => $a->id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    /** Una sesión completa: reserva, llega, trabaja y cierra. */
    private function sesion(User $u, Asset $equipo, int $minutosReservados, int $minutosUsados): void
    {
        Certifab::firstOrCreate(
            ['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id],
            ['level' => 'byte'],
        );

        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar(
            $u, $equipo, $inicio, $inicio->copy()->addMinutes($minutosReservados)
        );

        $asistencia = app(AttendanceService::class);
        $asistencia->checkIn($reserva->refresh());
        $this->travel($minutosUsados)->minutes();
        $asistencia->checkOut($reserva->refresh());
        $this->travelBack();
    }

    private function periodo(): array
    {
        return [now()->copy()->subDay(), now()->copy()->addDay()];
    }

    // ----------------------------------------------------------------- uso

    public function test_cuenta_el_uso_real_y_no_el_reservado(): void
    {
        $u = $this->persona();
        $this->sesion($u, $this->equipo(), 180, 60);

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        // Un informe que sumara lo reservado haría ver un laboratorio lleno
        // aunque la gente se hubiera ido a la hora.
        $this->assertSame(1, $informe->uso['completadas']);
        $this->assertSame(60, $informe->uso['minutos_usados']);
        $this->assertSame(180, $informe->uso['minutos_reservados']);
        $this->assertSame(33.3, $informe->aprovechamiento());
    }

    public function test_un_periodo_sin_actividad_no_divide_por_cero(): void
    {
        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        $this->assertSame(0, $informe->uso['completadas']);
        $this->assertNull($informe->aprovechamiento());
    }

    public function test_las_ausencias_pesan_en_el_aprovechamiento(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $inicio = now()->addMinutes(5);
        app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHour());

        // Nadie llega: pasa la tolerancia y se libera.
        $this->travel(2)->hours();
        app(AttendanceService::class)->liberarAusencias();
        $this->travelBack();

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        $this->assertSame(1, $informe->uso['no_show']);
        $this->assertSame(60, $informe->uso['minutos_reservados'], 'la hora bloqueada sí cuenta');
        $this->assertSame(0, $informe->uso['minutos_usados']);
        $this->assertSame(0.0, $informe->aprovechamiento());
    }

    public function test_agrupa_el_uso_por_area(): void
    {
        $u = $this->persona();
        $this->sesion($u, $this->equipo('Impresión 3D'), 60, 60);
        $this->sesion($u, $this->equipo('Corte láser'), 60, 30);

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        $this->assertSame(60, $informe->porArea['Impresión 3D']['minutos']);
        $this->assertSame(30, $informe->porArea['Corte láser']['minutos']);
        $this->assertSame('Impresión 3D', $informe->porArea->keys()->first(), 'ordenado por uso');
    }

    public function test_no_cuenta_dos_veces_el_bloque_del_acompanante(): void
    {
        $u = $this->persona();
        $this->sesion($u, $this->equipo(), 60, 60);

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        // El bloque del colaborador es la sombra de otra reserva: sumarlo
        // duplicaría las horas de uso del laboratorio.
        $this->assertSame(1, $informe->uso['reservas']);
    }

    // ------------------------------------------------------------ personas

    public function test_cuenta_personas_distintas_no_sesiones(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();
        $this->sesion($u, $equipo, 60, 60);
        $this->sesion($u, $equipo, 60, 60);

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        $this->assertSame(2, $informe->uso['completadas']);
        $this->assertSame(1, $informe->personas['atendidas'], 'la misma persona no cuenta dos veces');
        $this->assertSame(1, $informe->personas['por_categoria']['Estudiante']);
    }

    // --------------------------------------------------------- otros bloques

    public function test_recoge_habilitaciones_y_mantenimiento(): void
    {
        $u = $this->persona();
        $equipo = $this->equipo();

        Certifab::create([
            'user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id,
            'level' => 'kilo', 'granted_at' => now(),
        ]);

        $orden = app(MaintenanceService::class)->reportarFalla($equipo, $u, 'Ruido raro', detieneElEquipo: true);
        $this->travel(90)->minutes();
        app(MaintenanceService::class)->cerrar($orden, 'Se ajustó la correa');
        $this->travelBack();

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        $this->assertSame(1, $informe->formacion['certifabs']);
        $this->assertSame(1, $informe->formacion['por_nivel']['kilo']);
        $this->assertSame(1, $informe->mantenimiento['correctivas']);
        $this->assertSame(1, $informe->mantenimiento['cerradas']);
        $this->assertSame(90, $informe->mantenimiento['minutos_paro']);
        $this->assertSame(0, $informe->mantenimiento['sin_resolver']);
    }

    public function test_recoge_el_presupuesto_vigente(): void
    {
        $u = $this->persona();
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => 2026, 'amount' => 1_000_000, 'status' => 'vigente',
        ]);

        $compras = app(PurchasingService::class);
        $carrito = $compras->abrirCarrito($u, $presupuesto);
        $compras->agregar($carrito, 'Resina', 1, 100_000);
        $compras->enviar($carrito);
        $compras->aprobar($carrito->refresh(), $u);

        [$desde, $hasta] = $this->periodo();
        $informe = $this->informes()->generar($desde, $hasta);

        $this->assertSame(1_000_000, $informe->compras['aprobado']);
        $this->assertSame(119_000, $informe->compras['comprometido']);
    }

    public function test_el_mes_se_calcula_en_hora_del_laboratorio(): void
    {
        // Medianoche del 1 en Bogotá son las 05:00 UTC: si el corte se hiciera
        // en UTC, las sesiones de la última tarde del mes caerían en el otro.
        [$desde, $hasta] = $this->informes()->mesDe(Carbon::parse('2026-08-15', 'America/Bogota'));

        $this->assertSame('2026-08-01 00:00:00', $desde->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 23:59:59', $hasta->format('Y-m-d H:i:s'));
        $this->assertSame('America/Bogota', $desde->timezone->getName());
    }

    // ------------------------------------------------------------- pantallas

    public function test_la_pagina_del_informe_carga(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $this->sesion($this->persona(), $this->equipo(), 120, 90);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/informes')
            ->assertOk()
            ->assertSee('Uso del laboratorio')
            ->assertSee('del tiempo reservado se aprovechó');
    }

    public function test_el_informe_imprimible_es_solo_del_backoffice(): void
    {
        $this->actingAs($this->persona())
            ->get(route('informes.cierre'))
            ->assertForbidden();

        $this->actingAs($this->persona(User::ROL_CONSULTOR))
            ->get(route('informes.cierre'))
            ->assertOk()
            ->assertSee('Informe de operación')
            ->assertSee('Uso del laboratorio');
    }

    public function test_el_informe_imprimible_acepta_un_rango(): void
    {
        $this->sesion($this->persona(), $this->equipo(), 60, 45);

        $this->actingAs($this->persona(User::ROL_CONSULTOR))
            ->get(route('informes.cierre', [
                'desde' => now()->subDay()->format('Y-m-d'),
                'hasta' => now()->addDay()->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertSee('45 min');
    }
}
