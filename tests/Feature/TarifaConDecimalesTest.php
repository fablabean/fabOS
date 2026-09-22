<?php

namespace Tests\Feature;

use App\Filament\Resources\RateCards\Pages\CreateRateCard;
use App\Filament\Resources\RateCards\Pages\EditRateCard;
use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Money\QuoteService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tarifas con decimales, y escritas en pesos si se prefiere (§12).
 *
 * El caso que lo motivó: una lámina de MDF sale a unos 4 pesos el centímetro
 * cuadrado. Eso son 0,004 FabCoins, y en enteros de unidad menor se guardaba
 * como cero: el material salía gratis y nadie se enteraba hasta cuadrar la
 * caja. Aquí se defiende que el decimal llegue a la base, que se pueda escribir
 * en la moneda en que se piensa, y que el cobro siga saliendo entero.
 */
class TarifaConDecimalesTest extends TestCase
{
    use RefreshDatabase;

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

    /** Los campos que el formulario exige, para no repetirlos en cada prueba. */
    private function base(array $cambios = []): array
    {
        return array_merge([
            'name'        => 'MDF 5.5 (cm2)',
            'slug'        => 'mdf55-' . uniqid(),
            'basis'       => 'unidad',
            'unit'        => 'cm2',
            'price_minor' => '0',
        ], $cambios);
    }

    // ------------------------------------------------------------ decimales

    public function test_un_precio_por_centimetro_ya_no_se_redondea_a_cero(): void
    {
        $this->admin();

        Livewire::test(CreateRateCard::class)
            ->fillForm($this->base(['slug' => 'mdf55', 'price_minor' => '0.004']))
            ->call('create')
            ->assertHasNoFormErrors();

        $tarifa = RateCard::where('slug', 'mdf55')->firstOrFail();

        // 0,004 FabCoins son 0,4 unidades menores. Antes: 0.
        $this->assertEqualsWithDelta(0.4, $tarifa->price_minor, 0.0001);
    }

    public function test_el_material_por_centimetro_cuadrado_ya_no_sale_gratis(): void
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Corte y grabado']);
        $familia = RiskFamily::create(['area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Láser']);
        $equipo = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $familia->id,
            'name' => 'Láser', 'kind' => 'fijo', 'status' => 'operativo',
        ]);

        RateCard::create([
            'slug' => 'laser-' . uniqid(), 'name' => 'Láser', 'basis' => 'tiempo', 'unit' => 'hora',
            'rateable_type' => Area::class, 'rateable_id' => $area->id,
            'price_minor' => 0, 'rounding_minutes' => 15,
        ]);

        $mdf = RateCard::create([
            'slug' => 'mdf-' . uniqid(), 'name' => 'MDF 5.5 (cm2)', 'basis' => 'unidad', 'unit' => 'cm2',
            'price_minor' => 0.4, 'capture_currency' => 'pesos',
        ]);

        $categoria = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Categoría', 'can_reserve' => true, 'rate_factor' => 1,
        ]);
        $persona = User::create([
            'name' => 'Quien corta', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $categoria->id,
        ]);

        // Una pieza de 300 cm²: 120 unidades menores, 1,20 FabCoins.
        $cotizacion = app(QuoteService::class)->cotizar($persona, $equipo, 30, materiales: [
            ['tarifa' => $mdf, 'cantidad' => 300],
        ]);

        $this->assertSame(120, $cotizacion->totalMenor);
        // Y lo que se cobra sigue siendo un entero, no un decimal suelto.
        $this->assertIsInt($cotizacion->totalMenor);
    }

    // --------------------------------------------------------------- pesos

    public function test_la_tarifa_se_puede_escribir_en_pesos(): void
    {
        $this->admin();

        Livewire::test(CreateRateCard::class)
            ->fillForm($this->base(['slug' => 'mdf55-pesos']))
            ->set('data.capture_currency', 'pesos')
            ->set('data.price_minor', '4')
            ->call('create')
            ->assertHasNoFormErrors();

        $tarifa = RateCard::where('slug', 'mdf55-pesos')->firstOrFail();

        // 4 pesos el cm²: los mismos 0,004 FabCoins, sin traducir de cabeza.
        $this->assertEqualsWithDelta(0.4, $tarifa->price_minor, 0.0001);
        $this->assertTrue($tarifa->escribeEnPesos());
    }

    public function test_al_cambiar_de_moneda_se_convierte_lo_ya_escrito(): void
    {
        $this->admin();

        $pantalla = Livewire::test(CreateRateCard::class)
            ->fillForm($this->base(['price_minor' => '0.004', 'setup_minor' => '2']))
            ->set('data.capture_currency', 'pesos');

        $this->assertEquals(4, $pantalla->get('data.price_minor'));
        $this->assertEquals(2000, $pantalla->get('data.setup_minor'));

        // Y de vuelta, sin perder nada por el camino.
        $pantalla->set('data.capture_currency', 'fbc');

        $this->assertEquals(0.004, $pantalla->get('data.price_minor'));
        $this->assertEquals(2, $pantalla->get('data.setup_minor'));
    }

    public function test_al_reabrirla_se_ve_en_la_moneda_en_que_se_escribio(): void
    {
        $this->admin();

        $tarifa = RateCard::create([
            'slug' => 'mdf-' . uniqid(), 'name' => 'MDF 5.5 (cm2)', 'basis' => 'unidad', 'unit' => 'cm2',
            'price_minor' => 0.4, 'capture_currency' => 'pesos',
        ]);

        $pantalla = Livewire::test(EditRateCard::class, ['record' => $tarifa->getRouteKey()]);

        // En pesos, que es como se decidió: volver a ver «0,004» sería pedir
        // que se traduzca otra vez, y ahí es donde se cuela el cero.
        $this->assertEquals(4, $pantalla->get('data.price_minor'));
        $this->assertEquals('pesos', $pantalla->get('data.capture_currency'));
    }

    /**
     * La página de reglas leía los importes tipados a entero: una tarifa por
     * cm² se documentaba como «0,00», que es justo el cero que se quería
     * quitar. Sale con los decimales que tenga.
     */
    public function test_las_reglas_documentan_la_tarifa_fina_sin_redondearla(): void
    {
        RateCard::create([
            'slug' => 'mdf-' . uniqid(), 'name' => 'MDF 5.5 (cm2)', 'basis' => 'unidad', 'unit' => 'cm2',
            'price_minor' => 0.4, 'capture_currency' => 'pesos',
        ]);

        $this->admin();

        $this->get('/admin/reglas')
            ->assertOk()
            ->assertSee('MDF 5.5 (cm2)')
            ->assertSee('0,004');
    }

    public function test_una_tarifa_en_fabcoins_sigue_escribiendose_igual(): void
    {
        $this->admin();

        $tarifa = RateCard::create([
            'slug' => 'hora-' . uniqid(), 'name' => 'Hora de máquina', 'basis' => 'tiempo', 'unit' => 'hora',
            'price_minor' => 800,
        ]);

        $pantalla = Livewire::test(EditRateCard::class, ['record' => $tarifa->getRouteKey()]);

        $this->assertEquals(8, $pantalla->get('data.price_minor'));

        $pantalla->set('data.price_minor', '8.5')->call('save');

        $this->assertEqualsWithDelta(850, $tarifa->fresh()->price_minor, 0.0001);
    }
}
