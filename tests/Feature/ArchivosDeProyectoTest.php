<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\DocumentsRelationManager;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los archivos de un proyecto: modelos, vectores, comprimidos (§11).
 *
 * Un proyecto no se explica solo con PDF: se fabrica con un STL, un DXF, un
 * ZIP con todo junto. El tope de tamano era de 10 MB y el panel decia «no se
 * pudo subir» sin explicar que era eso.
 */
class ArchivosDeProyectoTest extends TestCase
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
    }

    private function solicitud(array $soportes): array
    {
        return [
            'titulo'       => 'Señalética para el edificio de Bienestar',
            'resumen'      => 'Necesitamos veinte letreros en acrílico.',
            'entregables'  => '20 letreros',
            'cliente'      => 'externo',
            'nombre'       => 'Steban Gómez',
            'correo'       => 'steban@ejemplo.co',
            'telefono'     => '3001234567',
            'organizacion' => 'Bienestar Universitario',
            'soportes'     => $soportes,
        ];
    }

    /** Desde la web se adjunta con lo que se fabrica, no solo con lo que se lee. */
    public function test_la_solicitud_admite_modelos_vectores_y_comprimidos(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            UploadedFile::fake()->create('pieza.stl', 20_000, 'model/stl'),
            UploadedFile::fake()->create('todo.zip', 30_000, 'application/zip'),
            UploadedFile::fake()->create('logo.svg', 40, 'image/svg+xml'),
            UploadedFile::fake()->create('pieza.3mf', 500, 'application/zip'),
        ]))->assertSessionHasNoErrors();

        $this->assertCount(4, Project::firstOrFail()->evidence);
    }

    /** Lo ejecutable sigue fuera. */
    public function test_lo_ejecutable_sigue_fuera(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            UploadedFile::fake()->create('cosa.exe', 10),
        ]))->assertSessionHasErrors('soportes.0');
    }

    /** El panel no recorta las subidas a 12 MB, que era el tope escondido de Livewire. */
    public function test_el_tope_de_subida_del_panel_es_de_cien_megas(): void
    {
        $this->assertContains('max:102400', config('livewire.temporary_file_upload.rules'));
    }

    /** Y en la ficha del proyecto entra un ZIP grande, de cualquier tipo. */
    public function test_en_la_ficha_entra_un_zip_grande(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $jefa->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($jefa->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $p = app(ProjectService::class)->registrarIdea([
            'name' => 'Trofeos', 'source' => 'whatsapp', 'organization' => 'Deportes', 'lead_id' => $jefa->id,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => EditProject::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'kind'      => 'otro',
                'title'     => 'Modelos para imprimir',
                'file_path' => UploadedFile::fake()->create('modelos.zip', 40_000, 'application/zip'),
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('project_documents', ['project_id' => $p->id, 'title' => 'Modelos para imprimir']);

        // Y se descarga. El documento va al disco privado, y de ahi no hay
        // enlace publico: «/storage/…» daba 404 a todo el mundo. El enlace
        // pasa por el panel, que lo entrega a quien tiene acceso.
        $doc = \App\Models\ProjectDocument::where('title', 'Modelos para imprimir')->firstOrFail();

        $this->assertNotNull($doc->file_path);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path), 'se guarda en el disco privado');
        $this->assertStringContainsString(route('panel.archivo'), $doc->enlace());
        $this->assertStringNotContainsString('/storage/', $doc->enlace());

        $this->get($doc->enlace())
            ->assertOk()
            // Con el titulo y la extension del archivo, para que se sepa con que abrirlo.
            ->assertHeader('content-disposition', 'attachment; filename="Modelos para imprimir.zip"');
    }

    /** Lo que quedo en el disco publico de antes sigue saliendo por ahi. */
    public function test_un_documento_del_disco_publico_conserva_su_enlace(): void
    {
        $p = app(ProjectService::class)->registrarIdea([
            'name' => 'Trofeos', 'source' => 'whatsapp', 'organization' => 'Deportes',
        ]);

        $doc = $p->documents()->create(['kind' => 'otro', 'title' => 'Viejo', 'file_path' => 'proyectos/viejo.pdf']);

        $this->assertSame(asset('storage/proyectos/viejo.pdf'), $doc->enlace());

        $conEnlace = $p->documents()->create(['kind' => 'otro', 'title' => 'Drive', 'url' => 'https://drive.google.com/x']);

        $this->assertSame('https://drive.google.com/x', $conEnlace->enlace());
    }
}
