<?php

namespace Tests\Feature;

use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Apagar y borrar cursos desde la lista (§9).
 *
 * Apagar no borra nada: el curso deja de ofrecerse y se queda con su gente y
 * sus notas. Borrar solo se puede cuando nadie pasó por él: una inscripción
 * es una persona con su nota, sus intentos y lo que alguien firmó delante de
 * la máquina, y eso no se borra.
 */
class ApagarYBorrarCursosTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo', 'user_category_id' => $cat->id]);
        $u->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    private function curso(string $slug, bool $conGente = false): Course
    {
        $c = Course::create([
            'slug' => $slug, 'name' => 'Curso ' . $slug, 'area_id' => $this->area->id,
            'level' => 'byte', 'hours' => 4, 'is_active' => true, 'is_public' => true,
        ]);

        $c->lessons()->create(['position' => 1, 'title' => 'Una', 'body' => 'Pantalla.']);

        $edicion = CourseEdition::create([
            'course_id' => $c->id, 'code' => 'ED-' . $slug, 'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true,
        ]);

        if ($conGente) {
            $cat = UserCategory::firstOrCreate(['slug' => 'invitado'], ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo']);
            $alumno = User::create(['name' => 'Ana ' . $slug, 'email' => $slug . '@ean.edu.co', 'status' => 'activo', 'user_category_id' => $cat->id]);

            Enrollment::create(['user_id' => $alumno->id, 'course_edition_id' => $edicion->id, 'status' => 'inscrito', 'theory_attempts' => 1]);
        }

        return $c;
    }

    // ------------------------------------------------------------ apagar

    public function test_se_apaga_desde_la_lista_y_no_se_pierde_nada(): void
    {
        $c = $this->curso('laser', conGente: true);

        Livewire::test(ListCourses::class)
            ->call('updateTableColumnState', 'is_active', (string) $c->getKey(), false);

        $c->refresh();

        $this->assertFalse($c->is_active);
        $this->assertSame(1, $c->inscripciones()->count(), 'la gente sigue ahí');
        $this->assertSame(1, $c->lessons()->count());
    }

    public function test_apagar_por_lotes_los_esconde_del_sitio_y_encender_los_devuelve(): void
    {
        $a = $this->curso('a');
        $b = $this->curso('b', conGente: true);

        Livewire::test(ListCourses::class)
            ->selectTableRecords([$a, $b])
            ->callAction(TestAction::make('apagar')->table()->bulk())
            ->assertNotified();

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($a->fresh()->is_public);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertSame(1, $b->inscripciones()->count());

        Livewire::test(ListCourses::class)
            ->filterTable('is_active', false)
            ->selectTableRecords([$a, $b])
            ->callAction(TestAction::make('encender')->table()->bulk());

        $this->assertTrue($a->fresh()->is_active);
        $this->assertTrue($b->fresh()->is_active);
    }

    // ------------------------------------------------------------ borrar

    public function test_sin_gente_se_borra_con_su_teoria_y_sus_ediciones_vacias(): void
    {
        $c = $this->curso('vacio');

        $this->assertTrue($c->sePuedeBorrar());

        Livewire::test(ListCourses::class)
            ->callAction(TestAction::make('delete')->table($c));

        $this->assertDatabaseMissing('courses', ['id' => $c->id]);
        $this->assertDatabaseMissing('course_editions', ['course_id' => $c->id]);
        $this->assertDatabaseMissing('course_lessons', ['course_id' => $c->id]);
    }

    public function test_con_gente_no_se_borra_y_dice_por_que(): void
    {
        $c = $this->curso('usado', conGente: true);

        $this->assertFalse($c->sePuedeBorrar());
        $this->assertStringContainsString('no se borra, se apaga', $c->porQueNoSeBorra());

        Livewire::test(ListCourses::class)
            ->assertActionDisabled(TestAction::make('delete')->table($c));

        $this->assertDatabaseHas('courses', ['id' => $c->id]);
    }

    /** Por lotes se borra lo que se puede, y se dice cuál se quedó. */
    public function test_por_lotes_se_borra_lo_vacio_y_lo_usado_se_queda(): void
    {
        $vacio = $this->curso('vacio');
        $usado = $this->curso('usado', conGente: true);

        Livewire::test(ListCourses::class)
            ->selectTableRecords([$vacio, $usado])
            ->callAction(TestAction::make('borrar')->table()->bulk())
            ->assertNotified();

        $this->assertDatabaseMissing('courses', ['id' => $vacio->id]);
        $this->assertDatabaseHas('courses', ['id' => $usado->id]);
        $this->assertTrue($usado->fresh()->is_active, 'borrar no apaga por su cuenta');
        $this->assertSame(1, $usado->inscripciones()->count());
    }

    public function test_el_texto_del_aviso_cuenta_lo_que_se_va(): void
    {
        $c = $this->curso('vacio');
        $c->questions()->create(['position' => 1, 'prompt' => '¿?', 'options' => ['a', 'b'], 'correct' => 0]);

        $texto = \App\Filament\Resources\Courses\Tables\CoursesTable::queSeVa($c);

        $this->assertStringContainsString('una pantalla de teoría', $texto);
        $this->assertStringContainsString('una pregunta', $texto);
        $this->assertStringContainsString('una edición vacía', $texto);
    }
}
