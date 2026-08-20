<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\LoginCodeService;
use App\Services\Identity\CarnetClient;
use App\Services\Identity\CarnetIdentity;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El carné identifica; no autentica (§5).
 *
 * El servicio de la Universidad solo devuelve el nombre completo —el documento
 * viene vacío en los carnés observados—, así que por sí solo no puede abrir una
 * cuenta: dos personas homónimas serían indistinguibles. Lo que ahorra es
 * teclear el correo, que desde un teléfono no es poco.
 */
class CarnetIdentificaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::put(Settings::CARNET_LOGIN, true, 'auth');
    }

    private function fingirCarnet(string $nombre): void
    {
        $this->mock(CarnetClient::class, function ($mock) use ($nombre) {
            $mock->shouldReceive('lookup')->andReturn(
                new CarnetIdentity(valid: true, fullName: $nombre)
            );
        });
    }

    public function test_escanear_no_inicia_sesion_por_si_solo(): void
    {
        $this->fingirCarnet('ERICK HANSEN GOMEZ');
        User::factory()->create([
            'email'  => 'erick@ejemplo.edu.co',
            'name'   => 'ERICK HANSEN GOMEZ',
            'status' => 'activo',
        ]);

        $this->post('/ingresar/carnet', ['carnet' => 'https://ejemplo/abc'])
            ->assertRedirect(route('login.code', ['email' => 'erick@ejemplo.edu.co']));

        $this->assertGuest();
    }

    /** Pero sí cuenta como factor, y ahorra escribir el correo. */
    public function test_el_carne_cuenta_como_factor_al_terminar_de_entrar(): void
    {
        $this->fingirCarnet('ERICK HANSEN GOMEZ');
        $persona = User::factory()->create([
            'email'  => 'erick@ejemplo.edu.co',
            'name'   => 'ERICK HANSEN GOMEZ',
            'status' => 'activo',
        ]);

        $this->post('/ingresar/carnet', ['carnet' => 'https://ejemplo/abc']);

        $codigo = app(LoginCodeService::class)->emitirEnMano('erick@ejemplo.edu.co');
        $this->post('/ingresar/codigo', ['email' => 'erick@ejemplo.edu.co', 'code' => $codigo]);

        $this->assertAuthenticatedAs($persona->fresh());

        $factores = session(FactoresDeSesion::CLAVE_PRUEBAS);
        $this->assertTrue($factores['carne'] ?? false, 'El carné no contó como factor.');
        $this->assertTrue($factores['correo'] ?? false);
    }

    /**
     * El caso que obliga a que el carné no autentique: dos personas con el
     * mismo nombre. Adivinar meteria a una en la cuenta de la otra.
     */
    public function test_con_homonimos_avisa_en_vez_de_adivinar(): void
    {
        $this->fingirCarnet('JUAN PEREZ');

        foreach (['juan1@ejemplo.edu.co', 'juan2@ejemplo.edu.co'] as $correo) {
            User::factory()->create(['email' => $correo, 'name' => 'JUAN PEREZ', 'status' => 'activo']);
        }

        $respuesta = $this->post('/ingresar/carnet', ['carnet' => 'https://ejemplo/abc']);

        $respuesta->assertRedirect(route('login'));
        $this->assertStringContainsString('más de una cuenta', session('status'));
        $this->assertGuest();
    }

    public function test_un_carne_desconocido_pide_el_correo_una_vez(): void
    {
        $this->fingirCarnet('QUIEN SEA');

        $this->post('/ingresar/carnet', ['carnet' => 'https://ejemplo/abc'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_apagado_el_carne_la_ruta_no_existe(): void
    {
        Setting::put(Settings::CARNET_LOGIN, false, 'auth');

        $this->post('/ingresar/carnet', ['carnet' => 'https://ejemplo/abc'])->assertNotFound();
    }
}
