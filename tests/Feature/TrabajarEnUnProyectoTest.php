<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectCost;
use App\Models\ProjectTask;
use App\Models\ProjectTimeLog;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Trabajar en un proyecto sin tener la sección de Proyectos (§5, §11).
 *
 * Quien trabaja en un proyecto no siempre tiene la sección abierta: el
 * practicante que registra sus horas, quien sube la foto del avance, quien
 * anota un costo. Hasta ahora eso quedaba en uno de dos extremos, y ninguno
 * servía: o se le abría la sección entera —y veía todos los proyectos del
 * laboratorio, con sus clientes y sus valores—, o dentro del proyecto no podía
 * tocar nada y lo suyo lo tenía que registrar otro.
 *
 * Lo que se prueba aquí es el punto medio: **maneja lo que él creó, ve lo que
 * le asignaron, y del resto del proyecto no toca nada.**
 */
class TrabajarEnUnProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    /** El proyecto, con su responsable y una persona apuntada al equipo. */
    private function proyectoCon(User $responsable, ?User $miembro = null): Project
    {
        $proyecto = app(ProjectService::class)->registrarIdea([
            'name' => 'Señalética', 'summary' => 'Diez piezas.',
        ], $responsable);

        $proyecto->update(['lead_id' => $responsable->id]);

        if ($miembro) {
            $proyecto->members()->create(['user_id' => $miembro->id, 'role' => 'apoyo']);
        }

        return $proyecto->fresh();
    }

    // --------------------------------------------------------- lo que es suyo

    public function test_maneja_lo_que_el_mismo_creo(): void
    {
        $practicante = $this->persona(User::ROL_PRACTICANTE);
        $proyecto = $this->proyectoCon($this->persona(User::ROL_ADMINISTRADOR), $practicante);

        $suyas = ProjectTimeLog::create([
            'project_id' => $proyecto->id, 'user_id' => $practicante->id,
            'worked_on' => now()->toDateString(), 'hours' => 3, 'activity' => 'Corte',
        ]);

        $this->assertTrue($practicante->can('view', $suyas));
        $this->assertTrue($practicante->can('update', $suyas));
        $this->assertTrue($practicante->can('delete', $suyas));
    }

    /** Y no lo de los demás, aunque sea del mismo proyecto. */
    public function test_no_toca_lo_que_registro_otro(): void
    {
        $practicante = $this->persona(User::ROL_PRACTICANTE);
        $responsable = $this->persona(User::ROL_ADMINISTRADOR);
        $proyecto = $this->proyectoCon($responsable, $practicante);

        // Los costos dicen a cuánto se vendió el proyecto y con qué margen: no
        // son de todo el que pase por el equipo.
        $costo = ProjectCost::create([
            'project_id' => $proyecto->id, 'kind' => 'insumo', 'concept' => 'Acrílico',
            'amount' => 400_000, 'incurred_on' => now()->toDateString(),
            'registered_by' => $responsable->id,
        ]);

        $this->assertFalse($practicante->can('view', $costo));
        $this->assertFalse($practicante->can('update', $costo));
        $this->assertFalse($practicante->can('delete', $costo));
    }

    /**
     * Una tarea asignada se ve aunque la haya escrito otro: si no, no se le
     * podría encargar nada a nadie —no vería el encargo—.
     */
    public function test_ve_la_tarea_que_le_asignaron_aunque_no_la_creara(): void
    {
        $practicante = $this->persona(User::ROL_PRACTICANTE);
        $responsable = $this->persona(User::ROL_ADMINISTRADOR);
        $proyecto = $this->proyectoCon($responsable, $practicante);

        $tarea = ProjectTask::create([
            'project_id' => $proyecto->id, 'title' => 'Cortar las piezas',
            'status' => 'por_hacer', 'created_by' => $responsable->id,
            'assigned_to' => $practicante->id,
        ]);

        $this->assertTrue($practicante->can('view', $tarea));

        // Verla, sí. Borrar la tarea que le encargaron, no: eso es decidir por
        // el proyecto, y quien la encargó es quien la retira.
        $this->assertFalse($practicante->can('delete', $tarea));
    }

    public function test_una_tarea_de_otro_que_no_le_toca_no_la_ve(): void
    {
        $practicante = $this->persona(User::ROL_PRACTICANTE);
        $responsable = $this->persona(User::ROL_ADMINISTRADOR);
        $proyecto = $this->proyectoCon($responsable, $practicante);

        $tarea = ProjectTask::create([
            'project_id' => $proyecto->id, 'title' => 'Hablar con el cliente',
            'status' => 'por_hacer', 'created_by' => $responsable->id,
        ]);

        $this->assertFalse($practicante->can('view', $tarea));
    }

    /** Fuera de su proyecto no maneja nada, ni siquiera lo que lleve su nombre. */
    public function test_fuera_de_su_proyecto_no_toca_nada(): void
    {
        $practicante = $this->persona(User::ROL_PRACTICANTE);
        $ajeno = $this->proyectoCon($this->persona(User::ROL_ADMINISTRADOR));

        $suyas = ProjectTimeLog::create([
            'project_id' => $ajeno->id, 'user_id' => $practicante->id,
            'worked_on' => now()->toDateString(), 'hours' => 2, 'activity' => 'Algo',
        ]);

        $this->assertFalse($practicante->can('view', $suyas));
        $this->assertFalse($practicante->can('update', $suyas));
    }

    // ------------------------------------------------- lo que no se rompe

    /**
     * El responsable maneja su proyecto entero.
     *
     * Responde por él, y uno que no pueda corregir un costo mal tecleado en su
     * propio proyecto acaba pidiéndoselo a alguien por chat.
     */
    public function test_el_responsable_maneja_todo_lo_de_su_proyecto(): void
    {
        $responsable = $this->persona(User::ROL_PRACTICANTE);
        $otro = $this->persona(User::ROL_PRACTICANTE);
        $proyecto = $this->proyectoCon($responsable, $otro);

        $costo = ProjectCost::create([
            'project_id' => $proyecto->id, 'kind' => 'insumo', 'concept' => 'Acrílico',
            'amount' => 400_000, 'incurred_on' => now()->toDateString(),
            'registered_by' => $otro->id,
        ]);

        $this->assertTrue($responsable->can('view', $costo));
        $this->assertTrue($responsable->can('update', $costo));
        $this->assertTrue($responsable->can('delete', $costo));
    }

    /**
     * Y quien tiene la sección la sigue teniendo entera.
     *
     * Esta es la que estaba rota antes y nadie había mirado: como una tarea no
     * tiene sección propia, la política de por defecto no sabía a qué casilla
     * de la matriz mirar y se caía del lado del superadmin. El administrador no
     * podía editar una tarea desde la ficha del proyecto.
     */
    public function test_el_administrador_maneja_las_tareas_de_cualquier_proyecto(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $ajeno = $this->proyectoCon($this->persona(User::ROL_PRACTICANTE));

        $tarea = ProjectTask::create([
            'project_id' => $ajeno->id, 'title' => 'Cortar', 'status' => 'por_hacer',
        ]);

        $this->assertTrue($admin->can('view', $tarea));
        $this->assertTrue($admin->can('update', $tarea));
    }

    /** Comunicaciones no entra a Proyectos, pero sí a lo suyo dentro de uno. */
    public function test_comunicaciones_maneja_lo_suyo_en_el_proyecto_que_trabaja(): void
    {
        $comunicaciones = $this->persona(User::ROL_COMUNICACIONES);
        $proyecto = $this->proyectoCon($this->persona(User::ROL_ADMINISTRADOR), $comunicaciones);

        $suyo = ProjectComment::create([
            'project_id' => $proyecto->id, 'user_id' => $comunicaciones->id,
            'author_name' => $comunicaciones->name, 'side' => 'laboratorio',
            'body' => 'Subí las fotos del avance.',
        ]);

        $this->assertFalse(
            $comunicaciones->puedeVerLaSeccion('project'),
            'comunicaciones no tiene la sección: es justo el caso que esto viene a resolver',
        );

        $this->assertTrue($comunicaciones->can('view', $suyo));
        $this->assertTrue($comunicaciones->can('update', $suyo));
    }

    /**
     * Lo que ya existía se queda sin autor a propósito: nadie sabe hoy quién lo
     * escribió, y darlo por suyo abriría de par en par lo que esto cierra.
     */
    public function test_lo_viejo_sin_autor_no_es_de_nadie(): void
    {
        $miembro = $this->persona(User::ROL_PRACTICANTE);
        $proyecto = $this->proyectoCon($this->persona(User::ROL_ADMINISTRADOR), $miembro);

        $huerfana = ProjectTask::create([
            'project_id' => $proyecto->id, 'title' => 'De antes', 'status' => 'por_hacer',
        ]);

        $this->assertFalse($miembro->can('update', $huerfana));
        $this->assertFalse($miembro->can('delete', $huerfana));
    }
}
