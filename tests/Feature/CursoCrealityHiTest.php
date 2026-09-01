<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\RiskFamily;
use Database\Seeders\CursoCrealityHiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El curso kilo de la Creality Hi (§9, §10).
 *
 * Es el primero de la escalera que habilita a usar una máquina **sin nadie al
 * lado**, así que su contenido no es divulgación: es lo que sostiene el
 * certifab. Por eso vive en el repositorio y tiene pruebas — no para revisar la
 * redacción, sino para que no se publique roto: sin familia de riesgo no
 * habilita nada, y una pregunta cuya respuesta correcta apunte fuera de sus
 * opciones no se puede acertar nunca.
 */
class CursoCrealityHiTest extends TestCase
{
    use RefreshDatabase;

    private function sembrar(): Course
    {
        Mail::fake();

        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'creality-hi', 'name' => 'Creality Hi',
            'required_course_level' => 'kilo',
        ]);

        $this->seed(CursoCrealityHiSeeder::class);

        return Course::where('slug', 'kilo-creality-hi')->firstOrFail();
    }

    public function test_el_curso_queda_montado_de_punta_a_punta(): void
    {
        $curso = $this->sembrar();

        $this->assertSame('kilo', $curso->level);
        $this->assertTrue($curso->requires_practical, 'Habilita a operar sola: exige práctica.');
        $this->assertSame(7, $curso->lessons()->count());
        $this->assertSame(10, $curso->questions()->count());

        // Una edición sin fechas: la teoría se lee cuando se pueda.
        $edicion = CourseEdition::where('code', 'HI-CONTINUA')->firstOrFail();
        $this->assertTrue($edicion->is_self_paced);
        $this->assertNull($edicion->starts_on);
    }

    /**
     * Sin familia de riesgo, aprobar no habilita nada.
     *
     * Es el enganche que convierte un curso en un certifab. Sin él, alguien
     * estudia, se examina, aprueba… y sigue sin poder reservar la máquina.
     */
    public function test_esta_enganchado_a_la_familia_de_riesgo(): void
    {
        $curso = $this->sembrar();

        $this->assertTrue(
            $curso->riskFamilies->contains('slug', 'creality-hi'),
            'El curso tiene que habilitar la familia de las Creality Hi.',
        );
    }

    /**
     * Cada pregunta se puede acertar, y su respuesta está entre las opciones.
     *
     * Un índice fuera de rango daría una pregunta imposible: se falla siempre y
     * nadie llega al 80 % por mucho que se sepa la teoría.
     */
    public function test_todas_las_preguntas_se_pueden_acertar(): void
    {
        foreach ($this->sembrar()->questions as $p) {
            $this->assertGreaterThanOrEqual(2, count($p->options), $p->prompt);
            $this->assertArrayHasKey($p->correct, $p->options, 'Respuesta fuera de rango: ' . $p->prompt);
            $this->assertNotEmpty($p->explanation, 'Sin explicación: ' . $p->prompt);
        }
    }

    /** Y volver a sembrarlo no duplica nada: se corrige y se vuelve a cargar. */
    public function test_sembrarlo_dos_veces_no_duplica(): void
    {
        $this->sembrar();
        $this->seed(CursoCrealityHiSeeder::class);

        $curso = Course::where('slug', 'kilo-creality-hi')->firstOrFail();

        $this->assertSame(1, Course::where('slug', 'kilo-creality-hi')->count());
        $this->assertSame(7, $curso->lessons()->count());
        $this->assertSame(10, $curso->questions()->count());
    }
}
