<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\LoginCodeService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\BeneficioSemanal;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El saldo con el que nace una cuenta (§12).
 *
 * Lo que se defiende: que quien entra un martes no espere al lunes, que cada
 * subcategoría arranque con lo suyo, que sea una vez por categoría, y que
 * apagado el beneficio nadie reciba FabCoins solos.
 */
class BienvenidaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['fabos.identity.institutional_domain' => 'universidadean.edu.co']);
        Setting::put(Settings::BENEFICIO_ACTIVO, true, 'finanzas');
        $this->seed(\Database\Seeders\CatalogSeeder::class);
    }

    private function saldo(User $u): int
    {
        return app(LedgerService::class)->saldoDe($u);
    }

    private function categoria(string $slug): UserCategory
    {
        return UserCategory::where('slug', $slug)->firstOrFail();
    }

    public function test_las_subcategorias_de_estudiante_estan_sembradas_con_su_bienvenida(): void
    {
        $this->assertSame(800, $this->categoria('estudiante')->welcome_minor);
        $this->assertSame(1000, $this->categoria('estudiante-bootcamp')->welcome_minor);
        $this->assertSame(2000, $this->categoria('estudiante-curso')->welcome_minor);
        $this->assertSame(3000, $this->categoria('estudiante-diplomado')->welcome_minor);
        $this->assertTrue($this->categoria('estudiante-diplomado')->weekly_benefit);
        $this->assertFalse($this->categoria('externo')->weekly_benefit);
        $this->assertSame(0, $this->categoria('externo')->welcome_minor);
    }

    public function test_quien_entra_un_martes_con_correo_de_la_universidad_no_espera_al_lunes(): void
    {
        $this->travelTo(now()->next('Tuesday')->setTime(10, 0));

        // El código al correo crea la cuenta como estudiante.
        $persona = (new \ReflectionClass(LoginCodeService::class))
            ->getMethod('resolveUser')
            ->invoke(app(LoginCodeService::class), 'nuevo@universidadean.edu.co');

        $this->assertSame('estudiante', $persona->category->slug);
        $this->assertSame(800, $this->saldo($persona), 'los 8 del semanal, de una');
    }

    public function test_un_externo_no_nace_con_nada(): void
    {
        $u = User::create(['name' => 'Ext', 'email' => 'ext@gmail.com', 'status' => 'activo', 'user_category_id' => $this->categoria('externo')->id]);

        $this->assertSame(0, $this->saldo($u));
    }

    public function test_cada_programa_arranca_con_lo_suyo(): void
    {
        $boot = User::create(['name' => 'B', 'email' => 'b@gmail.com', 'status' => 'activo', 'user_category_id' => $this->categoria('estudiante-bootcamp')->id]);
        $dipl = User::create(['name' => 'D', 'email' => 'd@gmail.com', 'status' => 'activo', 'user_category_id' => $this->categoria('estudiante-diplomado')->id]);

        // Aunque el correo sea Gmail: la categoría manda.
        $this->assertSame(1000, $this->saldo($boot));
        $this->assertSame(3000, $this->saldo($dipl));
    }

    public function test_cambiar_de_categoria_completa_hasta_la_nueva_y_no_se_repite(): void
    {
        $u = User::create(['name' => 'A', 'email' => 'a@universidadean.edu.co', 'status' => 'activo', 'user_category_id' => $this->categoria('estudiante')->id]);
        $this->assertSame(800, $this->saldo($u));

        $u->update(['user_category_id' => $this->categoria('estudiante-curso')->id]);
        $this->assertSame(2000, $this->saldo($u), 'completa hasta 20, no suma 20');

        // Vuelve a general y otra vez a curso: ya la tuvo.
        $u->update(['user_category_id' => $this->categoria('estudiante')->id]);
        $u->update(['user_category_id' => $this->categoria('estudiante-curso')->id]);
        $this->assertSame(2000, $this->saldo($u));
    }

    public function test_apagado_el_beneficio_nadie_nace_con_saldo(): void
    {
        Setting::put(Settings::BENEFICIO_ACTIVO, false, 'finanzas');

        $u = User::create(['name' => 'A', 'email' => 'a@universidadean.edu.co', 'status' => 'activo', 'user_category_id' => $this->categoria('estudiante-diplomado')->id]);

        $this->assertSame(0, $this->saldo($u));
    }

    public function test_el_semanal_llega_por_categoria_aunque_el_correo_sea_de_fuera(): void
    {
        $boot = User::create(['name' => 'B', 'email' => 'b@gmail.com', 'status' => 'activo', 'user_category_id' => $this->categoria('estudiante-bootcamp')->id]);
        $ext = User::create(['name' => 'E', 'email' => 'e@gmail.com', 'status' => 'activo', 'user_category_id' => $this->categoria('externo')->id]);
        $prof = User::create(['name' => 'P', 'email' => 'p@universidadean.edu.co', 'status' => 'activo', 'user_category_id' => $this->categoria('profesor')->id]);

        $this->assertTrue(BeneficioSemanal::tieneDerecho($boot), 'por la categoría');
        $this->assertFalse(BeneficioSemanal::tieneDerecho($ext));
        $this->assertTrue(BeneficioSemanal::tieneDerecho($prof), 'por el correo, como siempre');
    }

    public function test_la_bienvenida_sale_de_la_emision_y_queda_firmada(): void
    {
        $u = User::create(['name' => 'B', 'email' => 'b@gmail.com', 'status' => 'activo', 'user_category_id' => $this->categoria('estudiante-bootcamp')->id]);

        $this->assertDatabaseHas('ledger_transactions', [
            'kind' => 'dotacion',
            'idempotency_key' => 'bienvenida:' . $u->id . ':estudiante-bootcamp',
        ]);
    }
}
