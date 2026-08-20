<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\MaintenancePlan;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkOrder;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Mantenimiento preventivo y correctivo (§8). */
class MantenimientoTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    private function persona(): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function familia(): RiskFamily
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);

        return RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);
    }

    private function equipo(?RiskFamily $rf = null): Asset
    {
        $rf ??= $this->familia();

        return Asset::create([
            'area_id' => $rf->area_id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 240, 'max_minutes' => 720,
        ]);
    }

    // ------------------------------------------------------------- correctivo

    public function test_reportar_una_falla_abre_una_orden(): void
    {
        $e = $this->equipo();

        $orden = $this->servicio()->reportarFalla($e, $this->persona(), 'Hace un ruido raro');

        $this->assertSame('correctivo', $orden->kind);
        $this->assertSame('abierta', $orden->status);
        $this->assertSame('operativo', $e->fresh()->status, 'sin paro sigue operativo');
    }

    public function test_una_falla_con_paro_saca_el_equipo_de_servicio(): void
    {
        $e = $this->equipo();

        $this->servicio()->reportarFalla($e, $this->persona(), 'No calienta', detieneElEquipo: true);

        $this->assertSame('mantenimiento', $e->fresh()->status);
    }

    public function test_un_equipo_detenido_deja_de_poder_reservarse(): void
    {
        $u = $this->persona();
        $e = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $e->risk_family_id, 'level' => 'byte']);

        // Esta es la integración que importa: no basta con anotar la avería.
        $this->servicio()->reportarFalla($e, $u, 'Rota', detieneElEquipo: true);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);

        $this->expectException(BookingException::class);
        app(BookingService::class)->reservar($u, $e->fresh(), $d, $d->copy()->addHour());
    }

    public function test_avisa_que_reservas_quedan_afectadas(): void
    {
        $u = $this->persona();
        $e = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $e->risk_family_id, 'level' => 'byte']);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        app(BookingService::class)->reservar($u, $e, $d, $d->copy()->addHour());

        $afectadas = $this->servicio()->detener($e);

        // No se cancelan solas: quién avisa y cómo reagenda lo decide la coordinación.
        $this->assertCount(1, $afectadas);
        $this->assertSame($u->id, $afectadas->first()->user_id);
    }

    public function test_cerrar_la_orden_devuelve_el_equipo_a_servicio(): void
    {
        $e = $this->equipo();
        $orden = $this->servicio()->reportarFalla($e, $this->persona(), 'Fusible', detieneElEquipo: true);

        $cerrada = $this->servicio()->cerrar($orden, 'Se cambió el fusible');

        $this->assertSame('cerrada', $cerrada->status);
        $this->assertSame('operativo', $e->fresh()->status);
        $this->assertNotNull($cerrada->minutosDeParo());
    }

    public function test_dos_averias_no_se_curan_cerrando_una(): void
    {
        $e = $this->equipo();
        $u = $this->persona();

        $a = $this->servicio()->reportarFalla($e, $u, 'Avería A', detieneElEquipo: true);
        $this->servicio()->reportarFalla($e, $u, 'Avería B', detieneElEquipo: true);

        $this->servicio()->cerrar($a, 'Resuelta A');

        $this->assertSame('mantenimiento', $e->fresh()->status, 'queda la otra abierta');
    }

    // ------------------------------------------------------------- preventivo

    public function test_genera_la_preventiva_de_un_equipo_que_nunca_se_ha_revisado(): void
    {
        $rf = $this->familia();
        $this->equipo($rf);
        $this->equipo($rf);

        MaintenancePlan::create([
            'name' => 'Limpieza trimestral', 'risk_family_id' => $rf->id, 'every_days' => 90,
            'checklist' => [['campo' => 'Boquilla limpia', 'tipo' => 'si_no']],
        ]);

        $this->assertSame(2, $this->servicio()->generarPreventivas(), 'una por equipo de la familia');
        $this->assertSame(2, WorkOrder::where('kind', 'preventivo')->count());
    }

    public function test_no_duplica_una_preventiva_ya_abierta(): void
    {
        $rf = $this->familia();
        $this->equipo($rf);

        MaintenancePlan::create([
            'name' => 'Limpieza', 'risk_family_id' => $rf->id, 'every_days' => 90,
        ]);

        $this->servicio()->generarPreventivas();
        $this->assertSame(0, $this->servicio()->generarPreventivas(), 'ya hay una abierta');
    }

    public function test_respeta_la_periodicidad_tras_cerrarla(): void
    {
        $rf = $this->familia();
        $this->equipo($rf);

        MaintenancePlan::create([
            'name' => 'Limpieza', 'risk_family_id' => $rf->id, 'every_days' => 90,
        ]);

        $this->servicio()->generarPreventivas();
        $this->servicio()->cerrar(WorkOrder::first(), 'Hecha');

        // Al día siguiente todavía no toca.
        $this->assertSame(0, $this->servicio()->generarPreventivas(now()->addDay()));

        // Tres meses después, sí.
        $this->assertSame(1, $this->servicio()->generarPreventivas(now()->addDays(91)));
    }

    public function test_el_formulario_queda_congelado_con_la_orden(): void
    {
        $rf = $this->familia();
        $this->equipo($rf);

        $plan = MaintenancePlan::create([
            'name' => 'Revisión', 'risk_family_id' => $rf->id, 'every_days' => 30,
            'checklist' => [['campo' => 'Correa tensada', 'tipo' => 'si_no']],
        ]);

        $this->servicio()->generarPreventivas();
        $orden = WorkOrder::first();

        // Cambiar el plan no debe reescribir el formulario de una orden vieja.
        $plan->update(['checklist' => [['campo' => 'Otra cosa', 'tipo' => 'texto']]]);

        $this->assertSame('Correa tensada', $orden->fresh()->checklist_snapshot[0]['campo']);
    }
}
