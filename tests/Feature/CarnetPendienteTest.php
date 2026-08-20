<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\CarnetClient;
use App\Services\Identity\CarnetIdentity;
use App\Support\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un carné auténtico cuyo dueño no podemos deducir (§5).
 *
 * Pasa cuando el nombre de la cuenta viene del correo —"Mstorres"— y no se
 * parece al del carné. En vez de dejar a la persona en un callejón, el carné
 * queda pendiente y se vincula al ingresar con el correo.
 */
class CarnetPendienteTest extends TestCase
{
    use RefreshDatabase;

    private function habilitarCarnet(): void
    {
        Setting::put(Settings::CARNET_LOGIN, true, 'auth');
    }

    /** Simula el servicio de la Universidad: siempre devuelve el mismo carné. */
    private function fingirCarnet(string $nombre = 'MARIA SOFIA TORRES GOMEZ'): void
    {
        $this->mock(CarnetClient::class, function ($mock) use ($nombre) {
            $mock->shouldReceive('lookup')->andReturn(
                new CarnetIdentity(valid: true, fullName: $nombre)
            );
        });
    }

    public function test_un_carne_sin_dueno_deducible_queda_pendiente(): void
    {
        $this->habilitarCarnet();
        $this->fingirCarnet();

        $r = $this->post(route('carnet.login'), ['carnet' => 'https://x/carnet-digital/' . fake()->uuid() . '/']);

        $r->assertRedirect(route('login'));
        $r->assertSessionHas('carnet_pendiente');
        // No debe dejar entrar sin saber de quién es el carné.
        $this->assertGuest();
    }

    public function test_al_ingresar_con_el_correo_el_carne_pendiente_se_vincula(): void
    {
        $this->habilitarCarnet();
        $this->fingirCarnet();

        // Cuenta con nombre derivado del correo: no hay forma de emparejarla.
        $u = User::create([
            'name' => 'Mstorres', 'email' => 'mstorres@test.co', 'status' => 'activo',
        ]);

        $this->post(route('carnet.login'), ['carnet' => 'https://x/carnet-digital/' . fake()->uuid() . '/']);
        $this->assertNotNull(session('carnet_pendiente'));

        // Ahora entra por correo: ahí sí sabemos quién es.
        $this->actingAs($u);
        app(\App\Services\Identity\CarnetLinker::class)->vincular($u, 'https://x/carnet-digital/abc/');

        $u->refresh();
        $this->assertNotNull($u->carnet_subject, 'debe quedar vinculado');
        $this->assertSame('Maria Sofia Torres Gomez', $u->name, 'y el nombre se completa con el del carné');
    }

    public function test_no_roba_un_carne_ya_vinculado_a_otra_cuenta(): void
    {
        $this->habilitarCarnet();
        $this->fingirCarnet();

        $dueno = User::create(['name' => 'Dueña', 'email' => 'd@test.co', 'status' => 'activo']);
        $otra  = User::create(['name' => 'Otra',  'email' => 'o@test.co', 'status' => 'activo']);

        $linker = app(\App\Services\Identity\CarnetLinker::class);
        $this->assertNull($linker->vincular($dueno, 'https://x/carnet-digital/abc/'));

        $error = $linker->vincular($otra, 'https://x/carnet-digital/abc/');

        $this->assertNotNull($error);
        $this->assertNull($otra->fresh()->carnet_subject);
    }
}
