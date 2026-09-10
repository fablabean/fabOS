<?php

namespace Tests\Feature;

use App\Filament\Resources\Logos\Pages\CreateLogo;
use App\Models\Logo;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los logos de la portada (§3).
 *
 * Quién respalda al laboratorio, en una franja debajo del banner, que se
 * administra desde el panel como las láminas del banner.
 */
class LogosDeLaPortadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::create(['slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true]);

        $u = User::create([
            'name' => 'Comunicaciones', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        return $u->fresh();
    }

    private function logo(string $nombre, int $posicion = 0, bool $activo = true, ?string $url = null): Logo
    {
        Storage::disk('public')->put("logos/{$posicion}.png", 'png');

        return Logo::create([
            'nombre' => $nombre, 'imagen_path' => "logos/{$posicion}.png",
            'position' => $posicion, 'is_active' => $activo, 'url' => $url,
        ]);
    }

    /** Sin logos no hay franja: una fila vacía no dice nada. */
    public function test_sin_logos_la_portada_no_pinta_la_franja(): void
    {
        $this->get('/')->assertOk()->assertDontSee('class="logos"', false);
    }

    public function test_los_logos_activos_salen_en_orden_y_con_su_enlace(): void
    {
        $this->logo('Fab Foundation', 2, url: 'https://fabfoundation.org');
        $this->logo('Universidad EAN', 1);
        $this->logo('Uno apagado', 3, activo: false);

        $respuesta = $this->get('/')->assertOk()
            ->assertSee('class="logos"', false)
            ->assertSee('alt="Universidad EAN"', false)
            ->assertSee('alt="Fab Foundation"', false)
            ->assertSee('href="https://fabfoundation.org"', false)
            ->assertDontSee('Uno apagado');

        // La Universidad va antes que la Fab Foundation: lo decide el orden, no el id.
        $html = $respuesta->getContent();
        $this->assertLessThan(strpos($html, 'alt="Fab Foundation"'), strpos($html, 'alt="Universidad EAN"'));
    }

    /** Desde el panel se sube como cualquier imagen, al disco público. */
    public function test_desde_el_panel_se_sube_un_logo(): void
    {
        $this->admin();

        Livewire::test(CreateLogo::class)
            ->fillForm([
                'nombre'      => 'Universidad EAN',
                'imagen_path' => UploadedFile::fake()->image('ean.png', 400, 160),
                'url'         => 'https://universidadean.edu.co',
                'is_active'   => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $logo = Logo::firstOrFail();

        $this->assertSame('Universidad EAN', $logo->nombre);
        $this->assertTrue(Storage::disk('public')->exists($logo->imagen_path));
        $this->assertStringContainsString('/storage/logos/', $logo->imagenUrl());

        $this->get('/')->assertSee('alt="Universidad EAN"', false);
    }

    /** La sección aparece en el panel, en Comunicaciones. */
    public function test_la_lista_del_panel_carga(): void
    {
        $this->admin();
        $this->logo('Universidad EAN');

        $this->get('/admin/logos')->assertOk()->assertSee('Universidad EAN');
    }
}
