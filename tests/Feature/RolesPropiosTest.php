<?php

namespace Tests\Feature;

use App\Filament\Pages\RolesYAccesos;
use App\Models\User;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los roles los decide el laboratorio (§5).
 *
 * Lo que se defiende: que un rol creado desde el panel entre al panel y
 * aparezca en la matriz como cualquiera, que sea del equipo si se marcó
 * así, que los cinco fijos no se borren, y que borrar un rol propio deje a
 * su gente sin rol y no rompa nada.
 */
class RolesPropiosTest extends TestCase
{
    use RefreshDatabase;

    private MatrizDeAccesos $accesos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accesos = app(MatrizDeAccesos::class);
        $this->accesos->sincronizar();
    }

    private function con(string $rol): User
    {
        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole(Role::findByName($rol, 'web'));

        return $u->fresh();
    }

    private function superadmin(): User
    {
        $u = $this->con(User::ROL_SUPERADMIN);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    public function test_los_fijos_estan_y_con_su_etiqueta(): void
    {
        $this->assertSame('Practicante', Roles::etiqueta('practicante'));
        $this->assertSame('Practicante', Role::findByName('practicante', 'web')->label);
        $this->assertTrue(Roles::esFijo('superadmin'));
    }

    public function test_un_rol_nuevo_entra_al_panel_y_ve_solo_el_tablero(): void
    {
        $this->accesos->crearRol('Voluntario');

        $this->assertTrue(Roles::existe('voluntario'));
        $this->assertSame('Voluntario', Roles::etiqueta('voluntario'));

        $voluntario = $this->con('voluntario');

        $this->assertTrue($voluntario->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($voluntario->puedeVerLaSeccion('tablero'));
        $this->assertFalse($voluntario->puedeVerLaSeccion('budget'), 'nace sin nada más');
    }

    public function test_un_rol_del_equipo_cuenta_como_personal_y_uno_que_no_no(): void
    {
        $this->accesos->crearRol('Voluntario', delEquipo: true);
        $this->accesos->crearRol('Auditor', delEquipo: false);

        $this->assertContains('voluntario', User::rolesDelEquipo());
        $this->assertNotContains('auditor', User::rolesDelEquipo());
        $this->assertNotContains('comunicaciones', User::rolesDelEquipo(), 'como siempre');

        // El auditor entra al panel de todos modos: lo que ve lo dice la matriz.
        $this->assertTrue($this->con('auditor')->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_el_rol_nuevo_aparece_en_la_matriz_y_se_le_abre_lo_que_se_marque(): void
    {
        $this->accesos->crearRol('Voluntario');
        $this->superadmin();

        $this->assertContains('voluntario', $this->accesos->rolesEditables());

        Livewire::test(RolesYAccesos::class)
            ->set('matriz.voluntario.reservation.ver', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->con('voluntario')->puedeVerLaSeccion('reservation'));
    }

    public function test_se_crea_desde_la_pantalla(): void
    {
        $this->superadmin();

        Livewire::test(RolesYAccesos::class)
            ->callAction('nuevoRol', ['etiqueta' => 'Voluntario', 'del_equipo' => true])
            ->assertHasNoActionErrors()
            ->assertSee('Voluntario');

        $this->assertTrue(Roles::existe('voluntario'));
    }

    public function test_no_se_repite_un_nombre(): void
    {
        $this->accesos->crearRol('Voluntario');

        $this->expectException(\InvalidArgumentException::class);

        $this->accesos->crearRol('voluntario');
    }

    public function test_los_fijos_no_se_borran(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->accesos->borrarRol(User::ROL_PRACTICANTE);
    }

    public function test_borrar_un_rol_propio_deja_a_su_gente_sin_rol_y_sin_panel(): void
    {
        $this->accesos->crearRol('Voluntario');
        $persona = $this->con('voluntario');
        $this->assertSame(1, $this->accesos->cuantosTienen('voluntario'));

        $this->accesos->borrarRol('voluntario');

        $this->assertFalse(Roles::existe('voluntario'));
        $this->assertSame(0, $persona->fresh()->roles()->count());
        $this->assertFalse($persona->fresh()->canAccessPanel(Filament::getPanel('admin')));
        // Y la matriz sigue pintándose sin él.
        $this->assertNotContains('voluntario', array_keys($this->accesos->matriz()));
    }

    public function test_se_borra_desde_la_pantalla_y_los_fijos_no_tienen_boton(): void
    {
        $this->accesos->crearRol('Voluntario');
        $this->superadmin();

        Livewire::test(RolesYAccesos::class)
            ->assertSee("borrarRol('voluntario')", false)
            ->assertDontSee("borrarRol('practicante')", false)
            ->call('borrarRol', 'voluntario')
            ->assertHasNoErrors();

        $this->assertFalse(Roles::existe('voluntario'));
    }

    public function test_el_selector_de_personas_ofrece_el_rol_nuevo(): void
    {
        $this->accesos->crearRol('Voluntario');

        $persona = \App\Filament\Componentes\NuevaPersona::crear([
            'name' => 'Vol', 'email' => 'vol@test.co', 'roles' => ['voluntario'],
        ]);

        $this->assertTrue($persona->hasRole('voluntario'));
        $this->assertSame('Voluntario', $persona->cargo());
    }

    public function test_sincronizar_no_toca_los_roles_propios(): void
    {
        $this->accesos->crearRol('Voluntario');

        $this->accesos->sincronizar();

        $this->assertTrue(Roles::existe('voluntario'));
        $this->assertSame('Voluntario', Role::findByName('voluntario', 'web')->label);
    }
}
