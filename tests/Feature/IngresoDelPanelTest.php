<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El panel no tiene pantalla de ingreso propia: usa la del sitio (§5).
 *
 * fabOS no tiene contraseñas. El formulario de Filament pedía una que nadie
 * tiene, y ahí caía quien cerraba sesión desde el panel.
 */
class IngresoDelPanelTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(User::ROL_ADMINISTRADOR);

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

    public function test_la_pantalla_de_ingreso_del_panel_es_la_del_sitio(): void
    {
        $this->get('/admin/login')->assertRedirect(route('login'));
    }

    public function test_sin_sesion_el_panel_lleva_al_ingreso_del_sitio(): void
    {
        // Dos saltos: Filament manda a su ruta de ingreso, y esa manda a la del sitio.
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->followingRedirects()->get('/admin')->assertOk()->assertSee('Ingresar');
    }

    public function test_cerrar_sesion_desde_el_panel_termina_en_el_ingreso_del_sitio(): void
    {
        $u = $this->administrador();

        $this->entra($u)
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->get('/admin/login')->assertRedirect(route('login'));
    }

    public function test_quien_ya_entro_no_ve_ninguna_pantalla_de_ingreso(): void
    {
        $u = $this->administrador();

        $this->entra($u)->get('/admin/login')->assertRedirect('/admin');
    }
}
