<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Quien gestiona el mantenimiento manda el equipo a mantenimiento desde su QR. */
class MantenimientoDesdeElQrTest extends TestCase
{
    use RefreshDatabase;

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Prusa 1', 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 240, 'max_minutes' => 720, 'qr_token' => 'prusa1',
        ]);
    }

    private function persona(?string $rol = null): User
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);

        if ($rol) {
            Role::findOrCreate($rol, 'web');
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    public function test_el_superadmin_lo_ve_y_lo_manda_a_mantenimiento(): void
    {
        $equipo = $this->equipo();
        $jefa = $this->persona(User::ROL_SUPERADMIN);

        $this->actingAs($jefa)->get('/e/prusa1')
            ->assertOk()
            ->assertSee('Mandar a mantenimiento')
            ->assertDontSee('Reportar falla');

        $this->actingAs($jefa)->from('/e/prusa1')
            ->post('/e/prusa1/mantenimiento', ['motivo' => 'Cambio de boquilla', 'tipo' => 'preventivo', 'prioridad' => 'alta'])
            ->assertRedirect('/e/prusa1');

        $this->assertSame('mantenimiento', $equipo->fresh()->status);

        $orden = WorkOrder::sole();
        $this->assertSame('preventivo', $orden->kind);
        $this->assertSame('alta', $orden->priority);
        $this->assertTrue((bool) $orden->stops_equipment);
        $this->assertSame($jefa->id, $orden->reported_by);

        $this->actingAs($jefa)->get('/e/prusa1')->assertSee('Ya está en mantenimiento')->assertSee('abrir la orden');
    }

    public function test_quien_no_gestiona_solo_reporta_la_falla(): void
    {
        $equipo = $this->equipo();
        $ana = $this->persona();

        $this->actingAs($ana)->get('/e/prusa1')
            ->assertOk()
            ->assertSee('Reportar falla')
            ->assertDontSee('Mandar a mantenimiento');

        $this->actingAs($ana)
            ->post('/e/prusa1/mantenimiento', ['motivo' => 'x', 'tipo' => 'correctivo', 'prioridad' => 'normal'])
            ->assertForbidden();

        $this->assertSame('operativo', $equipo->fresh()->status);
        $this->assertSame(0, WorkOrder::count());
    }
}
