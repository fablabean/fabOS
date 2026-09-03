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
 * El buscador del menú lateral (§19).
 *
 * El panel pasó de diez entradas a cuarenta y cuatro; a esa altura el menú se
 * busca, no se recorre. Lo que se fija aquí es que la caja esté en la barra de
 * todo el backoffice, y que no se confunda con el buscador global de Filament,
 * que busca registros y no pantallas.
 */
class BuscadorDelMenuTest extends TestCase
{
    use RefreshDatabase;

    private function entrar(string $rol): void
    {
        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate($rol, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    public function test_la_barra_lateral_trae_el_buscador(): void
    {
        $this->entrar(User::ROL_ADMINISTRADOR);

        $this->get('/admin/tablero')
            ->assertOk()
            ->assertSee('id="buscar-menu"', escape: false)
            ->assertSee('Buscar en el menú');
    }

    /** También para quien solo ve una pantalla: el buscador es de la barra. */
    public function test_lo_ve_cualquiera_del_backoffice(): void
    {
        $this->entrar(User::ROL_COMUNICACIONES);

        $this->get('/admin/contenidos')
            ->assertOk()
            ->assertSee('id="buscar-menu"', escape: false);
    }
}
