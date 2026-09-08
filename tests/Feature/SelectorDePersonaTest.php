<?php

namespace Tests\Feature;

use App\Filament\Componentes\SelectorDePersona;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los selectores de persona llevan el cargo al lado del nombre.
 *
 * Un desplegable con cuarenta nombres a secas obliga a saber de memoria
 * quien es practicante y quien consultor. Con el cargo en la etiqueta, y
 * buscando dentro de ella, escribir «practicante» filtra por rol.
 */
class SelectorDePersonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function persona(string $nombre, ?string $rol = null, ?string $categoria = null): User
    {
        $u = User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'user_category_id' => $categoria
                ? UserCategory::firstOrCreate(['slug' => $categoria], ['name' => ucfirst($categoria), 'can_reserve' => true, 'rate_factor' => 1])->id
                : null,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    public function test_la_etiqueta_lleva_el_rol_o_la_categoria(): void
    {
        $ana = $this->persona('Ana Pérez', User::ROL_PRACTICANTE);
        $beto = $this->persona('Beto Gómez', null, 'estudiante');
        $sinNada = $this->persona('Caro Ruiz');

        $this->assertSame('Ana Pérez · Practicante', $ana->etiquetaConCargo());
        $this->assertSame('Beto Gómez · Estudiante', $beto->etiquetaConCargo());
        $this->assertSame('Caro Ruiz', $sinNada->etiquetaConCargo());
    }

    /** El equipo es quien tiene rol; cualquiera, toda persona activa. */
    public function test_el_equipo_y_cualquiera(): void
    {
        $ana = $this->persona('Ana Pérez', User::ROL_CONSULTOR);
        $beto = $this->persona('Beto Gómez', null, 'estudiante');
        $inactiva = $this->persona('Dora Ida', User::ROL_CONSULTOR);
        $inactiva->update(['status' => 'inactivo']);

        $equipo = SelectorDePersona::equipo();
        $todos = SelectorDePersona::personas();

        $this->assertSame(['Ana Pérez · Consultor'], array_values($equipo));
        $this->assertArrayHasKey($ana->id, $todos);
        $this->assertArrayHasKey($beto->id, $todos);
        $this->assertArrayNotHasKey($inactiva->id, $todos);
    }

    /** Y en el panel se ve: el formulario de ausencias lista al equipo con su cargo. */
    public function test_el_formulario_de_ausencias_ensena_el_cargo(): void
    {
        $this->persona('Ana Pérez', User::ROL_PRACTICANTE);
        $this->persona('Beto Gómez', null, 'estudiante');

        $admin = $this->persona('Admin', User::ROL_SUPERADMIN);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $this->get('/admin/schedule-exceptions/create')
            ->assertOk()
            ->assertSee('Ana Pérez · Practicante')
            ->assertDontSee('Beto Gómez');
    }
}
