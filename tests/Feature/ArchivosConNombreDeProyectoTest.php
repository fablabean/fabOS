<?php

namespace Tests\Feature;

use App\Filament\Pages\ArchivosDeProyectos;
use App\Models\Evidencia;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cada archivo de un proyecto lleva el proyecto en el nombre (§11).
 *
 * `PRY-2026-0159_20261005_03_pieza-1.stl`: código, fecha, consecutivo del día
 * y el nombre con que llegó. Y todos se ven juntos en una pantalla.
 */
class ArchivosConNombreDeProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->seed(NotificationTemplateSeeder::class);
        UserCategory::firstOrCreate(['slug' => 'invitado'], ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1]);

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa Lab', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        return $u->fresh();
    }

    private function entra(User $u): void
    {
        $this->actingAs($u)->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    private function pedir(array $archivos = []): Project
    {
        $this->post(route('proyectos.solicitar.store'), [
            'titulo' => 'Soporte de celular', 'resumen' => 'Un soporte impreso en 3D para el escritorio.',
            'cliente' => 'externo', 'nombre' => 'Andrés Malagón', 'correo' => 'andres' . uniqid() . '@ejemplo.co',
            'soportes' => $archivos,
        ])->assertSessionHasNoErrors();

        return Project::latest('id')->firstOrFail();
    }

    public function test_lo_que_se_sube_al_pedir_lleva_el_proyecto_la_fecha_y_el_consecutivo(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00', config('fabos.lab.timezone')));

        $p = $this->pedir([
            UploadedFile::fake()->create('Carcasa V3 FINAL.stl', 40),
            UploadedFile::fake()->create('plano.pdf', 30, 'application/pdf'),
        ]);

        $nombres = $p->evidence()->orderBy('id')->pluck('original_name')->all();

        $this->assertSame([
            $p->code . '_20261005_01_carcasa-v3-final.stl',
            $p->code . '_20261005_02_plano.pdf',
        ], $nombres);

        // Y cada uno sabe de qué proyecto es.
        $this->assertSame([$p->id], $p->evidence()->pluck('project_id')->unique()->all());
    }

    /** El consecutivo es del día y del proyecto: otro proyecto, o mañana, vuelve a empezar. */
    public function test_el_consecutivo_es_por_proyecto_y_por_dia(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00', config('fabos.lab.timezone')));
        $uno = $this->pedir([UploadedFile::fake()->create('a.stl', 10)]);
        $otro = $this->pedir([UploadedFile::fake()->create('b.stl', 10)]);

        $this->assertStringContainsString('_20261005_01_a.stl', $uno->evidence()->first()->original_name);
        $this->assertStringContainsString('_20261005_01_b.stl', $otro->evidence()->first()->original_name);

        // El mismo día, otro archivo del primero: el 02.
        $jefa = $this->jefa();
        app(\App\Services\Projects\SoportesDeSolicitud::class)
            ->guardar($uno, [UploadedFile::fake()->create('c.stl', 10)], null, $jefa->id);
        $this->assertStringContainsString('_20261005_02_c.stl', $uno->evidence()->reorder()->latest('id')->first()->original_name);

        // Al día siguiente vuelve al 01.
        $this->travelTo(Carbon::parse('2026-10-06 09:00', config('fabos.lab.timezone')));
        app(\App\Services\Projects\SoportesDeSolicitud::class)
            ->guardar($uno, [UploadedFile::fake()->create('d.stl', 10)], null, $jefa->id);
        $this->assertStringContainsString('_20261006_01_d.stl', $uno->evidence()->reorder()->latest('id')->first()->original_name);
    }

    /** Lo que cuelga de una tarea o de un entregable también es del proyecto. */
    public function test_el_archivo_de_una_tarea_lleva_el_nombre_de_su_proyecto(): void
    {
        $p = $this->pedir();
        $tarea = $p->tasks()->create(['title' => 'Imprimir', 'status' => 'pendiente']);

        Storage::disk('local')->put('proyectos/evidencia/x.jpg', 'x');
        $e = $tarea->evidence()->create(['kind' => 'foto', 'file_path' => 'proyectos/evidencia/x.jpg', 'original_name' => 'IMG_2041.jpg']);

        $this->assertSame($p->id, $e->project_id);
        $this->assertMatchesRegularExpression('/^' . preg_quote($p->code, '/') . '_\d{8}_01_img-2041\.jpg$/', $e->original_name);
    }

    public function test_el_nombre_ordenado_no_se_ordena_dos_veces_y_aguanta_un_archivo_sin_nombre(): void
    {
        $fecha = Carbon::parse('2026-10-05 10:00');

        $una = Evidencia::nombreOrdenado('PRY-2026-0159', $fecha, 3, 'pieza 1.STL', 'proyectos/soportes/abc.bin');
        $this->assertSame('PRY-2026-0159_20261005_03_pieza-1.stl', $una);

        // Vuelto a pasar —al reemplazar el archivo, por ejemplo— no acumula prefijos.
        $this->assertSame(
            'PRY-2026-0159_20261006_01_pieza-1.stl',
            Evidencia::nombreOrdenado('PRY-2026-0159', $fecha->copy()->addDay(), 1, $una, 'x.bin'),
        );

        // Sin nombre: el tipo, y la extensión del archivo guardado.
        $this->assertSame(
            'PRY-2026-0159_20261005_02_foto.webp',
            Evidencia::nombreOrdenado('PRY-2026-0159', $fecha, 2, null, 'proyectos/soportes/01ABC.webp', 'foto'),
        );
    }

    /** Todos juntos, en una pantalla: se buscan por nombre o por proyecto. */
    public function test_la_lista_de_archivos_de_proyectos(): void
    {
        $uno = $this->pedir([UploadedFile::fake()->create('carcasa.stl', 10)]);
        $otro = $this->pedir([UploadedFile::fake()->create('letrero.pdf', 10, 'application/pdf')]);

        $this->entra($this->jefa());

        Livewire::test(ArchivosDeProyectos::class)
            ->assertOk()
            ->assertSee($uno->code)
            ->assertSee('carcasa.stl')
            ->assertSee('letrero.pdf')
            ->searchTable('carcasa')
            ->assertCanSeeTableRecords($uno->evidence)
            ->assertCanNotSeeTableRecords($otro->evidence)
            ->searchTable($otro->code)
            ->assertCanSeeTableRecords($otro->evidence)
            ->assertCanNotSeeTableRecords($uno->evidence);
    }

    /** El nombre es una etiqueta: en el disco el archivo sigue con su nombre aleatorio y privado. */
    public function test_el_archivo_en_el_disco_no_lleva_el_nombre_del_proyecto(): void
    {
        $p = $this->pedir([UploadedFile::fake()->create('carcasa.stl', 10)]);
        $e = $p->evidence()->firstOrFail();

        $this->assertStringNotContainsString($p->code, $e->file_path);
        $this->assertMatchesRegularExpression('#^proyectos/soportes/[A-Za-z0-9]{40}\.stl$#', $e->file_path);
        Storage::disk('local')->assertExists($e->file_path);

        // Y sin sesión no se baja, se sepa el nombre o no.
        $this->get(route('proyectos.evidencia', $e))->assertForbidden();
    }
}
