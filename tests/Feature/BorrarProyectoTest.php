<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Asset;
use App\Models\Evidencia;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\EliminarProyecto;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Borrar un proyecto descartado (§11).
 *
 * El histórico enseña, pero después de unas cuantas pruebas la lista se llena
 * de ruido que nadie va a volver a mirar. Lo que se comprueba aquí es la
 * frontera: **lo que ocurrió de verdad no se borra con el proyecto**.
 */
class BorrarProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function proyecto(): Project
    {
        return app(ProjectService::class)->registrarIdea(['name' => 'Una idea que no cuajó']);
    }

    private function descartado(): Project
    {
        $p = $this->proyecto();
        app(ProjectService::class)->descartar($p, 'No hubo presupuesto.');

        return $p->fresh();
    }

    private function conRol(string $rol): User
    {
        $u = User::create([
            'name' => 'Quien manda', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate($rol, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // -------------------------------------------------------------- frenos

    /** Un proyecto vivo no se borra: se descarta primero, y eso obliga a decir por qué. */
    public function test_un_proyecto_vivo_no_se_borra(): void
    {
        $this->expectException(ProjectException::class);

        app(EliminarProyecto::class)($this->proyecto());
    }

    public function test_solo_el_superadmin_ve_el_boton(): void
    {
        $p = $this->descartado();

        // El listado filtra por «activo» de entrada, y lo que se quiere borrar
        // esta descartado: sin quitar el filtro, la fila no esta en la tabla.
        $this->conRol(User::ROL_ADMINISTRADOR);
        Livewire::test(ListProjects::class)
            ->filterTable('status', 'descartado')
            ->assertActionHidden(TestAction::make('borrar')->table($p));

        $this->conRol(User::ROL_SUPERADMIN);
        Livewire::test(ListProjects::class)
            ->filterTable('status', 'descartado')
            ->assertActionVisible(TestAction::make('borrar')->table($p));
    }

    /**
     * Hay que escribir el código. Un borrado irreversible detrás de un botón
     * junto a «Editar» se pulsa por error tarde o temprano.
     */
    public function test_hay_que_escribir_el_codigo(): void
    {
        $p = $this->descartado();
        $this->conRol(User::ROL_SUPERADMIN);

        Livewire::test(ListProjects::class)
            ->filterTable('status', 'descartado')
            ->callAction(TestAction::make('borrar')->table($p), ['confirmacion' => 'PRY-0000-9999'])
            ->assertHasActionErrors(['confirmacion']);

        $this->assertDatabaseHas('projects', ['id' => $p->id]);
    }

    public function test_con_el_codigo_correcto_se_borra(): void
    {
        $p = $this->descartado();
        $this->conRol(User::ROL_SUPERADMIN);

        // Al terminar, Filament vuelve a resolver la fila para redibujar la
        // tabla y no la encuentra: es cosa del banco de pruebas -en el
        // navegador la tabla simplemente se recarga sin ella-, y le pasa igual
        // a la accion de borrar que trae Filament de serie. Lo que importa es
        // lo que quedo en la base.
        try {
            Livewire::test(ListProjects::class)
                ->filterTable('status', 'descartado')
                ->callAction(TestAction::make('borrar')->table($p), ['confirmacion' => $p->code]);
        } catch (\Filament\Actions\Exceptions\ActionNotResolvableException) {
            // La fila ya no existe, que es justo lo que se queria.
        }

        $this->assertDatabaseMissing('projects', ['id' => $p->id]);
    }

    // ------------------------------------------------------ lo que se lleva

    public function test_se_lleva_todo_lo_que_cuelga_del_proyecto(): void
    {
        $p = $this->descartado();

        $p->deliverables()->create(['title' => 'Veinte letreros']);
        $p->documents()->create(['kind' => 'propuesta', 'title' => 'Propuesta 1']);
        $p->comments()->create(['body' => 'Nos lo pensamos.', 'side' => 'cliente']);
        $p->costs()->create(['concept' => 'Un flete', 'amount' => 100_000]);
        $tarea = $p->tasks()->create(['title' => 'Cortar']);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 2]);

        app(EliminarProyecto::class)($p);

        $this->assertDatabaseCount('project_deliverables', 0);
        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('project_comments', 0);
        $this->assertDatabaseCount('project_costs', 0);
        $this->assertDatabaseCount('project_tasks', 0);
        $this->assertDatabaseCount('project_time_logs', 0);
        $this->assertNull(\App\Models\ProjectTask::find($tarea->id));
    }

    /**
     * La evidencia es polimórfica y ninguna restricción de la base se la lleva:
     * sin recorrerla a mano quedarían filas huérfanas apuntando a archivos que
     * nadie borraría nunca.
     */
    public function test_se_lleva_la_evidencia_y_sus_archivos(): void
    {
        $p = $this->descartado();

        Storage::disk('local')->put('proyectos/soportes/foto.webp', 'contenido');
        Storage::disk('local')->put('proyectos/evidencia/tarea.webp', 'contenido');

        $p->evidence()->create(['kind' => 'foto', 'file_path' => 'proyectos/soportes/foto.webp']);

        $tarea = $p->tasks()->create(['title' => 'Cortar']);
        $tarea->evidence()->create(['kind' => 'foto', 'file_path' => 'proyectos/evidencia/tarea.webp']);

        $resumen = app(EliminarProyecto::class)($p);

        $this->assertSame(2, $resumen['archivos']);
        $this->assertDatabaseCount('evidencias', 0);
        Storage::disk('local')->assertMissing('proyectos/soportes/foto.webp');
        Storage::disk('local')->assertMissing('proyectos/evidencia/tarea.webp');
    }

    public function test_se_lleva_la_imagen_de_referencia(): void
    {
        $p = $this->descartado();

        Storage::disk('local')->put('proyectos/referencia/portada.webp', 'contenido');
        $p->update(['reference_image_path' => 'proyectos/referencia/portada.webp']);

        app(EliminarProyecto::class)($p->fresh());

        Storage::disk('local')->assertMissing('proyectos/referencia/portada.webp');
    }

    // ----------------------------------------------------- lo que sobrevive

    /**
     * El tiempo de máquina ocurrió. Borrar esas reservas dejaría el inventario
     * y el libro contable diciendo cosas que no cuadran, así que se desligan.
     */
    public function test_las_reservas_se_desligan_pero_se_quedan(): void
    {
        $p = $this->descartado();

        $equipo = Asset::create([
            'name' => 'Bambu ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
        ]);

        $reserva = Reservation::create([
            'reservable_type' => Asset::class,
            'reservable_id'   => $equipo->id,
            'user_id'         => User::create(['name' => 'Alguien', 'email' => uniqid() . '@t.co', 'status' => 'activo'])->id,
            'project_id'      => $p->id,
            'status'          => 'completada',
            'starts_at'       => now()->subHours(3),
            'ends_at'         => now()->subHour(),
        ]);

        $resumen = app(EliminarProyecto::class)($p);

        $this->assertSame(1, $resumen['desligadas']);
        $this->assertDatabaseHas('reservations', ['id' => $reserva->id]);
        $this->assertNull($reserva->fresh()->project_id);
    }

    /** Y el material grabado es de quien lo grabó, con su autorización. */
    public function test_el_material_del_banco_se_queda(): void
    {
        $p = $this->descartado();

        $quien = User::create(['name' => 'Quien graba', 'email' => uniqid() . '@t.co', 'status' => 'activo']);

        $pieza = \App\Models\Contenido::create([
            'user_id'            => $quien->id,
            'project_id'         => $p->id,
            'kind'               => 'foto',
            'file_path'          => 'contenido/pieza.webp',
            'rights_accepted_at' => now(),
            'rights_version'     => '2026-08',
        ]);

        app(EliminarProyecto::class)($p);

        $this->assertDatabaseHas('contenidos', ['id' => $pieza->id]);
        $this->assertNull($pieza->fresh()->project_id);
    }
}
