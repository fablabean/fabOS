<?php

namespace Tests\Feature;

use App\Filament\Componentes\ArchivoPrivado;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las fotos de un proyecto se ven en el panel (§11).
 *
 * Van al disco privado a proposito, y el campo de subida construia la vista
 * previa con la URL publica del disco, que para el privado no existe: la
 * foto salia como una barra gris con un nombre aleatorio y «6 KB».
 */
class VistaPreviaDeArchivosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1],
        );

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function entra(User $u): void
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    private function delEquipo(string $rol = User::ROL_CONSULTOR): User
    {
        $u = User::create(['name' => 'Equipo', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole($rol);

        return $u->fresh();
    }

    /** Una foto guardada en el disco privado, como la deja el formulario público. */
    private function foto(): string
    {
        $ruta = 'proyectos/soportes/' . uniqid() . '.webp';
        Storage::disk('local')->put($ruta, UploadedFile::fake()->image('pieza.webp', 300, 200)->getContent());

        return $ruta;
    }

    public function test_el_equipo_ve_la_foto_por_la_ruta_del_panel(): void
    {
        $ruta = $this->foto();
        $this->entra($this->delEquipo());

        $this->get(route('panel.archivo', ['ruta' => $ruta]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_quien_no_es_del_equipo_no_la_ve(): void
    {
        $ruta = $this->foto();
        $estudiante = User::create(['name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        $this->actingAs($estudiante)
            ->get(route('panel.archivo', ['ruta' => $ruta]))
            ->assertForbidden();
    }

    public function test_sin_sesion_manda_al_ingreso(): void
    {
        $this->get(route('panel.archivo', ['ruta' => $this->foto()]))->assertRedirect(route('login'));
    }

    /** Solo lo de proyectos, y sin subir de directorio: el resto del disco no se asoma. */
    public function test_fuera_de_proyectos_no_se_sirve_nada(): void
    {
        Storage::disk('local')->put('privado/secreto.txt', 'no');
        $this->entra($this->delEquipo());

        $this->get(route('panel.archivo', ['ruta' => 'privado/secreto.txt']))->assertNotFound();
        $this->get(route('panel.archivo', ['ruta' => 'proyectos/../privado/secreto.txt']))->assertNotFound();

        $this->assertFalse(ArchivoPrivado::permitida('proyectos/../x'));
        $this->assertTrue(ArchivoPrivado::permitida('proyectos/soportes/a.webp'));
    }

    /** Y el campo de subida pide la vista previa por esa ruta, con el tamaño real. */
    public function test_el_campo_de_subida_previsualiza_por_la_ruta_del_panel(): void
    {
        $ruta = $this->foto();

        $vista = ArchivoPrivado::vistaPrevia($ruta, ['proyectos/otra.webp' => 'x', $ruta => 'pieza-rota.webp']);

        $this->assertStringContainsString('/panel/archivo/proyectos/soportes/', $vista['url']);
        $this->assertSame(Storage::disk('local')->size($ruta), $vista['size']);
        $this->assertStringStartsWith('image/', $vista['type']);
        $this->assertSame('pieza-rota.webp', $vista['name']);

        // Lo que no existe o no toca, no se enseña.
        $this->assertNull(ArchivoPrivado::vistaPrevia('proyectos/soportes/no-existe.webp'));
        $this->assertNull(ArchivoPrivado::vistaPrevia('privado/secreto.txt'));

        // Y es lo que el campo de subida usa.
        $campo = ArchivoPrivado::previsualizar(FileUpload::make('file_path'));
        $this->assertNotNull($campo);
    }

    /** La ficha del proyecto carga con una imagen de referencia en el disco privado. */
    public function test_la_ficha_del_proyecto_ensena_la_imagen_de_referencia(): void
    {
        $jefa = $this->delEquipo(User::ROL_ADMINISTRADOR);
        $this->entra($jefa);

        $ruta = $this->foto();
        $p = app(ProjectService::class)->registrarIdea([
            'name' => 'Tapa decorativa', 'source' => 'whatsapp', 'organization' => 'Alguien', 'lead_id' => $jefa->id,
        ]);
        $p->update(['reference_image_path' => $ruta]);

        Livewire::test(EditProject::class, ['record' => $p->id])
            ->assertOk();

        // La vista previa de esa ruta sale por el panel, no por /storage.
        $this->assertStringContainsString('/panel/archivo/', ArchivoPrivado::vistaPrevia($ruta)['url']);
    }
}
