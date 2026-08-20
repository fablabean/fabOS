<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Training\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** El catálogo de formación y la inscripción desde el sitio (§9). */
class FormacionPublicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function curso(array $datos = []): Course
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
        ]);

        $curso = Course::create(array_merge([
            'slug' => 'c-' . uniqid(), 'name' => 'byte · Impresión 3D',
            'level' => 'byte', 'hours' => 8, 'area_id' => $area->id,
            'summary' => 'Del modelo al objeto.',
            'is_active' => true, 'is_public' => true,
        ], $datos));

        $curso->riskFamilies()->sync([$familia->id]);

        return $curso->fresh();
    }

    private function edicion(Course $curso, array $datos = []): CourseEdition
    {
        return CourseEdition::create(array_merge([
            'course_id' => $curso->id,
            'code'      => app(TrainingService::class)->siguienteCodigo(),
            'starts_on' => now()->addWeek()->toDateString(),
            'capacity'  => 10,
            'status'    => 'abierta',
        ], $datos));
    }

    public function test_el_catalogo_es_publico(): void
    {
        $curso = $this->curso();
        $this->edicion($curso, ['schedule_note' => 'Martes de 14:00 a 17:00']);

        // Sin sesión: es la vitrina de lo que enseña el laboratorio.
        $this->get(route('formacion'))
            ->assertOk()
            ->assertSee('byte · Impresión 3D')
            ->assertSee('Martes de 14:00 a 17:00')
            ->assertSee('FDM')
            ->assertSee('Entrar para inscribirme');
    }

    public function test_un_curso_no_publico_no_sale_en_la_vitrina(): void
    {
        $this->curso(['name' => 'Curso interno', 'is_public' => false]);

        $this->get(route('formacion'))->assertOk()->assertDontSee('Curso interno');
    }

    public function test_una_edicion_planeada_no_se_ofrece(): void
    {
        $curso = $this->curso();
        $this->edicion($curso, ['status' => 'planeada']);

        $this->get(route('formacion'))
            ->assertOk()
            ->assertSee('Sin fechas abiertas por ahora')
            ->assertDontSee('Inscribirme');
    }

    public function test_alguien_con_sesion_se_inscribe_desde_el_sitio(): void
    {
        $curso = $this->curso();
        $edicion = $this->edicion($curso, ['capacity' => 2]);
        $u = $this->persona();

        $this->actingAs($u)
            ->post(route('formacion.inscribir', $edicion))
            ->assertRedirect();

        $this->assertSame(1, $edicion->fresh()->inscritos());

        // Y ya no se le vuelve a ofrecer el botón.
        $this->actingAs($u)->get(route('formacion'))
            ->assertOk()
            ->assertSee('Ya estás inscrito')
            ->assertDontSee('Inscribirme');
    }

    public function test_inscribirse_sin_cupo_avisa_sin_romper(): void
    {
        $curso = $this->curso();
        $edicion = $this->edicion($curso, ['capacity' => 1]);
        app(TrainingService::class)->inscribir($edicion, $this->persona());

        $this->actingAs($this->persona())
            ->post(route('formacion.inscribir', $edicion->fresh()))
            ->assertRedirect()
            ->assertSessionHasErrors('inscripcion');

        $this->assertSame(1, $edicion->fresh()->inscritos());
    }

    public function test_se_puede_liberar_el_propio_cupo(): void
    {
        $edicion = $this->edicion($this->curso(), ['capacity' => 1]);
        $u = $this->persona();
        $inscripcion = app(TrainingService::class)->inscribir($edicion, $u);

        $this->actingAs($u)
            ->post(route('formacion.retirar', $inscripcion))
            ->assertRedirect();

        $this->assertSame('retirado', $inscripcion->fresh()->status);
        $this->assertSame(1, $edicion->fresh()->cuposLibres());
    }

    public function test_nadie_retira_el_cupo_de_otro(): void
    {
        $edicion = $this->edicion($this->curso());
        $inscripcion = app(TrainingService::class)->inscribir($edicion, $this->persona());

        $this->actingAs($this->persona())
            ->post(route('formacion.retirar', $inscripcion))
            ->assertForbidden();

        $this->assertSame('inscrito', $inscripcion->fresh()->status);
    }

    public function test_inscribirse_exige_sesion(): void
    {
        $edicion = $this->edicion($this->curso());

        $this->post(route('formacion.inscribir', $edicion))->assertRedirect(route('login'));
        $this->assertSame(0, Enrollment::count());
    }

    public function test_los_cursos_sembrados_habilitan_familias_reales(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->seed(\Database\Seeders\CourseSeeder::class);

        $byte3d = Course::where('slug', 'byte-impresion-3d')->first();
        $induccion = Course::where('slug', 'bit-induccion')->first();

        $this->assertSame(['FDM (filamento)'], $byte3d->riskFamilies->pluck('name')->all());
        $this->assertTrue($induccion->riskFamilies->isEmpty(), 'la inducción no abre máquinas');
        $this->assertSame(8, Course::count());
    }
}
