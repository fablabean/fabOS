<?php

namespace Tests\Feature;

use App\Models\LoginCode;
use App\Models\User;
use App\Services\Auth\LoginCodeService;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Entrar con la app de autenticación (§5).
 *
 * El punto de todo esto: que el correo deje de ser la única puerta. Quien tiene
 * la app configurada entra aunque el proveedor de correo esté caído, aunque su
 * universidad filtre el mensaje, o aunque no haya señal.
 */
class IngresoConAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function conApp(array $atributos = []): array
    {
        $persona = User::factory()->create($atributos + ['status' => 'activo']);
        $secreto = app(TwoFactorService::class)->generarSecreto($persona);
        app(TwoFactorService::class)->confirmar($persona, app(Google2FA::class)->getCurrentOtp($secreto));

        return [$persona->fresh(), $secreto];
    }

    public function test_quien_tiene_la_app_entra_con_su_codigo(): void
    {
        [$persona, $secreto] = $this->conApp(['email' => 'quien@ejemplo.edu.co']);

        $this->post('/ingresar', ['email' => 'quien@ejemplo.edu.co'])
            ->assertRedirect(route('login.code', ['email' => 'quien@ejemplo.edu.co']));

        $this->post('/ingresar/codigo', [
            'email' => 'quien@ejemplo.edu.co',
            'code'  => app(Google2FA::class)->getCurrentOtp($secreto),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($persona);
        $this->assertTrue(session(FactoresDeSesion::CLAVE_PRUEBAS)['app'] ?? false);
    }

    /** Lo que de verdad importa: no se manda ningún correo. */
    public function test_a_quien_tiene_la_app_no_se_le_manda_correo(): void
    {
        $this->conApp(['email' => 'quien@ejemplo.edu.co']);

        $this->post('/ingresar', ['email' => 'quien@ejemplo.edu.co']);

        $this->assertSame(0, LoginCode::where('email', 'quien@ejemplo.edu.co')->count());
        Mail::assertNothingSent();
    }

    public function test_sin_app_se_sigue_enviando_el_codigo_al_correo(): void
    {
        User::factory()->create(['email' => 'otra@ejemplo.edu.co', 'status' => 'activo']);

        $this->post('/ingresar', ['email' => 'otra@ejemplo.edu.co']);

        $this->assertSame(1, LoginCode::where('email', 'otra@ejemplo.edu.co')->count());
    }

    public function test_un_codigo_de_recuperacion_tambien_sirve_para_entrar(): void
    {
        [$persona] = $this->conApp(['email' => 'quien@ejemplo.edu.co']);
        $recuperacion = app(TwoFactorService::class)->codigosDe($persona)[0];

        $this->post('/ingresar/codigo', [
            'email' => 'quien@ejemplo.edu.co',
            'code'  => $recuperacion,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($persona);
    }

    public function test_un_codigo_de_recuperacion_no_sirve_dos_veces(): void
    {
        [$persona] = $this->conApp(['email' => 'quien@ejemplo.edu.co']);
        $recuperacion = app(TwoFactorService::class)->codigosDe($persona)[0];

        $this->post('/ingresar/codigo', ['email' => 'quien@ejemplo.edu.co', 'code' => $recuperacion]);
        $this->post('/salir');

        $this->post('/ingresar/codigo', ['email' => 'quien@ejemplo.edu.co', 'code' => $recuperacion])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /**
     * La pantalla de ingreso no puede delatar quién tiene cuenta ni quién tiene
     * app: en los dos casos lleva al mismo sitio.
     */
    public function test_la_pantalla_es_la_misma_haya_app_o_no(): void
    {
        $this->conApp(['email' => 'conapp@ejemplo.edu.co']);
        User::factory()->create(['email' => 'sinapp@ejemplo.edu.co', 'status' => 'activo']);

        $destinos = [];

        foreach (['conapp@ejemplo.edu.co', 'sinapp@ejemplo.edu.co', 'noexiste@ejemplo.edu.co'] as $correo) {
            $destinos[] = $this->post('/ingresar', ['email' => $correo])
                ->headers->get('Location');
            $this->flushSession();
        }

        $this->assertSame(
            [route('login.code', ['email' => 'conapp@ejemplo.edu.co'])],
            array_unique([$destinos[0]]),
        );

        // Los tres llevan a la pantalla de codigo, cambiando solo el correo.
        foreach ($destinos as $d) {
            $this->assertStringContainsString('/ingresar/codigo', (string) $d);
        }
    }

    // --------------------------------------------- dos factores en el backoffice

    public function test_el_backoffice_no_se_abre_con_un_solo_factor(): void
    {
        [$persona, $secreto] = $this->conApp(['email' => 'jefa@ejemplo.edu.co']);
        $persona->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        // Entra solo con la app: un factor.
        $this->post('/ingresar/codigo', [
            'email' => 'jefa@ejemplo.edu.co',
            'code'  => app(Google2FA::class)->getCurrentOtp($secreto),
        ]);

        $this->assertAuthenticatedAs($persona->fresh());

        // Y el panel le pide el otro, en vez de repetirle el mismo.
        $this->get('/admin/tablero')->assertRedirect(route('dosfactores.otroFactor'));
    }

    public function test_con_dos_factores_distintos_el_backoffice_se_abre(): void
    {
        [$persona] = $this->conApp(['email' => 'jefa@ejemplo.edu.co']);
        $persona->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->actingAs($persona->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/tablero')
            ->assertOk();
    }

    /**
     * Los factores no se heredan: si no se limpiaran, la siguiente persona que
     * entrara en ese mismo navegador arrancaria con los de la anterior.
     */
    public function test_los_factores_de_la_sesion_anterior_no_cuentan(): void
    {
        User::factory()->create(['email' => 'otra@ejemplo.edu.co', 'status' => 'activo']);

        $this->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['app' => true, 'carne' => true]]);

        $codigo = app(LoginCodeService::class)->emitirEnMano('otra@ejemplo.edu.co');
        $this->post('/ingresar/codigo', ['email' => 'otra@ejemplo.edu.co', 'code' => $codigo]);

        $factores = session(FactoresDeSesion::CLAVE_PRUEBAS);

        $this->assertSame(['correo'], array_keys($factores));
    }

    // ------------------------------------------------ activarla desde Mi cuenta

    public function test_cualquiera_puede_activar_la_app_desde_su_cuenta(): void
    {
        $persona = User::factory()->create(['status' => 'activo']);

        $this->actingAs($persona)->get('/cuenta/app')->assertOk()->assertSee('Escanea este código');

        $secreto = session('2fa_secreto_provisional');

        $this->actingAs($persona)
            ->post('/cuenta/app/activar', ['codigo' => app(Google2FA::class)->getCurrentOtp($secreto)])
            ->assertRedirect(route('cuenta.app'));

        $this->assertTrue($persona->fresh()->tieneSegundoFactor());
    }

    public function test_una_persona_normal_puede_desactivarla(): void
    {
        [$persona] = $this->conApp();

        $this->actingAs($persona)->post('/cuenta/app/desactivar')->assertRedirect();

        $this->assertFalse($persona->fresh()->tieneSegundoFactor());
    }

    /** Para quien administra la app es la reja del backoffice, no una comodidad. */
    public function test_quien_administra_no_puede_desactivarla(): void
    {
        [$persona] = $this->conApp();
        $persona->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->actingAs($persona->fresh())
            ->post('/cuenta/app/desactivar')
            ->assertSessionHasErrors('codigo');

        $this->assertTrue($persona->fresh()->tieneSegundoFactor());
    }
}
