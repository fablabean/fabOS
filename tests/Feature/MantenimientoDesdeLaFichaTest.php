<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Models\Area;
use App\Models\Asset;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A mantenimiento solo se llega con una orden (§8).
 *
 * La fuente de voltaje del 10/09 se marcó «en mantenimiento» a mano desde su
 * ficha y quedó detenida sin orden: la alerta del tablero decía 1 y en
 * Órdenes de trabajo no había nada.
 */
class MantenimientoDesdeLaFichaTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        $this->actingAs($admin);

        $area = Area::create(['slug' => 'e-' . uniqid(), 'name' => 'Electrónica']);
        $this->equipo = Asset::create([
            'area_id' => $area->id, 'name' => 'Fuente voltaje 4', 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true,
        ]);
    }

    public function test_la_ficha_ofrece_crear_la_orden_con_el_equipo(): void
    {
        Livewire::test(EditAsset::class, ['record' => $this->equipo->getRouteKey()])
            ->assertActionVisible('orden')
            ->assertActionHasUrl('orden', \App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('create', ['equipo' => $this->equipo->id]));
    }

    public function test_en_mantenimiento_no_se_elige_a_mano(): void
    {
        Livewire::test(EditAsset::class, ['record' => $this->equipo->getRouteKey()])
            ->fillForm(['status' => 'mantenimiento'])
            ->call('save');

        $this->assertSame('operativo', $this->equipo->fresh()->status);
    }

    /** Creada desde el panel con paro, la orden detiene el equipo de verdad. */
    public function test_la_orden_del_panel_con_paro_detiene_el_equipo(): void
    {
        Livewire::withQueryParams(['equipo' => $this->equipo->id])
            ->test(CreateWorkOrder::class)
            ->assertSchemaStateSet(['asset_id' => $this->equipo->id])
            ->fillForm([
                'asset_id'        => $this->equipo->id,
                'reported_issue'  => 'No enciende',
                'stops_equipment' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('mantenimiento', $this->equipo->fresh()->status);
        $this->assertNotNull(WorkOrder::first()->down_since);

        // Y cerrarla lo devuelve.
        app(MaintenanceService::class)->cerrar(WorkOrder::first(), 'Cambio de fusible');
        $this->assertSame('operativo', $this->equipo->fresh()->status);
    }

    /** Mientras una orden lo detiene, el estado no se cambia desde la ficha. */
    public function test_detenido_por_una_orden_el_estado_lo_devuelve_la_orden(): void
    {
        app(MaintenanceService::class)->reportarFalla($this->equipo, auth()->user(), 'No enciende', detieneElEquipo: true);

        Livewire::test(EditAsset::class, ['record' => $this->equipo->getRouteKey()])
            ->assertFormFieldIsDisabled('status')
            ->fillForm(['status' => 'operativo'])
            ->call('save');

        $this->assertSame('mantenimiento', $this->equipo->fresh()->status);
    }
}
