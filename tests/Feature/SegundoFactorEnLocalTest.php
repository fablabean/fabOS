<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * El interruptor para no pedir el segundo factor solo vale en el computador de
 * desarrollo. Lo que se defiende es que en cualquier otro entorno no apague nada.
 */
class SegundoFactorEnLocalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web');
        $u = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        return $u;
    }

    public function test_por_defecto_el_administrador_lo_necesita(): void
    {
        $this->assertTrue($this->admin()->segundoFactorObligatorio());
    }

    public function test_en_local_se_puede_apagar(): void
    {
        $this->app['env'] = 'local';
        config(['fabos.sesion.segundo_factor_en_local' => false]);

        $this->assertFalse($this->admin()->segundoFactorObligatorio());
    }

    public function test_fuera_de_local_el_interruptor_no_hace_nada(): void
    {
        config(['fabos.sesion.segundo_factor_en_local' => false]);

        foreach (['production', 'testing', 'staging'] as $entorno) {
            $this->app['env'] = $entorno;
            $this->assertTrue($this->admin()->segundoFactorObligatorio(), $entorno);
        }
    }
}
