<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Auth\TwoFactorService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Evidencia fotográfica en las órdenes de mantenimiento (§8). */
class EvidenciaMantenimientoTest extends TestCase
{
    use RefreshDatabase;

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);

        return Asset::create([
            'area_id' => $area->id, 'name' => 'Equipo ' . uniqid(),
            'kind' => 'fijo', 'status' => 'operativo',
        ]);
    }

    public function test_una_orden_guarda_varias_fotos(): void
    {
        Mail::fake();
        $orden = app(MaintenanceService::class)
            ->reportarFalla($this->equipo(), $this->persona(), 'Se atasca el eje');

        $orden->update(['photos' => ['mantenimiento/antes.jpg', 'mantenimiento/despues.jpg']]);

        $this->assertCount(2, $orden->fresh()->photos);
        $this->assertSame('mantenimiento/antes.jpg', $orden->fresh()->photos[0]);
    }

    public function test_una_orden_sin_fotos_no_se_rompe(): void
    {
        Mail::fake();
        $orden = app(MaintenanceService::class)
            ->reportarFalla($this->equipo(), $this->persona(), 'Ruido raro');

        $this->assertNull($orden->fresh()->photos);
    }

    public function test_cerrar_una_orden_conserva_la_evidencia(): void
    {
        Mail::fake();
        $servicio = app(MaintenanceService::class);
        $orden = $servicio->reportarFalla($this->equipo(), $this->persona(), 'Fusible', detieneElEquipo: true);
        $orden->update(['photos' => ['mantenimiento/fusible.jpg']]);

        $cerrada = $servicio->cerrar($orden->fresh(), 'Se cambió el fusible');

        // Cerrar no debe pisar lo que documenta el trabajo.
        $this->assertSame(['mantenimiento/fusible.jpg'], $cerrada->photos);
        $this->assertSame('cerrada', $cerrada->status);
    }

    public function test_el_formulario_de_la_orden_pide_evidencia(): void
    {
        Mail::fake();
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $orden = app(MaintenanceService::class)
            ->reportarFalla($this->equipo(), $admin, 'Se atasca el eje');

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession(['segundo_factor_verificado' => true])
            ->get('/admin/work-orders/' . $orden->id . '/edit')
            ->assertOk()
            ->assertSee('Evidencia fotográfica')
            ->assertSee('Diagnóstico')
            ->assertSee('Trabajo realizado');
    }
}
