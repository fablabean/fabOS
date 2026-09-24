<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Setting;
use App\Services\Money\TasaDeCambio;
use App\Support\Dinero;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La TRM, y los precios en dólares (§12).
 *
 * El sistema llevaba una cifra escrita en la configuración —4.100— con un
 * comentario reconociendo que era un supuesto, y el formulario de compras
 * prometía en su ayuda «la TRM del día» mientras ofrecía ese número. Un
 * programa que se vende en dólares no se cotiza con la tasa del año pasado.
 */
class TasaDeCambioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function responde(float $valor): void
    {
        Http::fake(['datos.gov.co/*' => Http::response([['valor' => (string) $valor, 'vigenciadesde' => now()->toDateString()]])]);
    }

    public function test_toma_la_tasa_del_dia_y_la_guarda(): void
    {
        $this->responde(4_387.5);

        $this->assertSame(4_387.5, app(TasaDeCambio::class)->pesosPorDolar());
        $this->assertTrue(app(TasaDeCambio::class)->esReal());

        // Guardada: mañana, sin red, esta sirve.
        $this->assertSame(4_387.5, (float) Setting::get(Settings::TRM_ULTIMA)['valor']);
    }

    /**
     * Una consulta al día, no una por proceso.
     *
     * La memoria es por proceso y aquí hay varios —la web, la cola, la
     * consola—: guardada, la consulta es una al día de verdad.
     */
    public function test_si_ya_se_consiguio_hoy_no_se_vuelve_a_preguntar(): void
    {
        $this->responde(4_387.5);
        app(TasaDeCambio::class)->pesosPorDolar();

        Cache::flush();
        $this->assertSame(4_387.5, app(TasaDeCambio::class)->pesosPorDolar());

        Http::assertSentCount(1);
    }

    /** Sin red, la de ayer: es mucho mejor que el supuesto de la configuración. */
    public function test_sin_red_vale_la_ultima_que_sirvio(): void
    {
        Setting::put(Settings::TRM_ULTIMA, [
            'valor'  => 4_200.0,
            'cuando' => now()->subDays(3)->toIso8601String(),
        ], 'finanzas');

        Http::fake(['datos.gov.co/*' => Http::response('caído', 500)]);

        $this->assertSame(4_200.0, app(TasaDeCambio::class)->pesosPorDolar());
    }

    /** Y sin nada, el supuesto de siempre, que es lo que había. */
    public function test_sin_nada_vale_el_supuesto_de_la_configuracion(): void
    {
        config(['fabos.money.usd_rate' => 4_100]);
        Http::fake(['datos.gov.co/*' => Http::response('caído', 500)]);

        $this->assertSame(4_100.0, app(TasaDeCambio::class)->pesosPorDolar());
        $this->assertFalse(app(TasaDeCambio::class)->esReal());
    }

    /** Que la tasa no se pueda pedir no deja sin abrir una pantalla. */
    public function test_un_fallo_de_red_no_revienta(): void
    {
        Http::fake(fn () => throw new \RuntimeException('sin DNS'));

        $this->assertGreaterThan(0, app(TasaDeCambio::class)->pesosPorDolar());
    }

    // ---------------------------------------------------------- en el dinero

    public function test_un_importe_se_dice_en_las_tres_monedas(): void
    {
        $this->responde(4_000);

        // 11.200 FabCoins · a mil pesos el FabCoin son 11.200.000 pesos · a
        // 4.000 pesos el dólar, 2.800 dólares.
        $menor = 1_120_000;

        $this->assertSame('11.200 FBC', Dinero::enTexto($menor, 'fbc'));
        $this->assertSame('11.200.000 $', Dinero::enTexto($menor, 'pesos'));
        $this->assertSame('2.800 USD', Dinero::enTexto($menor, 'usd'));
    }

    public function test_escribir_en_dolares_guarda_lo_mismo(): void
    {
        $this->responde(4_000);

        $this->assertSame(1_120_000.0, Dinero::aMenor(2_800, 'usd'));
    }

    // -------------------------------------------------------------- el curso

    public function test_el_curso_lo_ensena_en_dolares_si_lo_pide(): void
    {
        $this->responde(4_000);

        $curso = Course::create([
            'slug' => 'fab-academy', 'name' => 'Fab Academy', 'level' => 'tera',
            'hours' => 500, 'price_minor' => 1_120_000, 'mostrar_usd' => true,
            'is_active' => true, 'is_public' => true,
        ]);

        $this->get('/formacion')->assertOk()->assertSee('2.800 USD');

        // Y si no lo pide, no sale: el precio en dólares de un taller de cuatro
        // horas no le interesa a nadie.
        $curso->update(['mostrar_usd' => false]);

        $this->get('/formacion')->assertOk()->assertDontSee('USD');
    }
    /**
     * Elegir «Dólares» en el formulario surte efecto.
     *
     * El fallo, tal cual salió: el dólar se añadió a las conversiones pero la
     * lista de monedas que el campo aceptaba se quedó con dos. Elegir
     * «Dólares» no hacía nada —el campo seguía leyendo y guardando en
     * FabCoins, sin decirlo— y 3.500 dólares se guardaron como 3.500
     * FabCoins: la cuarta parte de lo que se quiso poner.
     */
    public function test_elegir_dolares_en_el_formulario_surte_efecto(): void
    {
        $this->responde(4_000);

        $jefa = \App\Models\User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $jefa->assignRole(\Spatie\Permission\Models\Role::findOrCreate(\App\Models\User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $curso = Course::create([
            'slug' => 'fa-' . uniqid(), 'name' => 'Fab Academy', 'level' => 'tera',
            'hours' => 500, 'price_minor' => 0, 'is_active' => true, 'is_public' => true,
        ]);

        \Livewire\Livewire::test(
            \App\Filament\Resources\Courses\Pages\EditCourse::class,
            ['record' => $curso->getRouteKey()],
        )
            ->set('data.' . \App\Filament\Componentes\CampoDeDinero::CAMPO, 'usd')
            ->set('data.price_minor', '3500')
            ->call('save');

        // 3.500 dólares · a 4.000 pesos el dólar son 14.000.000 de pesos · a
        // mil pesos el FabCoin, 14.000 FabCoins.
        $this->assertSame(1_400_000, (int) $curso->fresh()->price_minor);
    }
}
