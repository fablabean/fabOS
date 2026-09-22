<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\ProduccionesRelationManager;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ReservationSupply;
use App\Models\RiskFamily;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProduccionService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gastar un trozo de lámina, no la hoja entera (§13).
 *
 * El MDF se compra en hojas de 120×90 y se corta en pedazos de 30×40. Al
 * cerrar una producción solo se podía declarar «cuántas láminas», y de una
 * hoja no se gastó una: se gastó una novena parte. O se anotaba la hoja
 * entera —y el inventario descontaba de más y el proyecto pagaba de más— o
 * había que hacer la regla de tres a mano.
 */
class TrozoDeLaminaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($u);
        $factores->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private ?Area $area = null;

    /** Una sola área por prueba: el escaneo solo ofrece los insumos de la suya. */
    private function area(): Area
    {
        return $this->area ??= Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Corte']);
    }

    private function mdf(array $cambios = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'MDF 5.5 mm', 'unit' => 'lámina', 'kind' => 'insumo',
            'largo_cm' => 120, 'ancho_cm' => 90, 'area_id' => $this->area()->id,
            'stock' => 10, 'last_cost' => 50_000, 'is_active' => true,
        ], $cambios));
    }

    private function impresora(): Asset
    {
        $area = $this->area();
        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Láser',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'name' => 'Láser ' . uniqid(), 'slug' => 'laser-' . uniqid(),
            'area_id' => $area->id, 'risk_family_id' => $familia->id,
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 15, 'autonomous_minutes' => 720, 'max_minutes' => 1440,
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1],
        );

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    // ------------------------------------------------------------- la cuenta

    public function test_un_trozo_es_la_fraccion_de_hoja_que_ocupa(): void
    {
        $mdf = $this->mdf();

        $this->assertTrue($mdf->seMideEnLamina());
        $this->assertSame('120 × 90 cm', $mdf->formato());
        $this->assertSame(10_800.0, $mdf->areaDeLaLamina());

        // 30×40 son 1.200 cm² de 10.800: una novena parte.
        $this->assertSame(0.1111, $mdf->laminasDeUnTrozo(30, 40));

        // Y la hoja entera sigue siendo una.
        $this->assertSame(1.0, $mdf->laminasDeUnTrozo(120, 90));
    }

    public function test_sin_las_medidas_no_se_puede_calcular_el_trozo(): void
    {
        $filamento = Supply::create([
            'name' => 'PLA', 'unit' => 'g', 'kind' => 'insumo', 'stock' => 1000, 'is_active' => true,
        ]);

        $this->assertFalse($filamento->seMideEnLamina());
        $this->assertNull($filamento->formato());
        $this->assertNull($filamento->laminasDeUnTrozo(30, 40));

        // Y con una sola medida tampoco: media regla de tres no es ninguna.
        $this->assertNull($this->mdf(['ancho_cm' => null])->laminasDeUnTrozo(30, 40));
    }

    public function test_un_recorte_pequeno_ya_no_se_guarda_como_cero(): void
    {
        $mdf = $this->mdf();
        $equipo = $this->impresora();
        $proyecto = app(ProjectService::class)->registrarIdea(['name' => 'Llavero']);

        $produccion = app(ProduccionService::class)->programar(
            $equipo, $this->persona(), now()->addHour(), now()->addHours(2), $proyecto,
        );

        // 5×5 en una hoja de 120×90: 0,0023 de lámina. Con tres decimales se
        // guardaba 0,002, y a la décima de eso se llegaba a cero.
        app(ProduccionService::class)->terminar($produccion, now()->addHours(2), [
            $mdf->id => ['cantidad' => $mdf->laminasDeUnTrozo(5, 5), 'origen' => ReservationSupply::INVENTARIO],
        ]);

        $anotado = ReservationSupply::where('reservation_id', $produccion->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.0023, (float) $anotado->quantity, 0.00001);
    }

    // ------------------------------------------------------------ la pantalla

    public function test_al_cerrar_se_declara_el_trozo_y_sale_la_fraccion(): void
    {
        $this->admin();

        $mdf = $this->mdf();
        $equipo = $this->impresora();
        $proyecto = app(ProjectService::class)->registrarIdea(['name' => 'Carcasa']);

        $produccion = app(ProduccionService::class)->programar(
            $equipo, $this->persona(), now()->addHour(), now()->addHours(2), $proyecto,
        );

        $fila = 'mountedActions.0.data.materiales.trozo';

        $pantalla = Livewire::test(ProduccionesRelationManager::class, [
            'ownerRecord' => $proyecto,
            'pageClass'   => EditProject::class,
        ])
            ->mountTableAction('terminar', $produccion)
            ->set('mountedActions.0.data.materiales', [
                'trozo' => [
                    'supply_id' => $mdf->id, 'cantidad' => null,
                    'origen' => ReservationSupply::INVENTARIO, 'largo' => null, 'ancho' => null,
                ],
            ]);

        // Escribir el trozo llena «Cuánto»: nadie tiene que dividir 1.200
        // entre 10.800 con el modal abierto.
        $pantalla->set($fila . '.largo', 30)->set($fila . '.ancho', 40);

        $this->assertEquals(0.1111, $pantalla->get($fila . '.cantidad'));

        $pantalla->callMountedTableAction()->assertHasNoTableActionErrors();

        $anotado = ReservationSupply::where('reservation_id', $produccion->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.1111, (float) $anotado->quantity, 0.00001);

        // Y del inventario se fue el trozo, no la hoja.
        $this->assertEqualsWithDelta(10 - 0.1111, (float) $mdf->fresh()->stock, 0.01);
    }

    /*
     * La misma cuenta desde el QR del equipo —donde quien usa la máquina
     * declara lo que gastó— se defiende en MaterialEnReservaTest, que es
     * donde vive esa pantalla.
     */
}
