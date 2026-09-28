<?php

namespace Tests\Feature;

use App\Filament\Pages\PautaPreventiva;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Area;
use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Maintenance\MaintenanceService;
use App\Support\MenuDelPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La pauta preventiva de los activos fijos, y lo que la acompaña (§8). */
class PautaPreventivaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        $this->actingAs($admin);

        $this->area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Taller']);
    }

    private function activo(string $nombre, string $tipo = 'fijo', array $mas = []): Asset
    {
        return Asset::create($mas + [
            'area_id' => $this->area->id, 'name' => $nombre, 'kind' => $tipo,
            'status' => 'operativo', 'is_reservable' => true,
        ]);
    }

    public function test_la_pauta_crea_un_plan_por_franja_con_sus_equipos(): void
    {
        $laser = $this->activo('Láser');
        $cnc = $this->activo('CNC');
        $prusa = $this->activo('Prusa');

        Livewire::test(PautaPreventiva::class)
            ->set('datos.mensual.equipos', [(string) $laser->id, (string) $prusa->id])
            ->set('datos.mensual.puntos', "Limpiar lente\nRevisar extractor")
            ->set('datos.semestral.equipos', [(string) $cnc->id])
            ->call('save')
            ->assertHasNoErrors();

        $mensual = MaintenancePlan::where('pauta', 'mensual')->firstOrFail();
        $this->assertSame(30, (int) $mensual->every_days);
        $this->assertEqualsCanonicalizing([$laser->id, $prusa->id], $mensual->assets()->pluck('assets.id')->all());
        $this->assertSame(['Limpiar lente', 'Revisar extractor'], $mensual->puntos());

        $this->assertSame(180, (int) MaintenancePlan::where('pauta', 'semestral')->value('every_days'));

        // Mover la Prusa a la semestral es editar la pauta, en bloque.
        Livewire::test(PautaPreventiva::class)
            ->set('datos.mensual.equipos', [(string) $laser->id])
            ->set('datos.semestral.equipos', [(string) $cnc->id, (string) $prusa->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$laser->id], $mensual->fresh()->assets()->pluck('assets.id')->all());
        $this->assertSame(2, MaintenancePlan::where('pauta', 'semestral')->first()->assets()->count());
    }

    public function test_un_equipo_va_en_una_sola_franja(): void
    {
        $laser = $this->activo('Láser');

        Livewire::test(PautaPreventiva::class)
            ->set('datos.mensual.equipos', [(string) $laser->id])
            ->set('datos.trimestral.equipos', [(string) $laser->id])
            ->call('save')
            ->assertHasErrors(['datos.trimestral.equipos']);
    }

    /** Las órdenes se abren desde la primera revisión, no la mañana siguiente. */
    public function test_las_ordenes_esperan_la_primera_revision(): void
    {
        $tz = config('fabos.lab.timezone');
        $laser = $this->activo('Láser');
        $cnc = $this->activo('CNC');

        $plan = MaintenancePlan::create([
            'name' => 'Preventivo · cada mes', 'pauta' => 'mensual', 'every_days' => 30,
            'starts_on' => '2026-10-01', 'checklist' => ['Limpiar lente'], 'is_active' => true,
        ]);
        $plan->assets()->sync([$laser->id, $cnc->id]);

        $servicio = app(MaintenanceService::class);

        $this->assertSame(0, $servicio->generarPreventivas(Carbon::parse('2026-09-27 05:00', $tz)));
        $this->assertSame(2, $servicio->generarPreventivas(Carbon::parse('2026-10-01 05:00', $tz)));

        // Al cerrarla, la lista queda guardada punto por punto.
        $orden = WorkOrder::where('asset_id', $laser->id)->firstOrFail();
        $servicio->cerrar($orden, 'Lente limpio', ['Limpiar lente' => true]);

        $this->assertSame(['Limpiar lente' => true], $orden->fresh()->checklist_answers);
        $this->assertSame(1, $plan->workOrders()->where('status', 'cerrada')->count());
    }

    /** Un computador se presta como herramienta, pero se cuenta aparte. */
    public function test_los_computadores_se_prestan_y_se_cuentan_aparte(): void
    {
        $sala = Space::create(['slug' => 'computo', 'name' => 'Lab. Cómputo', 'type' => 'fisico', 'is_reservable' => true]);
        $pc = $this->activo('Computador 1', 'computador', ['space_id' => $sala->id]);
        $this->activo('Multímetro', 'herramienta', ['space_id' => $sala->id]);
        $this->activo('Láser');

        $this->assertTrue($pc->esHerramienta(), 'se presta como una herramienta');
        $this->assertTrue($sala->herramientasDisponibles()->whereKey($pc->id)->exists(), 'se marca dentro de la sala');

        Livewire::test(ListAssets::class)
            ->assertSee('Activos fijos')
            ->assertSee('Computadores')
            ->set('activeTab', 'computador')
            ->assertCanSeeTableRecords([$pc])
            ->assertCanNotSeeTableRecords(Asset::where('kind', '!=', 'computador')->get());
    }

    /** El número del activo, a tres cifras, y se busca con los ceros. */
    public function test_el_activo_muestra_su_numero_a_tres_cifras(): void
    {
        $laser = $this->activo('Láser');
        $cnc = $this->activo('CNC');

        $this->assertSame(str_pad((string) $laser->id, 3, '0', STR_PAD_LEFT), $laser->numero());

        Livewire::test(ListAssets::class)
            ->assertSee($laser->numero())
            ->searchTable($cnc->numero())
            ->assertCanSeeTableRecords([$cnc])
            ->assertCanNotSeeTableRecords([$laser]);
    }

    public function test_la_opcion_abierta_del_menu_va_en_negro_o_en_el_color_de_su_grupo(): void
    {
        $css = MenuDelPanel::estilos();
        $this->assertStringContainsString('.fi-sidebar-item.fi-active .fi-sidebar-item-label{color:#111827}', $css);

        MenuDelPanel::guardar([['nombre' => 'Laboratorio', 'color' => 'violeta', 'opciones' => []]]);

        $this->assertStringContainsString('[data-group-label="Laboratorio"] .fi-sidebar-item.fi-active .fi-sidebar-item-label{color:#7c3aed}', MenuDelPanel::estilos());
    }
}
