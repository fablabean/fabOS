<?php

namespace Tests\Feature;

use App\Filament\Resources\Wishes\Pages\ListWishes;
use App\Models\Area;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Wish;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de la lista de deseos responden como una persona las usaría (§13). */
class BackofficeDeseosTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole($rol);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function admin(): User
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->entra($u);

        return $u;
    }

    private function deseo(array $datos = []): Wish
    {
        return Wish::create(array_merge([
            'target_year' => (int) now()->year + 1,
            'description' => 'Fresadora CNC',
            'quantity'    => 1,
            'unit_price'  => 12_000_000,
        ], $datos));
    }

    // ------------------------------------------------------------ la pantalla

    public function test_la_lista_se_ve_y_agrupa_por_area(): void
    {
        $this->admin();
        $area = Area::create(['slug' => 'fab-' . uniqid(), 'name' => 'Fabricación']);
        $this->deseo(['area_id' => $area->id, 'description' => 'Fresadora CNC de 3 ejes']);

        $this->get('/admin/wishes')
            ->assertOk()
            ->assertSee('Fresadora CNC de 3 ejes')
            ->assertSee('Fabricación');
    }

    public function test_el_resumen_del_ano_se_ve_encima_del_listado(): void
    {
        $this->admin();
        $this->deseo(['unit_price' => 12_000_000]);

        $this->get('/admin/wishes')
            ->assertOk()
            ->assertSee('Lo que la lista pide para')
            ->assertSee('$12.000.000');
    }

    public function test_el_resumen_avisa_de_los_deseos_sin_cotizar(): void
    {
        $this->admin();
        $this->deseo(['description' => 'Licencia por averiguar', 'unit_price' => null]);

        // A la vista: quien tome el total por completo pedirá de menos.
        $this->get('/admin/wishes')->assertOk()->assertSee('sin cotizar');
    }

    public function test_los_filtros_de_ano_y_estado_se_pueden_aplicar(): void
    {
        $this->admin();
        $esteAno = $this->deseo(['target_year' => (int) now()->year + 1]);
        $otroAno = $this->deseo(['target_year' => (int) now()->year + 5, 'description' => 'Torno']);

        Livewire::test(ListWishes::class)
            ->filterTable('target_year', (int) now()->year + 1)
            ->assertCanSeeTableRecords([$esteAno])
            ->assertCanNotSeeTableRecords([$otroAno]);

        foreach (array_keys(Wish::ESTADOS) as $estado) {
            Livewire::test(ListWishes::class)->filterTable('estado', $estado)->assertOk();
        }
    }

    public function test_el_filtro_de_estado_separa_lo_pedido_de_lo_que_sigue_en_la_lista(): void
    {
        $quien = $this->admin();
        $enLista = $this->deseo(['description' => 'Sigue esperando']);
        $pedido = $this->deseo(['description' => 'Ya se pidió']);

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$pedido->getKey()])
            ->callAction(TestAction::make('pasarACompra')->table()->bulk());

        Livewire::test(ListWishes::class)
            ->filterTable('estado', 'abierto')
            ->assertCanSeeTableRecords([$enLista])
            ->assertCanNotSeeTableRecords([$pedido]);

        Livewire::test(ListWishes::class)
            ->filterTable('estado', 'en_solicitud')
            ->assertCanSeeTableRecords([$pedido])
            ->assertCanNotSeeTableRecords([$enLista]);
    }

    // ----------------------------------------------------------- pasar a compra

    public function test_pasar_a_compra_por_lotes_arma_el_carrito_y_lleva_a_editarlo(): void
    {
        $this->admin();
        $a = $this->deseo(['description' => 'Fresadora CNC']);
        $b = $this->deseo(['description' => 'Resina flexible', 'quantity' => 6, 'unit_price' => 180_000]);

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$a->getKey(), $b->getKey()])
            ->callAction(TestAction::make('pasarACompra')->table()->bulk())
            ->assertHasNoActionErrors()
            ->assertNotified();

        $carrito = PurchaseRequest::latest('id')->first();

        $this->assertNotNull($carrito);
        $this->assertSame('borrador', $carrito->status);
        $this->assertSame(2, $carrito->items()->count());
        $this->assertSame($carrito->items()->first()->id, $a->fresh()->purchase_request_item_id);
    }

    public function test_pasar_a_compra_sin_deseos_validos_avisa_y_no_crea_carrito(): void
    {
        $this->admin();
        $descartado = $this->deseo(['discarded_at' => now(), 'discarded_reason' => 'No cabe']);

        Livewire::test(ListWishes::class)
            ->filterTable('estado', 'descartado')
            ->selectTableRecords([$descartado->getKey()])
            ->callAction(TestAction::make('pasarACompra')->table()->bulk())
            ->assertNotified();

        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_un_consultor_no_puede_pasar_deseos_a_compra(): void
    {
        // Ve la lista, pero armar un carrito es de quien compra.
        $this->entra($this->conRol(User::ROL_CONSULTOR));
        $deseo = $this->deseo();

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$deseo->getKey()])
            ->assertActionHidden(TestAction::make('pasarACompra')->table()->bulk());

        $this->assertSame(0, PurchaseRequest::count());
    }

    // -------------------------------------------------------- mover y descartar

    public function test_mover_de_ano_por_lotes_cambia_el_ano_destino(): void
    {
        $this->admin();
        $deseo = $this->deseo(['target_year' => (int) now()->year + 1]);

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$deseo->getKey()])
            ->callAction(TestAction::make('pasarDeAno')->table()->bulk(), ['ano' => 2030])
            ->assertHasNoActionErrors();

        $this->assertSame(2030, (int) $deseo->fresh()->target_year);
    }

    public function test_descartar_por_lotes_exige_motivo(): void
    {
        $this->admin();
        $deseo = $this->deseo();

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$deseo->getKey()])
            ->callAction(TestAction::make('descartar')->table()->bulk(), ['motivo' => ''])
            ->assertHasActionErrors(['motivo']);

        $this->assertNull($deseo->fresh()->discarded_at);

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$deseo->getKey()])
            ->callAction(TestAction::make('descartar')->table()->bulk(), ['motivo' => 'Se compró usado'])
            ->assertHasNoActionErrors();

        $this->assertNotNull($deseo->fresh()->discarded_at);
        $this->assertSame('descartado', $deseo->fresh()->estado());
    }

    // -------------------------------------------------------- el presupuesto

    public function test_crear_los_presupuestos_desde_la_lista_los_deja_en_borrador_uno_por_rubro(): void
    {
        config(['fabos.money.tax_rate' => 0.19]);
        $this->admin();
        $ano = (int) now()->year + 1;
        $this->deseo(['target_year' => $ano, 'budget_line' => 'Materiales laboratorio', 'unit_price' => 10_000_000]);
        $this->deseo(['target_year' => $ano, 'budget_line' => 'Licencias y software', 'unit_price' => 2_000_000]);

        Livewire::test(ListWishes::class)
            ->callAction('presupuestar', [
                'ano'     => $ano,
                'rubros'  => ['Materiales laboratorio', 'Licencias y software'],
                'area_id' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, Budget::where('year', $ano)->count());

        $materiales = Budget::where('name', 'Materiales laboratorio')->first();
        $this->assertNotNull($materiales);
        $this->assertSame('borrador', $materiales->status, 'es una propuesta, no plata asignada');
        $this->assertSame(11_900_000, (int) $materiales->amount);
        $this->assertStringContainsString('lista de deseos', $materiales->notes);
    }

    public function test_el_rubro_se_puede_asignar_por_lotes(): void
    {
        $this->admin();
        // El rubro sale de un presupuesto que ya existe, que es de donde salen
        // todos: así se clasifica una lista ya escrita sin ir de uno en uno.
        Budget::create([
            'name' => 'Herramientas y accesorios', 'year' => (int) now()->year,
            'amount' => 1_000_000, 'status' => 'vigente',
        ]);
        $a = $this->deseo();
        $b = $this->deseo(['description' => 'Torno']);

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$a->getKey(), $b->getKey()])
            ->callAction(TestAction::make('asignarRubro')->table()->bulk(), [
                'budget_line' => 'Herramientas y accesorios',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Herramientas y accesorios', $a->fresh()->budget_line);
        $this->assertSame('Herramientas y accesorios', $b->fresh()->budget_line);
    }

    public function test_un_rubro_que_no_existe_no_se_puede_elegir(): void
    {
        $this->admin();
        $deseo = $this->deseo();

        Livewire::test(ListWishes::class)
            ->selectTableRecords([$deseo->getKey()])
            ->callAction(TestAction::make('asignarRubro')->table()->bulk(), [
                'budget_line' => 'Materiales laboratoria',
            ])
            ->assertHasActionErrors(['budget_line']);

        // El nombre del rubro es el puente entre un año y el siguiente: una
        // errata lo rompe en silencio y los años dejan de poder compararse.
        $this->assertNull($deseo->fresh()->budget_line);
    }

    public function test_el_filtro_de_rubro_se_puede_aplicar(): void
    {
        $this->admin();
        $conRubro = $this->deseo(['budget_line' => 'Materiales laboratorio']);
        $otro = $this->deseo(['description' => 'Torno', 'budget_line' => 'Licencias y software']);

        Livewire::test(ListWishes::class)
            ->filterTable('budget_line', 'Materiales laboratorio')
            ->assertCanSeeTableRecords([$conRubro])
            ->assertCanNotSeeTableRecords([$otro]);
    }

    public function test_el_resumen_reparte_el_ano_por_rubro(): void
    {
        $this->admin();
        $this->deseo(['budget_line' => 'Herramientas y accesorios', 'unit_price' => 12_000_000]);

        $this->get('/admin/wishes')
            ->assertOk()
            ->assertSee('Rubro del presupuesto')
            ->assertSee('Herramientas y accesorios');
    }

    // ------------------------------------------------------------ documentado

    public function test_las_reglas_de_la_lista_de_deseos_quedan_documentadas(): void
    {
        $this->admin();

        $this->get('/admin/reglas')
            ->assertOk()
            ->assertSee('La lista de deseos')
            ->assertSee('La lista es el filtro');
    }
}
