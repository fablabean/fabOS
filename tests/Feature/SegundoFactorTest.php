<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Segundo factor para el backoffice (§16).
 *
 * El ingreso por código al correo hereda la seguridad de la bandeja: alcanza
 * para reservar una impresora, no para cambiar permisos o emitir FabCoins.
 */
class SegundoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    /** Código válido en este momento para ese secreto. */
    private function codigoActual(string $secreto): string
    {
        return app(Google2FA::class)->getCurrentOtp($secreto);
    }

    // ------------------------------------------------------------- a quién aplica

    public function test_solo_lo_exige_a_quien_administra(): void
    {
        $this->assertTrue($this->persona(User::ROL_SUPERADMIN)->segundoFactorObligatorio());
        $this->assertTrue($this->persona(User::ROL_ADMINISTRADOR)->segundoFactorObligatorio());

        // Un consultor solo mira, y un estudiante ni entra al backoffice.
        $this->assertFalse($this->persona(User::ROL_CONSULTOR)->segundoFactorObligatorio());
        $this->assertFalse($this->persona()->segundoFactorObligatorio());
    }

    // --------------------------------------------------------------------- alta

    public function test_el_secreto_se_guarda_cifrado(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);
        $secreto = $this->servicio()->generarSecreto($u);

        // Un volcado de la base no debe alcanzar para generar códigos.
        $this->assertStringNotContainsString($secreto, $u->fresh()->two_factor_secret);
    }

    public function test_no_queda_en_vigor_hasta_confirmarlo(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);
        $secreto = $this->servicio()->generarSecreto($u);

        $this->assertFalse($u->fresh()->tieneSegundoFactor(), 'generar no es activar');

        $this->assertTrue($this->servicio()->confirmar($u, $this->codigoActual($secreto)));
        $this->assertTrue($u->fresh()->tieneSegundoFactor());
    }

    public function test_un_codigo_equivocado_no_lo_activa(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);
        $this->servicio()->generarSecreto($u);

        $this->assertFalse($this->servicio()->confirmar($u, '000000'));
        $this->assertFalse($u->fresh()->tieneSegundoFactor());
    }

    // ------------------------------------------------------------- recuperación

    public function test_un_codigo_de_recuperacion_sirve_una_sola_vez(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);
        $this->servicio()->generarSecreto($u);

        $codigos = $this->servicio()->codigosDe($u);
        $this->assertCount(8, $codigos);

        $uno = $codigos[0];
        $this->assertTrue($this->servicio()->usarCodigoDeRecuperacion($u, $uno));
        $this->assertFalse($this->servicio()->usarCodigoDeRecuperacion($u->fresh(), $uno), 'no se reutiliza');
        $this->assertCount(7, $this->servicio()->codigosDe($u->fresh()));
    }

    // ------------------------------------------------------------- el guardián

    public function test_un_administrador_sin_segundo_factor_va_a_configurarlo(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);

        $this->actingAs($u)
            ->get('/admin')
            ->assertRedirect(route('dosfactores.configurar'));
    }

    public function test_con_segundo_factor_pero_sin_verificar_pide_el_codigo(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);
        $secreto = $this->servicio()->generarSecreto($u);
        $this->servicio()->confirmar($u, $this->codigoActual($secreto));

        $this->actingAs($u->fresh())
            ->get('/admin')
            ->assertRedirect(route('dosfactores.verificar'));
    }

    public function test_tras_verificar_entra_al_backoffice(): void
    {
        $u = $this->persona(User::ROL_SUPERADMIN);
        $secreto = $this->servicio()->generarSecreto($u);
        $this->servicio()->confirmar($u, $this->codigoActual($secreto));

        $this->actingAs($u->fresh())
            ->post(route('dosfactores.comprobar'), ['codigo' => $this->codigoActual($secreto)])
            ->assertRedirect('/admin');

        $this->assertTrue(session('segundo_factor_verificado'));
    }

    public function test_un_consultor_entra_sin_segundo_factor(): void
    {
        // /admin lleva a la primera pantalla del panel, que es el tablero. Lo
        // que importa aquí es que NO lo desvían al segundo factor.
        $this->actingAs($this->persona(User::ROL_CONSULTOR))
            ->get('/admin')
            ->assertRedirect(route('filament.admin.pages.tablero'));

        $this->actingAs($this->persona(User::ROL_CONSULTOR))
            ->get('/admin/tablero')
            ->assertOk();
    }

    public function test_el_resto_del_sistema_sigue_accesible(): void
    {
        // No se le bloquea la vida entera: solo la administración.
        $this->actingAs($this->persona(User::ROL_SUPERADMIN))
            ->get(route('home'))
            ->assertOk();
    }
}
