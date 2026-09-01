<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Teoría, examen y evaluación presencial (§9, §10).
 *
 * Un curso era una edición con fechas y alguien que marcaba «aprobado» a mano.
 * Sirve para un taller, pero no para lo que habilita a usar una máquina sin
 * nadie al lado: el certifab dice que esa persona **sabe**, y con «asistió» no
 * se sabe si sabe.
 *
 * Los tres pasos importan por separado: la teoría se lee cuando se pueda, el
 * examen corrige solo, y la práctica la firma una persona delante de la
 * máquina —una pantalla no puede ver si alguien nivela una cama—.
 */
class CursoConExamenTest extends TestCase
{
    use RefreshDatabase;

    private Course $curso;
    private CourseEdition $edicion;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        $this->curso = Course::create([
            'slug' => 'creality-hi', 'name' => 'Creality Hi · kilo', 'area_id' => $area->id,
            'level' => 'kilo', 'summary' => 'Uso autónomo de la Creality Hi',
            'hours' => 4, 'passing_score' => 80, 'requires_practical' => true,
            'is_active' => true, 'is_public' => true,
        ]);

        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'fdm', 'name' => 'Impresión FDM',
        ]);
        $this->curso->riskFamilies()->attach($familia->id);

        $this->curso->lessons()->create([
            'position' => 1, 'title' => 'Antes de imprimir', 'body' => 'Nivelar la cama.',
        ]);

        // Cinco preguntas: con 80 % hay que acertar cuatro.
        foreach (range(1, 5) as $i) {
            $this->curso->questions()->create([
                'position' => $i,
                'prompt'   => 'Pregunta ' . $i,
                'options'  => ['Mal', 'Bien', 'Peor'],
                'correct'  => 1,
                'explanation' => 'Porque sí, la ' . $i,
            ]);
        }

        $this->edicion = CourseEdition::create([
            'course_id' => $this->curso->id, 'code' => 'ED-1',
            'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true,
        ]);
    }

    private function alguien(): User
    {
        return User::create([
            'name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function inscribir(): Enrollment
    {
        return Enrollment::create([
            'course_edition_id' => $this->edicion->id,
            'user_id' => $this->alguien()->id,
            'status' => 'inscrito',
            'enrolled_at' => now(),
        ]);
    }

    private function formacion(): TrainingService
    {
        return app(TrainingService::class);
    }

    /** Todas bien menos las que se digan. */
    private function respuestas(int $fallos = 0): array
    {
        $r = [];
        $falladas = 0;

        foreach ($this->curso->questions as $p) {
            $r[$p->id] = $falladas++ < $fallos ? 0 : 1;
        }

        return $r;
    }

    // -------------------------------------------------------------- el examen

    public function test_el_examen_se_corrige_y_da_nota(): void
    {
        $inscripcion = $this->inscribir();

        $resultado = $this->formacion()->calificarExamen($inscripcion, $this->respuestas());

        $this->assertSame(100, $resultado['nota']);
        $this->assertTrue($resultado['aprobado']);
        $this->assertTrue($inscripcion->fresh()->teoriaAprobada());
    }

    public function test_por_debajo_del_minimo_no_aprueba(): void
    {
        $inscripcion = $this->inscribir();

        // Dos fallos de cinco: 60 %, y el mínimo es 80.
        $resultado = $this->formacion()->calificarExamen($inscripcion, $this->respuestas(2));

        $this->assertSame(60, $resultado['nota']);
        $this->assertFalse($resultado['aprobado']);
        $this->assertFalse($inscripcion->fresh()->teoriaAprobada());
    }

    /**
     * Lo que falló vuelve con su explicación.
     *
     * Un examen que solo dice «mal» enseña a adivinar, no a operar la máquina.
     */
    public function test_devuelve_lo_que_fallo_con_su_explicacion(): void
    {
        $resultado = $this->formacion()->calificarExamen($this->inscribir(), $this->respuestas(2));

        $this->assertCount(2, $resultado['fallos']);
        $this->assertNotEmpty($resultado['fallos']->first()->explanation);
    }

    /** Reintentar no borra la fecha del día que se aprobó. */
    public function test_la_fecha_de_aprobacion_no_se_pisa_al_reintentar(): void
    {
        $inscripcion = $this->inscribir();

        $this->formacion()->calificarExamen($inscripcion, $this->respuestas());
        $cuando = $inscripcion->fresh()->theory_passed_at;

        $this->formacion()->calificarExamen($inscripcion->fresh(), $this->respuestas(3));

        $this->assertEquals($cuando, $inscripcion->fresh()->theory_passed_at);
        $this->assertSame(2, $inscripcion->fresh()->theory_attempts);
    }

    // ----------------------------------------------------------- la práctica

    /** La práctica se evalúa sobre lo que la teoría ya explicó. */
    public function test_la_practica_no_se_firma_antes_del_examen(): void
    {
        $this->expectException(TrainingException::class);

        $this->formacion()->registrarPractica($this->inscribir(), $this->alguien());
    }

    public function test_la_practica_la_firma_una_persona(): void
    {
        $inscripcion = $this->inscribir();
        $instructor = $this->alguien();

        $this->formacion()->calificarExamen($inscripcion, $this->respuestas());
        $this->formacion()->registrarPractica($inscripcion->fresh(), $instructor, 'Niveló la cama sin ayuda.');

        $inscripcion->refresh();

        $this->assertTrue($inscripcion->practicaAprobada());
        $this->assertSame($instructor->id, $inscripcion->practical_by);
        $this->assertStringContainsString('Niveló', $inscripcion->practical_notes);
    }

    // ------------------------------------------------------------ el certifab

    /**
     * El certifab no se firma antes de tiempo.
     *
     * Dice que esa persona puede usar la máquina sin nadie al lado. Si se
     * pudiera otorgar saltándose el examen o la práctica, la palabra dejaría de
     * significar eso.
     */
    public function test_sin_examen_aprobado_no_hay_certifab(): void
    {
        $inscripcion = $this->inscribir();

        $this->expectExceptionMessage('examen teórico');

        $this->formacion()->aprobar($inscripcion);
    }

    public function test_sin_practica_tampoco(): void
    {
        $inscripcion = $this->inscribir();
        $this->formacion()->calificarExamen($inscripcion, $this->respuestas());

        $this->expectExceptionMessage('evaluación presencial');

        $this->formacion()->aprobar($inscripcion->fresh());
    }

    /** Con los dos pasos hechos, el certifab sale solo. */
    public function test_con_los_dos_pasos_se_otorga_el_certifab(): void
    {
        $inscripcion = $this->inscribir();

        $this->formacion()->calificarExamen($inscripcion, $this->respuestas());
        $this->formacion()->registrarPractica($inscripcion->fresh(), $this->alguien());
        $aprobada = $this->formacion()->aprobar($inscripcion->fresh());

        $this->assertSame('aprobado', $aprobada->status);
        $this->assertNotNull($aprobada->certificate_code);

        $this->assertDatabaseHas('certifabs', [
            'user_id' => $inscripcion->user_id,
            'level'   => 'kilo',
        ]);
    }

    /** Un curso sin práctica no la exige: si no, no se podría aprobar nunca. */
    public function test_un_curso_sin_practica_aprueba_con_el_examen(): void
    {
        $this->curso->update(['requires_practical' => false]);

        $inscripcion = $this->inscribir();
        $this->formacion()->calificarExamen($inscripcion, $this->respuestas());

        $this->assertSame('aprobado', $this->formacion()->aprobar($inscripcion->fresh())->status);
    }

    /** Y quien no ha terminado sabe qué le falta, en una frase. */
    public function test_dice_que_falta(): void
    {
        $inscripcion = $this->inscribir();

        $this->assertStringContainsString('examen', $inscripcion->queFaltaParaAprobar());

        $this->formacion()->calificarExamen($inscripcion, $this->respuestas());

        $this->assertStringContainsString('presencial', $inscripcion->fresh()->queFaltaParaAprobar());
    }
}
