<?php

namespace Tests\Feature;

use App\Filament\Resources\ArchivosMultimedia\Pages\ListArchivosMultimedia;
use App\Models\ArchivoMultimedia;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La carpeta multimedia: se sube un archivo y se usa por su dirección desde
 * una animación aislada (origen «null»), así que tiene que salir con CORS.
 */
class MultimediaDePaginasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function stl(string $contenido = "solid prueba\nendsolid prueba\n"): ArchivoMultimedia
    {
        Storage::disk('local')->put('multimedia/abc.stl', $contenido);

        return ArchivoMultimedia::create([
            'archivo' => 'multimedia/abc.stl',
            'nombre_original' => 'Rodillo del muro.stl',
        ]);
    }

    private function entraComoAdmin(): void
    {
        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        $f = app(TwoFactorService::class);
        $f->confirmar($admin, app(Google2FA::class)->getCurrentOtp($f->generarSecreto($admin)));
        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    public function test_se_sirve_con_cors_y_su_tipo_sin_iniciar_sesion(): void
    {
        $a = $this->stl();

        $this->assertSame('Rodillo del muro', $a->nombre);
        $this->assertStringEndsWith('/multimedia/' . $a->codigo . '/rodillo-del-muro.stl', $a->url());

        $r = $this->get($a->url());
        $r->assertOk();
        $r->assertHeader('Access-Control-Allow-Origin', '*');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('model/stl', $r->headers->get('Content-Type'));
        $this->assertSame(29, $a->fresh()->tamano);
    }

    public function test_un_codigo_que_no_existe_da_404(): void
    {
        $this->get('/multimedia/zzzzzzzzzz/x.stl')->assertNotFound();
    }

    public function test_reemplazar_deja_la_misma_direccion_y_borra_el_viejo(): void
    {
        $a = $this->stl();
        $antes = $a->url();

        Storage::disk('local')->put('multimedia/nuevo.stl', 'solid v2');
        $a->update(['archivo' => 'multimedia/nuevo.stl']);

        $this->assertSame($antes, $a->fresh()->url());
        Storage::disk('local')->assertMissing('multimedia/abc.stl');

        $a->delete();
        Storage::disk('local')->assertMissing('multimedia/nuevo.stl');
    }

    public function test_no_acepta_html_ni_ejecutables(): void
    {
        $this->assertFalse(ArchivoMultimedia::extensionPermitida('pagina.html'));
        $this->assertFalse(ArchivoMultimedia::extensionPermitida('x.php'));
        $this->assertTrue(ArchivoMultimedia::extensionPermitida('pieza.STL'));
        $this->assertTrue(ArchivoMultimedia::extensionPermitida('foto.jpg'));
    }

    public function test_se_sube_desde_el_panel(): void
    {
        $this->entraComoAdmin();

        Livewire::test(ListArchivosMultimedia::class)
            ->assertOk()
            ->callAction(TestAction::make('create'), [
                'archivo' => UploadedFile::fake()->createWithContent('rodillo.stl', "solid r\nendsolid r\n"),
                'nombre' => 'Rodillo',
            ])
            ->assertHasNoActionErrors();

        $a = ArchivoMultimedia::sole();
        $this->assertSame('Rodillo', $a->nombre);
        $this->assertSame('stl', $a->extension());
        Storage::disk('local')->assertExists($a->archivo);
        $this->get($a->url())->assertOk();
    }
}
