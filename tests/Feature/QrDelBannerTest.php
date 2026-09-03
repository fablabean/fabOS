<?php

namespace Tests\Feature;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Models\Banner;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El QR de la lámina del banner (§3).
 *
 * La portada se proyecta en una pantalla o en un stand, y ahí nadie hace clic:
 * se saca el teléfono. El QR lleva directo a un chat o a una dirección, y el
 * enlace lo arma el sistema, que es donde uno se equivoca a mano.
 */
class QrDelBannerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Comunicaciones', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        return $this;
    }

    private function lamina(array $qr): Banner
    {
        // Las de serie se apagan: la que se prueba tiene que ser la que se ve.
        Banner::query()->update(['is_active' => false]);

        return Banner::create(array_merge([
            'titulo' => 'Escríbenos', 'fondo_tipo' => 'color', 'efecto' => 'ninguno',
            'alineacion' => 'izquierda', 'is_active' => true, 'position' => 0,
        ], $qr));
    }

    // ------------------------------------------------------- el enlace

    public function test_whatsapp_se_arma_con_el_numero_limpio_y_el_mensaje(): void
    {
        $l = new Banner([
            'qr_tipo' => 'whatsapp', 'qr_destino' => '+57 300 123-4567',
            'qr_mensaje' => 'Hola, quiero saber más',
        ]);

        // El «+», los espacios y los guiones son como la gente lo escribe;
        // wa.me solo entiende dígitos.
        $this->assertSame('https://wa.me/573001234567?text=Hola%2C%20quiero%20saber%20m%C3%A1s', $l->qrUrl());
        $this->assertSame('Escríbenos por WhatsApp', $l->qrTexto());
    }

    public function test_teams_abre_el_chat_con_esa_cuenta(): void
    {
        $l = new Banner(['qr_tipo' => 'teams', 'qr_destino' => 'fablab@utadeo.edu.co', 'qr_mensaje' => 'Hola']);

        $this->assertSame('https://teams.microsoft.com/l/chat/0/0?users=fablab%40utadeo.edu.co&message=Hola', $l->qrUrl());
        $this->assertSame('Escríbenos por Teams', $l->qrTexto());
    }

    public function test_una_direccion_va_tal_cual_y_sin_destino_no_hay_qr(): void
    {
        $this->assertSame('https://libera.example.co/inscripcion', (new Banner([
            'qr_tipo' => 'url', 'qr_destino' => 'https://libera.example.co/inscripcion',
        ]))->qrUrl());

        $this->assertFalse((new Banner(['qr_tipo' => 'ninguno', 'qr_destino' => 'https://x.co']))->tieneQr());
        $this->assertFalse((new Banner(['qr_tipo' => 'whatsapp', 'qr_destino' => null]))->tieneQr());
        // Las laminas de fabrica no llevan tipo: tampoco llevan QR.
        $this->assertFalse((new Banner(['titulo' => 'x']))->tieneQr());
    }

    // ------------------------------------------------------- la portada

    public function test_la_lamina_con_qr_lo_enseña_en_la_portada(): void
    {
        $this->lamina([
            'qr_tipo' => 'whatsapp', 'qr_destino' => '573001234567', 'qr_texto' => 'Pregunta por el curso',
        ]);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('https://wa.me/573001234567')
            ->assertSee('Pregunta por el curso')
            ->assertSee('aria-label="Código QR"', false);
    }

    /**
     * Sin botones escritos, sin botones. Antes salían dos «de siempre», y una
     * lámina que solo anuncia —o que lleva a su QR— no tenía forma de no
     * llevarlos.
     */
    public function test_sin_botones_escritos_no_sale_ninguno(): void
    {
        $this->lamina(['qr_tipo' => 'teams', 'qr_destino' => 'fablab@utadeo.edu.co']);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Escríbenos por Teams')
            ->assertDontSee('Ver los equipos')
            ->assertDontSee('Proponer un proyecto')
            ->assertDontSee('class="acciones"', false);
    }

    public function test_sin_qr_la_portada_no_enseña_ninguno(): void
    {
        $this->lamina(['qr_tipo' => 'ninguno']);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertDontSee('aria-label="Código QR"', false);
    }

    // ------------------------------------------------------- el editor

    public function test_el_editor_exige_el_destino_cuando_hay_qr(): void
    {
        $this->entra($this->admin());

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'titulo' => 'Escríbenos', 'fondo_tipo' => 'color', 'efecto' => 'ninguno',
                'alineacion' => 'izquierda', 'qr_tipo' => 'whatsapp', 'qr_destino' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['qr_destino']);

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'titulo' => 'Escríbenos', 'fondo_tipo' => 'color', 'efecto' => 'ninguno',
                'alineacion' => 'izquierda', 'qr_tipo' => 'teams', 'qr_destino' => 'esto no es un correo',
            ])
            ->call('create')
            ->assertHasFormErrors(['qr_destino']);

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'titulo' => 'Escríbenos', 'fondo_tipo' => 'color', 'efecto' => 'ninguno',
                'alineacion' => 'izquierda', 'qr_tipo' => 'teams',
                'qr_destino' => 'fablab@utadeo.edu.co', 'qr_mensaje' => 'Hola',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Banner::where('qr_tipo', 'teams')->firstOrFail()->tieneQr());
    }
}
