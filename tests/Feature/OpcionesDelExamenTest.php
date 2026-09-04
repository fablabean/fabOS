<?php

namespace Tests\Feature;

use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La respuesta correcta se marca sobre la opción, y el examen las baraja (§9).
 *
 * Con el mismo orden siempre, lo que se aprende es «la segunda», no por qué
 * la segunda. Para poder barajar, la correcta tiene que ir pegada a su texto
 * y no a su posición: en el formulario se marca sobre la propia respuesta.
 */
class OpcionesDelExamenTest extends TestCase
{
    use RefreshDatabase;

    private Course $curso;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        $this->curso = Course::create([
            'slug' => 'creality-hi', 'name' => 'Creality Hi', 'area_id' => $area->id,
            'level' => 'kilo', 'hours' => 4, 'passing_score' => 80, 'is_active' => true, 'is_public' => true,
        ]);

        $this->curso->questions()->create([
            'position' => 1, 'prompt' => '¿Qué le pasa?', 'correct' => 1,
            'options' => ['Boquilla alta', 'Boquilla baja', 'Relleno escaso', 'Cama fría', 'Filamento húmedo'],
        ]);
    }

    private function jefa(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo', 'user_category_id' => $cat->id]);
        $u->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ------------------------------------------------------ el formulario

    /** Al abrir, cada opción trae su marca: la correcta, marcada. */
    public function test_al_editar_la_correcta_viene_marcada_sobre_su_opcion(): void
    {
        $this->jefa();

        $datos = Livewire::test(EditCourse::class, ['record' => $this->curso->id])->get('data');

        $opciones = array_values(array_values($datos['questions'])[0]['opciones']);

        $this->assertSame('Boquilla baja', $opciones[1]['texto']);
        $this->assertTrue($opciones[1]['correcta']);
        $this->assertFalse($opciones[0]['correcta']);
        $this->assertFalse($opciones[2]['correcta']);
    }

    /**
     * Marcar otra desmarca la anterior -una sola correcta-, y al guardar la
     * marca se vuelve el número: el que corrige el examen.
     */
    public function test_marcar_otra_desmarca_la_anterior_y_al_guardar_queda_su_numero(): void
    {
        $this->jefa();

        $c = Livewire::test(EditCourse::class, ['record' => $this->curso->id]);

        [$pregunta, $filas] = $this->rutas($c);

        // Se marca la cuarta, «Cama fría».
        $c->set("data.questions.{$pregunta}.opciones.{$filas[3]}.correcta", true);

        $marcadas = collect($c->get("data.questions.{$pregunta}.opciones"))->map(fn ($o) => $o['correcta'])->values()->all();
        $this->assertSame([false, false, false, true, false], $marcadas, 'una sola marcada');

        $c->call('save')->assertHasNoFormErrors();

        $p = $this->curso->questions()->firstOrFail();

        $this->assertSame(3, $p->correct);
        $this->assertSame('Cama fría', $p->options[$p->correct]);
    }

    /** Sin ninguna marcada no se guarda: no habría qué corregir. */
    public function test_sin_correcta_marcada_no_se_guarda(): void
    {
        $this->jefa();

        $c = Livewire::test(EditCourse::class, ['record' => $this->curso->id]);

        [$pregunta, $filas] = $this->rutas($c);

        $c->set("data.questions.{$pregunta}.opciones.{$filas[1]}.correcta", false)
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(1, $this->curso->questions()->firstOrFail()->correct, 'no cambió');
    }

    /** Las claves con que el formulario nombra la pregunta y sus filas. */
    private function rutas($c): array
    {
        $preguntas = $c->get('data')['questions'];
        $pregunta = array_key_first($preguntas);

        return [$pregunta, array_keys($preguntas[$pregunta]['opciones'])];
    }

    // ------------------------------------------------------ el examen

    private function inscrita(): Enrollment
    {
        $categoria = UserCategory::firstOrCreate(['slug' => 'invitado'], ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo']);
        $edicion = CourseEdition::create(['course_id' => $this->curso->id, 'code' => 'ED-1', 'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true]);
        $alumno = User::create(['name' => 'Ana', 'email' => 'ana@ean.edu.co', 'status' => 'activo', 'user_category_id' => $categoria->id]);

        return Enrollment::create(['user_id' => $alumno->id, 'course_edition_id' => $edicion->id, 'status' => 'inscrito']);
    }

    /** Las opciones salen barajadas, y cada una con su número original. */
    public function test_el_examen_baraja_las_opciones_y_conserva_el_numero_de_cada_una(): void
    {
        $inscripcion = $this->inscrita();
        $ordenes = [];

        foreach (range(1, 12) as $_) {
            $html = $this->actingAs($inscripcion->user)->get(route('formacion.examen', $inscripcion))->assertOk()->getContent();

            preg_match_all('/value="(\d)" required>\s*<span>([^<]+)<\/span>/', $html, $m, PREG_SET_ORDER);

            $this->assertCount(5, $m);

            // Cada texto sigue con su número de siempre: es lo que se corrige.
            foreach ($m as [$_, $n, $texto]) {
                $this->assertSame($this->curso->questions->first()->options[(int) $n], $texto);
            }

            $ordenes[] = implode(',', array_column($m, 1));
        }

        // Cinco opciones, doce cargas: que salgan siempre iguales es casi imposible.
        $this->assertGreaterThan(1, count(array_unique($ordenes)), 'nunca se barajaron');
    }

    /** Y la corrección no depende del orden en que se vieron. */
    public function test_se_corrige_por_el_numero_original_y_no_por_la_posicion(): void
    {
        $inscripcion = $this->inscrita();
        $p = $this->curso->questions()->firstOrFail();

        $this->actingAs($inscripcion->user)
            ->post(route('formacion.calificar', $inscripcion), ['respuestas' => [$p->id => 1]])
            ->assertOk();

        $this->assertSame(100, $inscripcion->fresh()->theory_score);
    }
}
