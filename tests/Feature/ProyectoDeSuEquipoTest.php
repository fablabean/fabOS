<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un proyecto lo ve su equipo; lo maneja su responsable (§11).
 *
 * Antes había dos opciones y las dos malas: abrirle a alguien la sección entera
 * —y con ella los proyectos de todos, con sus clientes, lo acordado y por
 * cuánto— o no dejarle ver ninguno y contarle por WhatsApp en qué va lo suyo.
 *
 * Esto añade lo que faltaba sin quitar nada: quien está en el equipo ve **su**
 * proyecto, y quien responde por él lo maneja.
 */
class ProyectoDeSuEquipoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        app(MatrizDeAccesos::class)->sincronizar();
    }

    /** Un practicante: entra al panel, pero su rol no abre Proyectos. */
    private function practicante(): User
    {
        $u = User::create([
            'name' => 'Practicante ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        return $u->fresh();
    }

    private function entrarComo(User $u): User
    {
        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function proyecto(array $cambios = []): Project
    {
        return Project::create(array_merge([
            'code' => 'PRY-' . uniqid(), 'name' => 'Señalética del campus',
            'stage' => 'ejecucion', 'status' => 'activo',
        ], $cambios));
    }

    // ------------------------------------------------------------- ver el suyo

    public function test_sin_proyecto_la_seccion_sigue_cerrada(): void
    {
        $this->entrarComo($this->practicante());

        $this->assertFalse(ProjectResource::canAccess());
        $this->get('/admin/projects')->assertForbidden();
    }

    /** Estar en el equipo abre la sección, sin tocarle el rol a nadie. */
    public function test_estar_en_el_equipo_abre_la_seccion(): void
    {
        $quien = $this->practicante();
        $p = $this->proyecto();
        $p->members()->create(['user_id' => $quien->id, 'role' => 'equipo']);

        $this->entrarComo($quien);

        $this->assertTrue(ProjectResource::canAccess());
        $this->get('/admin/projects')->assertOk();
    }

    /** Ser el responsable también, aunque no esté apuntado como miembro. */
    public function test_el_responsable_ve_su_proyecto(): void
    {
        $quien = $this->practicante();
        $this->proyecto(['lead_id' => $quien->id]);

        $this->entrarComo($quien);

        $this->assertTrue(ProjectResource::canAccess());
    }

    /**
     * Y ve **solo** el suyo.
     *
     * Un proyecto lleva el nombre del cliente, lo que se acordó y por cuánto.
     * Abrir la sección por pertenecer a uno no puede enseñar los demás.
     */
    public function test_solo_ve_los_suyos(): void
    {
        $quien = $this->practicante();
        $mio = $this->proyecto(['name' => 'El mío', 'lead_id' => $quien->id]);
        $ajeno = $this->proyecto(['name' => 'El de otra área']);

        $this->entrarComo($quien);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$mio])
            ->assertCanNotSeeTableRecords([$ajeno]);
    }

    /** Quien tiene la sección por su rol sigue viéndolo todo. */
    public function test_quien_tiene_la_seccion_los_ve_todos(): void
    {
        $mio = $this->proyecto(['name' => 'El mío']);
        $otro = $this->proyecto(['name' => 'El de otra área']);

        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->entrarComo($u);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$mio, $otro]);
    }

    // ------------------------------------------------------- manejar el suyo

    /** Del equipo se mira; para cambiarlo hay que responder por él. */
    public function test_del_equipo_ve_pero_no_edita(): void
    {
        $quien = $this->practicante();
        $p = $this->proyecto();
        $p->members()->create(['user_id' => $quien->id, 'role' => 'equipo']);

        $this->entrarComo($quien);

        $this->assertTrue(ProjectResource::canView($p));
        $this->assertFalse(ProjectResource::canEdit($p));

        $this->get('/admin/projects/' . $p->getKey() . '/edit')->assertForbidden();
    }

    public function test_el_responsable_lo_maneja(): void
    {
        $quien = $this->practicante();
        $p = $this->proyecto(['lead_id' => $quien->id]);

        $this->entrarComo($quien);

        $this->assertTrue(ProjectResource::canEdit($p));
        $this->get('/admin/projects/' . $p->getKey() . '/edit')->assertOk();
    }

    /** Pero solo el suyo: liderar uno no abre los demás. */
    public function test_liderar_uno_no_abre_los_demas(): void
    {
        $quien = $this->practicante();
        $this->proyecto(['lead_id' => $quien->id]);
        $ajeno = $this->proyecto(['name' => 'El de otra área']);

        $this->entrarComo($quien);

        $this->assertFalse(ProjectResource::canView($ajeno));
        $this->assertFalse(ProjectResource::canEdit($ajeno));

        // 404 y no 403, y esta bien asi: como la lista esta acotada a los
        // suyos, el proyecto ajeno ni siquiera se encuentra. Un 403 confirmaria
        // que existe, que es mas de lo que hace falta contarle.
        $this->get('/admin/projects/' . $ajeno->getKey() . '/edit')->assertNotFound();
    }

    // ------------------------------------------------------------- el tablero

    public function test_el_equipo_entra_a_su_tablero(): void
    {
        $quien = $this->practicante();
        $p = $this->proyecto();
        $p->members()->create(['user_id' => $quien->id, 'role' => 'equipo']);

        $this->entrarComo($quien);

        $this->get(route('proyectos.tablero', $p))->assertOk();
    }

    /**
     * Y tener rol de backoffice ya no basta para abrir cualquier tablero.
     *
     * Un tablero lleva el cliente, lo acordado y las tareas con nombres: eso no
     * es de todo el que pase por el panel.
     */
    public function test_un_tablero_ajeno_se_cierra(): void
    {
        $quien = $this->practicante();
        $this->proyecto(['lead_id' => $quien->id]);
        $ajeno = $this->proyecto(['name' => 'El de otra área']);

        $this->entrarComo($quien);

        $this->get(route('proyectos.tablero', $ajeno))->assertForbidden();
    }

    /** Mover una tarea es cambiar el proyecto: la mueve quien responde por él. */
    public function test_del_equipo_no_mueve_tareas(): void
    {
        $quien = $this->practicante();
        $p = $this->proyecto();
        $p->members()->create(['user_id' => $quien->id, 'role' => 'equipo']);
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $this->entrarComo($quien);

        $this->post(route('proyectos.tarea.mover', $tarea), ['estado' => 'en_curso'])
            ->assertForbidden();
    }

    public function test_el_responsable_mueve_tareas(): void
    {
        $quien = $this->practicante();
        $p = $this->proyecto(['lead_id' => $quien->id]);
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $this->entrarComo($quien);

        $this->post(route('proyectos.tarea.mover', $tarea), ['estado' => 'en_curso'])
            ->assertRedirect();

        $this->assertSame('en_curso', $tarea->fresh()->status);
    }

    /** El cronograma general enseña los suyos, no el año entero del laboratorio. */
    public function test_el_cronograma_general_se_limita_a_los_suyos(): void
    {
        $quien = $this->practicante();
        $this->proyecto([
            'name' => 'El mío', 'lead_id' => $quien->id,
            'starts_on' => '2026-09-01', 'due_on' => '2026-10-01',
        ]);
        $this->proyecto([
            'name' => 'El de otra área',
            'starts_on' => '2026-09-01', 'due_on' => '2026-10-01',
        ]);

        $this->entrarComo($quien);

        $this->get(route('proyectos.cronograma'))
            ->assertOk()
            ->assertSee('El mío')
            ->assertDontSee('El de otra área');
    }
}
