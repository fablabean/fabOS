<?php

namespace Tests\Feature;

use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\Dinero;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La moneda de trabajo del panel (§12).
 *
 * Todo importe se guarda en unidades menores de FabCoin, que es como lo lleva
 * el libro contable. Lo que cambia es en qué se escribe, y cada pantalla había
 * elegido la suya: un curso en FabCoins, un servicio de la tienda en pesos,
 * una dotación en unidades menores crudas. Quien pasaba de una a otra tenía
 * que acordarse de en cuál estaba, y escribir 11.200 donde iban 11.200.000 no
 * da ningún error: da un curso regalado.
 */
class MonedaDeTrabajoTest extends TestCase
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

    private function curso(int $menor = 0): Course
    {
        return Course::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Fab Academy', 'level' => 'tera',
            'hours' => 500, 'price_minor' => $menor, 'is_active' => true, 'is_public' => true,
        ]);
    }

    // -------------------------------------------------------- la conversión

    public function test_las_dos_monedas_dicen_lo_mismo(): void
    {
        // 11.200 FabCoins son 11.200.000 pesos a mil pesos el FabCoin.
        $menor = 1_120_000;

        $this->assertSame(11_200.0, Dinero::enMoneda($menor, 'fbc'));
        $this->assertSame(11_200_000.0, Dinero::enMoneda($menor, 'pesos'));

        $this->assertSame((float) $menor, Dinero::aMenor(11_200, 'fbc'));
        $this->assertSame((float) $menor, Dinero::aMenor(11_200_000, 'pesos'));
    }

    /** Sin ceros de relleno: 0,004 no se lee «0,00», que es el mismo cero de siempre. */
    public function test_un_importe_fino_no_se_lee_como_cero(): void
    {
        $this->assertSame('0,004 FBC', Dinero::enTexto(0.4, 'fbc'));
        $this->assertSame('4 $', Dinero::enTexto(0.4, 'pesos'));
    }

    // ------------------------------------------------------------ el ajuste

    public function test_por_defecto_se_trabaja_en_fabcoins(): void
    {
        $this->assertSame('fbc', Settings::monedaDeTrabajo());
    }

    public function test_el_curso_se_escribe_en_la_moneda_de_trabajo(): void
    {
        Setting::put(Settings::MONEDA_DE_TRABAJO, 'pesos', 'finanzas');
        $this->admin();

        $curso = $this->curso(1_120_000);

        $pantalla = Livewire::test(EditCourse::class, ['record' => $curso->getRouteKey()]);

        // En pesos, que es como se está trabajando.
        $this->assertEquals(11_200_000, $pantalla->get('data.price_minor'));
        $this->assertSame('pesos', $pantalla->get('data.' . \App\Filament\Componentes\CampoDeDinero::CAMPO));

        // Y al guardar vuelve a unidades menores, intacto.
        $pantalla->set('data.price_minor', '11200000')->call('save');

        $this->assertSame(1_120_000, (int) $curso->fresh()->price_minor);
    }

    public function test_en_fabcoins_el_mismo_curso_se_escribe_distinto(): void
    {
        $this->admin();
        $curso = $this->curso(1_120_000);

        $pantalla = Livewire::test(EditCourse::class, ['record' => $curso->getRouteKey()]);

        $this->assertEquals(11_200, $pantalla->get('data.price_minor'));
    }

    /**
     * Y el selector del formulario convierte lo ya escrito.
     *
     * Cambiarlo es «déjame escribir este número en pesos», no «cambia el
     * ajuste»: lo guardado sigue siendo lo mismo.
     */
    public function test_el_selector_convierte_lo_escrito_sin_tocar_el_ajuste(): void
    {
        $this->admin();
        $curso = $this->curso(1_120_000);
        $campo = \App\Filament\Componentes\CampoDeDinero::CAMPO;

        $pantalla = Livewire::test(EditCourse::class, ['record' => $curso->getRouteKey()])
            ->set('data.' . $campo, 'pesos');

        $this->assertEquals(11_200_000, $pantalla->get('data.price_minor'));

        $pantalla->call('save');

        $this->assertSame(1_120_000, (int) $curso->fresh()->price_minor, 'lo guardado no cambia');
        $this->assertSame('fbc', Settings::monedaDeTrabajo(), 'ni el ajuste del laboratorio');
    }

    /**
     * La dotación se escribe como dinero, no en unidades menores.
     *
     * Pedía el número crudo de la base —«100 = 1 FabCoin»— y lo explicaba en
     * la ayuda, que es tanto como pedir que se haga la cuenta a mano cada vez.
     * Un cero de más ahí es una dotación diez veces mayor para todo el mundo.
     */
    public function test_la_dotacion_ya_no_se_escribe_en_unidades_menores(): void
    {
        $this->admin();

        $categoria = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante',
            'can_reserve' => true, 'allowance_minor' => 5_000, 'welcome_minor' => 800,
        ]);

        $pantalla = Livewire::test(
            \App\Filament\Resources\UserCategories\Pages\EditUserCategory::class,
            ['record' => $categoria->getRouteKey()],
        );

        $this->assertEquals(50, $pantalla->get('data.allowance_minor'));
        $this->assertEquals(8, $pantalla->get('data.welcome_minor'));
    }

    public function test_el_ajuste_se_cambia_desde_cobros(): void
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($u);
        $factores->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(\App\Filament\Pages\Cobros::class)
            ->set('monedaDeTrabajo', 'pesos')
            ->call('save');

        $this->assertSame('pesos', Settings::monedaDeTrabajo());
    }
}
