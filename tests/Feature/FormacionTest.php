<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\NotificationLog;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\Eligibility;
use App\Services\Booking\EligibilityService;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Cursos, ediciones, inscripciones y los certifabs que otorgan (§9). */
class FormacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function formacion(): TrainingService
    {
        return app(TrainingService::class);
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

    private function familia(string $nivelExigido = 'byte'): RiskFamily
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);

        return RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => $nivelExigido, 'requires_companion' => false,
        ]);
    }

    private function curso(array $familias = [], string $nivel = 'byte'): Course
    {
        $curso = Course::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Impresión 3D para principiantes',
            'level' => $nivel, 'hours' => 8, 'is_active' => true, 'is_public' => true,
        ]);

        $curso->riskFamilies()->sync(collect($familias)->pluck('id')->all());

        return $curso->fresh();
    }

    private function edicion(Course $curso, array $datos = []): CourseEdition
    {
        return CourseEdition::create(array_merge([
            'course_id' => $curso->id,
            'code'      => $this->formacion()->siguienteCodigo(),
            'starts_on' => now()->addWeek()->toDateString(),
            'capacity'  => 12,
            'status'    => 'abierta',
        ], $datos));
    }

    // ---------------------------------------------------------- inscripción

    public function test_inscribirse_ocupa_un_cupo(): void
    {
        $edicion = $this->edicion($this->curso(), ['capacity' => 3]);

        $this->formacion()->inscribir($edicion, $this->persona());

        $this->assertSame(1, $edicion->fresh()->inscritos());
        $this->assertSame(2, $edicion->fresh()->cuposLibres());
    }

    public function test_no_se_puede_pasar_del_cupo(): void
    {
        $edicion = $this->edicion($this->curso(), ['capacity' => 1]);
        $this->formacion()->inscribir($edicion, $this->persona());

        // Sobreinscribir no es un detalle administrativo: es gente de pie en un
        // taller con máquinas.
        $this->expectException(TrainingException::class);
        $this->formacion()->inscribir($edicion->fresh(), $this->persona());
    }

    public function test_nadie_se_inscribe_dos_veces(): void
    {
        $edicion = $this->edicion($this->curso());
        $u = $this->persona();
        $this->formacion()->inscribir($edicion, $u);

        $this->expectException(TrainingException::class);
        $this->formacion()->inscribir($edicion->fresh(), $u);
    }

    public function test_una_edicion_planeada_no_admite_inscripciones(): void
    {
        $edicion = $this->edicion($this->curso(), ['status' => 'planeada']);

        $this->expectException(TrainingException::class);
        $this->formacion()->inscribir($edicion, $this->persona());
    }

    public function test_retirarse_libera_el_cupo(): void
    {
        $edicion = $this->edicion($this->curso(), ['capacity' => 1]);
        $u = $this->persona();
        $inscripcion = $this->formacion()->inscribir($edicion, $u);

        $this->formacion()->retirar($inscripcion, 'Se le cruzó una clase');

        $this->assertSame(1, $edicion->fresh()->cuposLibres());
        $this->assertNotNull($this->formacion()->inscribir($edicion->fresh(), $this->persona()));
    }

    public function test_quien_se_retiro_puede_volver_a_inscribirse(): void
    {
        $edicion = $this->edicion($this->curso());
        $u = $this->persona();
        $inscripcion = $this->formacion()->inscribir($edicion, $u);
        $this->formacion()->retirar($inscripcion);

        $vuelta = $this->formacion()->inscribir($edicion->fresh(), $u);

        $this->assertSame($inscripcion->id, $vuelta->id, 'reusa su inscripción');
        $this->assertSame('inscrito', $vuelta->status);
        $this->assertSame(1, Enrollment::count());
    }

    // ------------------------------------------------- aprobar y habilitar

    public function test_aprobar_otorga_los_certifabs_del_curso(): void
    {
        $familia = $this->familia();
        $edicion = $this->edicion($this->curso([$familia], 'kilo'));
        $u = $this->persona();
        $inscripcion = $this->formacion()->inscribir($edicion, $u);

        $aprobada = $this->formacion()->aprobar($inscripcion, 4.5);

        $certifab = Certifab::where('user_id', $u->id)->first();

        $this->assertSame('aprobado', $aprobada->status);
        $this->assertNotNull($certifab, 'aprobar el curso habilita');
        $this->assertSame('kilo', $certifab->level);
        $this->assertSame('curso', $certifab->granted_via);
        $this->assertSame($familia->id, $certifab->risk_family_id);
    }

    public function test_aprobar_emite_un_certificado_verificable(): void
    {
        $edicion = $this->edicion($this->curso([$this->familia()]));
        $inscripcion = $this->formacion()->inscribir($edicion, $this->persona());

        $aprobada = $this->formacion()->aprobar($inscripcion);

        $this->assertNotNull($aprobada->certificate_code);
        $this->assertNotNull($aprobada->completed_at);

        $this->get(route('publico.verificar', $aprobada->certificate_code))
            ->assertOk()
            ->assertSee('Impresión 3D para principiantes');
    }

    public function test_aprobar_dos_veces_no_duplica_nada(): void
    {
        $familia = $this->familia();
        $edicion = $this->edicion($this->curso([$familia]));
        $inscripcion = $this->formacion()->inscribir($edicion, $this->persona());

        $primera = $this->formacion()->aprobar($inscripcion);
        $segunda = $this->formacion()->aprobar($inscripcion->fresh());

        $this->assertSame($primera->certificate_code, $segunda->certificate_code);
        $this->assertSame(1, Certifab::count());
    }

    public function test_un_curso_no_baja_el_nivel_que_alguien_ya_tiene(): void
    {
        $familia = $this->familia();
        $u = $this->persona();

        Certifab::create([
            'user_id' => $u->id, 'risk_family_id' => $familia->id, 'level' => 'mega',
        ]);

        $edicion = $this->edicion($this->curso([$familia], 'byte'));
        $this->formacion()->aprobar($this->formacion()->inscribir($edicion, $u));

        // Un curso básico tomado después no debería degradar a quien ya avanzó.
        $this->assertSame('mega', Certifab::where('user_id', $u->id)->first()->level);
        $this->assertSame(1, Certifab::count());
    }

    public function test_un_curso_superior_sube_el_nivel(): void
    {
        $familia = $this->familia();
        $u = $this->persona();

        Certifab::create([
            'user_id' => $u->id, 'risk_family_id' => $familia->id, 'level' => 'byte',
        ]);

        $edicion = $this->edicion($this->curso([$familia], 'giga'));
        $this->formacion()->aprobar($this->formacion()->inscribir($edicion, $u));

        $this->assertSame('giga', Certifab::where('user_id', $u->id)->first()->level);
    }

    public function test_quien_se_retiro_no_se_aprueba(): void
    {
        $edicion = $this->edicion($this->curso([$this->familia()]));
        $inscripcion = $this->formacion()->inscribir($edicion, $this->persona());
        $this->formacion()->retirar($inscripcion);

        $this->expectException(TrainingException::class);
        $this->formacion()->aprobar($inscripcion->fresh());
    }

    public function test_reprobar_no_habilita_ni_certifica(): void
    {
        $edicion = $this->edicion($this->curso([$this->familia()]));
        $inscripcion = $this->formacion()->inscribir($edicion, $this->persona());

        $reprobada = $this->formacion()->reprobar($inscripcion, 2.0, 'No completó la práctica');

        $this->assertSame('reprobado', $reprobada->status);
        $this->assertNull($reprobada->certificate_code);
        $this->assertSame(0, Certifab::count());
    }

    public function test_cerrar_una_edicion_aprueba_a_quien_siga_inscrito(): void
    {
        $familia = $this->familia();
        $edicion = $this->edicion($this->curso([$familia]));

        $this->formacion()->inscribir($edicion, $this->persona());
        $this->formacion()->inscribir($edicion->fresh(), $this->persona());
        $retirada = $this->formacion()->inscribir($edicion->fresh(), $this->persona());
        $this->formacion()->retirar($retirada);

        $aprobadas = $this->formacion()->cerrarEdicion($edicion->fresh());

        $this->assertSame(2, $aprobadas, 'quien se retiró no se aprueba');
        $this->assertSame('cerrada', $edicion->fresh()->status);
        $this->assertSame(2, Certifab::count());
    }

    // ------------------------------------------------ el efecto en reservas

    public function test_aprobar_el_curso_habilita_a_reservar_de_verdad(): void
    {
        $familia = $this->familia('byte');
        $equipo = Asset::create([
            'area_id' => $familia->area_id, 'risk_family_id' => $familia->id,
            'name' => 'Impresora ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 240, 'max_minutes' => 720,
        ]);

        $u = $this->persona();
        $eligibility = app(EligibilityService::class);

        $this->assertNotSame(
            Eligibility::AUTONOMO,
            $eligibility->evaluar($u, $equipo)->resultado,
            'sin curso no debería poder reservar solo'
        );

        $edicion = $this->edicion($this->curso([$familia], 'byte'));
        $this->formacion()->aprobar($this->formacion()->inscribir($edicion, $u));

        // Esta es la integración que importa: el curso abre la máquina.
        $this->assertSame(
            Eligibility::AUTONOMO,
            $eligibility->evaluar($u->fresh(), $equipo)->resultado
        );
    }

    // ------------------------------------------------------------- avisos

    public function test_avisa_al_inscribirse_y_al_aprobar(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $familia = $this->familia();
        $edicion = $this->edicion($this->curso([$familia]));
        $inscripcion = $this->formacion()->inscribir($edicion, $this->persona());

        $this->assertSame('enviado', NotificationLog::where('key', 'curso.inscripcion')->first()?->status);

        $aprobada = $this->formacion()->aprobar($inscripcion);
        $aviso = NotificationLog::where('key', 'curso.aprobado')->first();

        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString($aprobada->certificate_code, $aviso->body);
        $this->assertStringContainsString('FDM', $aviso->body, 'dice qué habilita');
    }
}
