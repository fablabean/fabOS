<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cuánto dura lo que se demostró al entrar (§5).
 *
 * La sesión dura un mes, o hasta salir. El código de la app, que el panel
 * exige a quien administra, se recuerda una semana en el navegador: no se
 * pide cada vez que la sesión caduca.
 */
class SesionDuraderaTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'fabos_segundo_factor';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** El secreto de la app del último admin creado, para generar códigos. */
    private string $secreto = '';

    private function admin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $this->secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($this->secreto));

        return $u->fresh();
    }

    public function test_la_sesion_dura_un_mes(): void
    {
        // Lo que vale de fabrica, sin que un .env local lo tape: un mes en minutos.
        $this->assertMatchesRegularExpression(
            "/env\('SESSION_LIFETIME',\s*43200\)/",
            file_get_contents(config_path('session.php')),
        );
        $this->assertStringContainsString('SESSION_LIFETIME=43200', file_get_contents(base_path('.env.example')));
        $this->assertFalse((bool) config('session.expire_on_close'));
    }

    /** Sin nada demostrado en la sesión ni recordado, el panel pide la app. */
    public function test_sin_recuerdo_el_panel_pide_la_app(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertRedirect(route('dosfactores.verificar'));
    }

    /** Con la app recordada en la cookie, el panel abre aunque la sesión sea nueva. */
    public function test_con_la_app_recordada_el_panel_abre_sin_pedirla(): void
    {
        $admin = $this->admin();

        $respuesta = $this->actingAs($admin)
            ->withCookie(self::COOKIE, $admin->id . '|' . now()->addDays(3)->timestamp)
            ->get('/admin');

        // El panel puede mandar a su tablero; lo que no puede es mandar a la app.
        $this->assertLessThan(400, $respuesta->getStatusCode());
        $this->assertStringNotContainsString('segundo-factor', (string) $respuesta->headers->get('Location'));
    }

    /** La cookie de otra persona, o vencida, no vale. */
    public function test_la_cookie_ajena_o_vencida_no_vale(): void
    {
        $admin = $this->admin();
        $otro = $this->admin();

        $this->actingAs($admin)
            ->withCookie(self::COOKIE, $otro->id . '|' . now()->addDays(3)->timestamp)
            ->get('/admin')
            ->assertRedirect(route('dosfactores.verificar'));

        $this->actingAs($admin)
            ->withCookie(self::COOKIE, $admin->id . '|' . now()->subMinute()->timestamp)
            ->get('/admin')
            ->assertRedirect(route('dosfactores.verificar'));
    }

    /** Verificar la app deja la cookie puesta, por una semana. */
    public function test_verificar_la_app_la_recuerda_una_semana(): void
    {
        $admin = $this->admin();
        $codigo = app(Google2FA::class)->getCurrentOtp($this->secreto);

        $respuesta = $this->actingAs($admin)
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true]])
            ->post(route('dosfactores.comprobar'), ['codigo' => $codigo]);

        $respuesta->assertRedirect('/admin');
        $respuesta->assertCookie(self::COOKIE);

        $cookie = collect($respuesta->headers->getCookies())->first(fn ($c) => $c->getName() === self::COOKIE);
        $this->assertGreaterThan(now()->addDays(6)->timestamp, $cookie->getExpiresTime());
        $this->assertLessThanOrEqual(now()->addDays(8)->timestamp, $cookie->getExpiresTime());
    }

    /** Salir es salir: la cookie se borra. */
    public function test_al_salir_se_olvida_la_app(): void
    {
        $admin = $this->admin();

        $respuesta = $this->actingAs($admin)
            ->withCookie(self::COOKIE, $admin->id . '|' . now()->addDays(3)->timestamp)
            ->post(route('logout'));

        $respuesta->assertRedirect(route('publico.home'));
        $respuesta->assertCookieExpired(self::COOKIE);
    }
}
