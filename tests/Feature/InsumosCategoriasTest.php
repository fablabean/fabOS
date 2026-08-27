<?php

namespace Tests\Feature;

use App\Filament\Resources\Supplies\Pages\CreateSupply;
use App\Models\Supply;
use App\Models\SupplyCategory;
use App\Models\SupplyMovement;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Categorías de insumos, existencia inicial y política de reposición (§13).
 */
class InsumosCategoriasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ---------------------------------------------------------- categorías

    public function test_las_categorias_se_anidan_y_se_leen_por_su_ruta(): void
    {
        $madera = SupplyCategory::create(['name' => 'Madera']);
        $mdf = SupplyCategory::create(['name' => 'MDF', 'parent_id' => $madera->id]);
        $tres = SupplyCategory::create(['name' => '3 mm', 'parent_id' => $mdf->id]);

        $this->assertSame('Madera', $madera->ruta());
        $this->assertSame('Madera › MDF', $mdf->ruta());
        $this->assertSame('Madera › MDF › 3 mm', $tres->ruta());
    }

    /** Un ciclo no puede colgar la pantalla: eso no depende de que nadie se equivoque. */
    public function test_un_ciclo_no_cuelga_la_ruta(): void
    {
        $a = SupplyCategory::create(['name' => 'A']);
        $b = SupplyCategory::create(['name' => 'B', 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]);

        $this->assertNotSame('', $a->fresh()->ruta());
    }

    /**
     * Un insumo sin clasificar sigue siendo usable: obligar a categorizar antes
     * de poder anotar algo hace que se invente una categoría «Varios».
     */
    public function test_un_insumo_puede_no_tener_categoria(): void
    {
        $insumo = Supply::create(['name' => 'Algo', 'unit' => 'unidad']);

        $this->assertNull($insumo->category_id);
    }

    public function test_borrar_la_categoria_no_borra_el_insumo(): void
    {
        $cat = SupplyCategory::create(['name' => 'Madera']);
        $insumo = Supply::create(['name' => 'MDF 3mm', 'unit' => 'hoja', 'category_id' => $cat->id]);

        $cat->delete();

        $this->assertDatabaseHas('supplies', ['id' => $insumo->id]);
        $this->assertNull($insumo->fresh()->category_id);
    }

    // --------------------------------------------------- existencia inicial

    /**
     * Lo que hay en el estante existe antes que su ficha. Entra como
     * **movimiento**: escribirlo en `stock` dejaría una existencia que nadie
     * puede explicar, con el listado de movimientos empezando en cero.
     */
    public function test_la_existencia_inicial_queda_como_movimiento(): void
    {
        $this->admin();

        Livewire::test(CreateSupply::class)
            ->fillForm([
                'name' => 'Filamento PLA', 'unit' => 'g',
                'existencia_inicial' => 2500,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $insumo = Supply::where('name', 'Filamento PLA')->firstOrFail();

        $this->assertEqualsWithDelta(2500, (float) $insumo->stock, 0.001);

        $movimiento = SupplyMovement::where('supply_id', $insumo->id)->firstOrFail();

        $this->assertSame('entrada', $movimiento->kind);
        $this->assertStringContainsString('inicial', mb_strtolower((string) $movimiento->memo));
    }

    public function test_sin_existencia_inicial_no_se_inventa_un_movimiento(): void
    {
        $this->admin();

        Livewire::test(CreateSupply::class)
            ->fillForm(['name' => 'Sin nada', 'unit' => 'unidad'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('supply_movements', 0);
    }

    // ------------------------------------------------- mínimo y máximo

    /**
     * El mínimo dice cuándo comprar; el máximo, cuánto. Sin el segundo, quien
     * repone acaba comprando lo que le parece.
     */
    public function test_el_maximo_dice_cuanto_pedir(): void
    {
        $insumo = Supply::create([
            'name' => 'MDF 3mm', 'unit' => 'hoja',
            'stock' => 4, 'reorder_point' => 5, 'max_stock' => 20,
        ]);

        $this->assertTrue($insumo->estaBajoMinimo());
        $this->assertEqualsWithDelta(16, $insumo->cuantoPedir(), 0.001);
    }

    /** Sin máximo no se inventa una cantidad. */
    public function test_sin_maximo_no_dice_cuanto(): void
    {
        $insumo = Supply::create(['name' => 'Algo', 'unit' => 'unidad', 'stock' => 1, 'reorder_point' => 5]);

        $this->assertTrue($insumo->estaBajoMinimo());
        $this->assertNull($insumo->cuantoPedir());
    }

    /** Y si sobra existencia, no hay nada que pedir. */
    public function test_con_existencia_de_sobra_no_hay_que_pedir(): void
    {
        $insumo = Supply::create([
            'name' => 'Algo', 'unit' => 'unidad',
            'stock' => 30, 'reorder_point' => 5, 'max_stock' => 20,
        ]);

        $this->assertFalse($insumo->estaBajoMinimo());
        $this->assertEqualsWithDelta(0, $insumo->cuantoPedir(), 0.001);
    }

    public function test_el_maximo_no_puede_ser_menor_que_el_minimo(): void
    {
        $this->admin();

        Livewire::test(CreateSupply::class)
            ->fillForm([
                'name' => 'Al reves', 'unit' => 'unidad',
                'reorder_point' => 10, 'max_stock' => 5,
            ])
            ->call('create')
            ->assertHasFormErrors(['max_stock']);
    }

    // ------------------------------------------------ de dónde salen las cifras

    /**
     * Quien mira un presupuesto en cero tiene que poder saber cómo se llena.
     * Sin decirlo, se queda mirando el cero.
     */
    public function test_el_presupuesto_explica_de_donde_salen_sus_cifras(): void
    {
        $this->admin();

        \App\Models\Budget::create(['name' => 'Materiales', 'year' => 2026, 'amount' => 1_000_000]);

        $this->get('/admin/budgets')
            ->assertOk()
            ->assertSee('salen de las solicitudes de compra')
            ->assertSee('ejecutado de arranque');
    }
}
