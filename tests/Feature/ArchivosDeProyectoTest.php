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

    /**
     * Los archivos de una producción: un .dxf y un .ai se guardan con su
     * extensión y el panel los vuelve a mostrar. Se subían a una carpeta que
     * el panel no tenía permitida: decía «subida completa» y al reabrir no
     * aparecían, y el .dxf quedaba guardado como «.pdf».
     */
    public function test_los_archivos_de_una_produccion_se_ven_y_conservan_su_extension(): void
    {
        $guardar = fn (string $nombre) => \App\Filament\Componentes\CampoDeEvidencia::guardar(
            UploadedFile::fake()->create($nombre, 1300, 'application/pdf'), 'archivo', 'proyectos/producciones',
        );

        $dxf = $guardar('escenarios-vector-completo.dxf');
        $ai = $guardar('arte final.AI');

        $this->assertStringStartsWith('proyectos/producciones/', $dxf);
        $this->assertStringEndsWith('.dxf', $dxf, 'con su extensión, no la que se adivina por el contenido');
        $this->assertStringEndsWith('.ai', $ai);

        foreach ([$dxf, $ai] as $ruta) {
            $this->assertNotNull(\App\Filament\Componentes\ArchivoPrivado::vistaPrevia($ruta), 'el panel lo vuelve a mostrar');
        }

        // Y los que ya se habían subido a la carpeta vieja también se ven.
        Storage::disk('local')->put('producciones/viejo.pdf', 'x');
        $this->assertNotNull(\App\Filament\Componentes\ArchivoPrivado::vistaPrevia('producciones/viejo.pdf'));
    }

    /**
     * El laboratorio responde con una foto: queda pegada a la respuesta, es
     * soporte del proyecto, y quien pidió la ve debajo de lo que se dijo.
     */
    public function test_el_laboratorio_responde_con_imagenes(): void
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
            'contact_email' => 'cliente@ejemplo.co',
        ]);

        Livewire::test(\App\Filament\Resources\Projects\RelationManagers\CommentsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => EditProject::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'body'     => 'Así va la pieza.',
                'adjuntos' => [UploadedFile::fake()->image('avance.jpg', 400, 300)],
            ])
            ->assertHasNoActionErrors();

        $respuesta = $p->comments()->where('side', 'laboratorio')->firstOrFail();
        $foto = $respuesta->adjuntos->first();

        $this->assertNotNull($foto, 'la foto queda pegada a la respuesta');
        $this->assertSame('foto', $foto->kind);
        $this->assertSame('avance.jpg', $foto->original_name);
        $this->assertSame($p->id, $foto->evidenciable_id, 'y es soporte del proyecto');
        $this->assertSame($jefa->id, $foto->uploaded_by);
        $this->assertTrue(Storage::disk('local')->exists($foto->file_path));

        // Quien pidió la ve en línea, debajo de la respuesta.
        $enlace = \Illuminate\Support\Facades\URL::temporarySignedRoute('proyectos.propuesta', now()->addDay(), ['project' => $p->id]);

        $this->get($enlace)
            ->assertOk()
            ->assertSee('Así va la pieza.')
            ->assertSee('/proyectos/evidencia/' . $foto->id, false)
            ->assertSee('alt="avance.jpg"', false)
            // Y no repetida en la lista de lo que adjuntó al pedirlo: no la adjuntó él.
            ->assertDontSee('<h2 style="margin-top:0">Lo que adjuntaste</h2>', false);
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

    private function jefaDentro(): User
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

        return $jefa;
    }

    /**
     * Un adjunto que se quedó sin archivo no tumba la conversación.
     *
     * Pasó con PRY-2026-0159: cinco adjuntos del cliente quedaron sin ruta, y
     * la pestaña entera salía en blanco con «Error al cargar la página».
     */
    public function test_la_conversacion_abre_aunque_un_adjunto_no_tenga_archivo(): void
    {
        $jefa = $this->jefaDentro();
        $p = app(ProjectService::class)->registrarIdea(['name' => 'Soporte', 'source' => 'whatsapp', 'lead_id' => $jefa->id]);
        $c = app(ProjectService::class)->comentar($p, 'Aquí van los modelos.', $jefa);
        $p->evidence()->create(['kind' => 'archivo', 'file_path' => null, 'project_comment_id' => $c->id]);

        Livewire::test(\App\Filament\Resources\Projects\RelationManagers\CommentsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => EditProject::class,
        ])
            ->assertOk()
            ->assertSee('Aquí van los modelos.')
            ->assertSee('sin archivo');
    }

    /**
     * Guardar la ficha con el campo de archivo vacío no deja la evidencia sin
     * su archivo: el campo vive en el navegador y a veces llega vacío sin que
     * nadie haya quitado nada.
     */
    public function test_guardar_la_ficha_no_le_quita_el_archivo_a_una_evidencia(): void
    {
        Storage::fake('local');
        $jefa = $this->jefaDentro();
        $p = app(ProjectService::class)->registrarIdea(['name' => 'Soporte', 'source' => 'whatsapp', 'lead_id' => $jefa->id]);

        Storage::disk('local')->put('proyectos/soportes/modelo.bin', 'contenido');
        $e = $p->evidence()->create([
            'kind' => 'archivo', 'file_path' => 'proyectos/soportes/modelo.bin', 'original_name' => 'soporte.3mf',
        ]);

        Livewire::test(EditProject::class, ['record' => $p->getRouteKey()])
            ->set('data.evidence.record-' . $e->id . '.file_path', [])
            ->call('save')
            ->assertHasNoFormErrors();

        $e->refresh();
        $this->assertSame('proyectos/soportes/modelo.bin', $e->file_path);
        $this->assertSame('soporte.3mf', $e->original_name);
    }

    // ------------------------------------ editar lo dicho y saber si se vio

    /** Una errata en un mensaje ya enviado se corrige, y queda como editado. */
    public function test_el_laboratorio_edita_lo_que_dijo(): void
    {
        $jefa = $this->jefaDentro();
        $p = app(ProjectService::class)->registrarIdea(['name' => 'Soporte', 'source' => 'whatsapp', 'lead_id' => $jefa->id]);
        $c = app(ProjectService::class)->comentar($p, 'Tomo tu proeycto personalmente.', $jefa);
        $c->update(['seen_at' => now()]);

        Livewire::test(\App\Filament\Resources\Projects\RelationManagers\CommentsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => EditProject::class,
        ])
            ->callAction(TestAction::make('editar')->table($c), ['body' => 'Tomo tu proyecto personalmente.'])
            ->assertHasNoActionErrors();

        $c->refresh();
        $this->assertSame('Tomo tu proyecto personalmente.', $c->body);
        $this->assertNotNull($c->edited_at);
        // Lo que había visto era el texto anterior.
        $this->assertNull($c->seen_at);
    }

    /** Lo que dijo quien pidió el proyecto no se le edita. */
    public function test_lo_del_cliente_no_se_edita(): void
    {
        $jefa = $this->jefaDentro();
        $p = app(ProjectService::class)->registrarIdea(['name' => 'Soporte', 'source' => 'whatsapp', 'lead_id' => $jefa->id]);
        $c = $p->comments()->create(['side' => 'cliente', 'author_name' => 'Andrés', 'body' => 'Hola']);

        Livewire::test(\App\Filament\Resources\Projects\RelationManagers\CommentsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => EditProject::class,
        ])->assertActionHidden(TestAction::make('editar')->table($c));
    }

    /**
     * «Visto» es que quien pidió el proyecto abrió su página después del
     * mensaje. Que la abra alguien del equipo para revisarla no cuenta.
     */
    public function test_se_sabe_si_quien_lo_pidio_vio_el_mensaje(): void
    {
        $jefa = $this->jefaDentro();
        $cliente = User::create(['name' => 'Andrés', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $p = app(ProjectService::class)->registrarIdea(['name' => 'Soporte', 'source' => 'whatsapp', 'lead_id' => $jefa->id]);
        $p->update(['requested_by' => $cliente->id]);
        $c = app(ProjectService::class)->comentar($p, 'Pasa por el laboratorio.', $jefa);

        // El equipo la mira: sigue sin abrir.
        $this->get(route('proyectos.propuesta', $p))->assertOk();
        $this->assertNull($c->fresh()->seen_at);

        // La abre quien la pidió: visto, con la hora.
        $this->actingAs($cliente)->get(route('proyectos.propuesta', $p))->assertOk();
        $this->assertNotNull($c->fresh()->seen_at);
    }
}
