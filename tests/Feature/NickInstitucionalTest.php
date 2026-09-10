<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Identity\CarnetClient;
use App\Services\Identity\CarnetIdentity;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El nick institucional (§5).
 *
 * Lo que va antes de la arroba en un correo de la Universidad no se repite.
 * Se guarda aparte, sirve para entrar escribiendo solo eso, y reconoce a la
 * persona del carné por un dato exacto en vez de por su nombre completo.
 */
class NickInstitucionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['fabos.identity.institutional_domain' => 'universidadean.edu.co']);
    }

    public function test_el_nick_sale_del_correo_institucional_y_solo_de_ese(): void
    {
        $dentro = User::factory()->create(['email' => 'EHansen@UniversidadEan.edu.co']);
        $fuera = User::factory()->create(['email' => 'erick@gmail.com']);

        $this->assertSame('ehansen', $dentro->fresh()->nick);
        $this->assertNull($fuera->fresh()->nick, 'un correo de fuera no tiene nick');

        // Cambia el correo, cambia el nick.
        $fuera->update(['email' => 'nuevo@universidadean.edu.co']);
        $this->assertSame('nuevo', $fuera->fresh()->nick);
    }

    public function test_con_el_nick_basta_para_pedir_el_codigo(): void
    {
        User::factory()->create(['email' => 'ehansen@universidadean.edu.co', 'status' => 'activo']);

        $this->from('/ingresar')->post('/ingresar', ['email' => 'EHansen'])
            ->assertRedirect(route('login.code', ['email' => 'ehansen@universidadean.edu.co']));

        // Y el correo completo sigue valiendo, como siempre.
        $this->from('/ingresar')->post('/ingresar', ['email' => 'ehansen@universidadean.edu.co'])
            ->assertRedirect(route('login.code', ['email' => 'ehansen@universidadean.edu.co']));
    }

    public function test_sin_dominio_configurado_el_nick_no_se_inventa(): void
    {
        config(['fabos.identity.institutional_domain' => '']);

        $this->from('/ingresar')->post('/ingresar', ['email' => 'ehansen'])
            ->assertSessionHasErrors('email');

        $this->assertNull(User::nickDe('ehansen@universidadean.edu.co'));
    }

    public function test_la_pantalla_pide_correo_o_nick(): void
    {
        $this->get('/ingresar')
            ->assertOk()
            ->assertSee('Correo o nick')
            ->assertSee('placeholder="nick@universidadean.edu.co"', false)
            // El sufijo que acompaña al nick mientras se escribe, y la ayuda
            // con la arroba de verdad, no con la plantilla sin compilar.
            ->assertSee('class="sufijo"', false)
            ->assertSee('le ponemos @universidadean.edu.co')
            ->assertDontSee('{{ $dominio }}');
    }

    /** El carné con correo reconoce a la persona por el nick, aunque el nombre no cuadre. */
    public function test_el_carne_con_correo_reconoce_por_el_nick_y_no_por_el_nombre(): void
    {
        Setting::put(Settings::CARNET_LOGIN, true, 'auth');

        $persona = User::factory()->create([
            'email' => 'ehansen@universidadean.edu.co', 'name' => 'Erick', 'status' => 'activo',
        ]);
        // Un homónimo con el nombre completo del carné: por nombre, se llevaría el carné.
        User::factory()->create(['email' => 'otro@gmail.com', 'name' => 'ERICK HANSEN GOMEZ', 'status' => 'activo']);

        $this->mock(CarnetClient::class, function ($mock) {
            $mock->shouldReceive('lookup')->andReturn(new CarnetIdentity(
                valid: true, fullName: 'ERICK HANSEN GOMEZ', email: 'EHansen@universidadean.edu.co',
            ));
        });

        $this->post('/ingresar/carnet', ['carnet' => 'https://ejemplo/abc'])
            ->assertRedirect(route('login.code', ['email' => 'ehansen@universidadean.edu.co']));

        $this->assertNotNull($persona->fresh()->carnet_subject, 'el carné queda vinculado a la cuenta del nick');
    }

    /** Lo que el carné trae como «Correo:» se lee. */
    public function test_el_lector_del_carne_saca_el_correo_si_viene(): void
    {
        $html = '<html><body><h3>ERICK HANSEN GOMEZ</h3><p>Identificación: 80123456</p>'
            . '<p>Correo: ehansen@universidadean.edu.co</p><p>Fecha de expiración: 31/12/2099</p></body></html>';

        $cliente = app(CarnetClient::class);
        $parse = new \ReflectionMethod($cliente, 'parse');

        $identidad = $parse->invoke($cliente, $html);

        $this->assertTrue($identidad->valid);
        $this->assertSame('ehansen@universidadean.edu.co', $identidad->email);
    }
}
