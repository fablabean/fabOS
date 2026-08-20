<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\CarnetClient;
use App\Services\Identity\CarnetIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mover y quitar el vínculo de un carné (§5).
 *
 * Un carné vinculado decide quién entra a una cuenta. Trasladarlo en silencio
 * seria dar acceso a otra persona sin que nadie lo decidiera, asi que hace
 * falta pedirlo explicitamente.
 */
class CarnetVinculoTest extends TestCase
{
    use RefreshDatabase;

    private function fingirCarnet(string $nombre = 'ERICK HANSEN GOMEZ'): void
    {
        $this->mock(CarnetClient::class, function ($mock) use ($nombre) {
            $mock->shouldReceive('lookup')->andReturn(
                new CarnetIdentity(valid: true, fullName: $nombre)
            );
        });
    }

    public function test_vincula_un_carne_a_una_cuenta(): void
    {
        $this->fingirCarnet();
        $persona = User::factory()->create(['email' => 'quien@ejemplo.edu.co']);

        $this->artisan('fabos:carnet:link quien@ejemplo.edu.co https://ejemplo/abc')
            ->assertSuccessful();

        $this->assertNotNull($persona->fresh()->carnet_subject);
    }

    public function test_no_lo_traslada_sin_que_se_lo_pidan(): void
    {
        $this->fingirCarnet();
        $primera = User::factory()->create(['email' => 'primera@ejemplo.edu.co']);
        $segunda = User::factory()->create(['email' => 'segunda@ejemplo.edu.co']);

        $this->artisan('fabos:carnet:link primera@ejemplo.edu.co https://ejemplo/abc')
            ->assertSuccessful();

        $this->artisan('fabos:carnet:link segunda@ejemplo.edu.co https://ejemplo/abc')
            ->assertFailed();

        $this->assertNotNull($primera->fresh()->carnet_subject);
        $this->assertNull($segunda->fresh()->carnet_subject);
    }

    public function test_con_mover_lo_traslada_y_lo_quita_de_la_anterior(): void
    {
        $this->fingirCarnet();
        $primera = User::factory()->create(['email' => 'primera@ejemplo.edu.co']);
        $segunda = User::factory()->create(['email' => 'segunda@ejemplo.edu.co']);

        $this->artisan('fabos:carnet:link primera@ejemplo.edu.co https://ejemplo/abc')->assertSuccessful();
        $this->artisan('fabos:carnet:link segunda@ejemplo.edu.co https://ejemplo/abc --mover')->assertSuccessful();

        $this->assertNull($primera->fresh()->carnet_subject, 'El carné siguió en la cuenta anterior.');
        $this->assertNotNull($segunda->fresh()->carnet_subject);
    }

    /**
     * Al quitar el carné se retira tambien la verificacion de identidad que
     * aportaba: mantenerla seria afirmar algo que ya nada respalda.
     */
    public function test_desvincular_retira_tambien_la_identidad_verificada(): void
    {
        $this->fingirCarnet();
        $persona = User::factory()->create(['email' => 'quien@ejemplo.edu.co']);

        $this->artisan('fabos:carnet:link quien@ejemplo.edu.co https://ejemplo/abc')->assertSuccessful();
        $this->assertSame('carnet_ean', $persona->fresh()->identity_verified_via);

        $this->artisan('fabos:carnet:link quien@ejemplo.edu.co --desvincular')->assertSuccessful();

        $persona->refresh();
        $this->assertNull($persona->carnet_subject);
        $this->assertNull($persona->identity_verified_via);
        $this->assertNull($persona->identity_verified_at);
    }

    public function test_desvincular_una_cuenta_sin_carne_no_es_un_error(): void
    {
        User::factory()->create(['email' => 'quien@ejemplo.edu.co']);

        $this->artisan('fabos:carnet:link quien@ejemplo.edu.co --desvincular')
            ->assertSuccessful();
    }
}
