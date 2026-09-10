<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\RiskFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El certificado del curso y la habilitación, enlazados en Mi cuenta (§9).
 *
 * Eran dos códigos sin relación a la vista. Ahora el curso dice qué habilitó
 * y la habilitación dice de qué curso salió.
 */
class HabilitacionYCursoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_curso_dice_que_habilito_y_la_habilitacion_de_donde_salio(): void
    {
        $area = Area::create(['slug' => 'impresion', 'name' => 'Impresión 3D']);
        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'creality-hi', 'name' => 'Creality Hi',
            'required_course_level' => 'kilo', 'requires_companion' => false,
        ]);

        $curso = Course::create([
            'slug' => 'creality-hi', 'name' => 'kilo · Creality Hi', 'level' => 'kilo',
            'hours' => 4, 'is_active' => true, 'is_public' => true,
        ]);
        $curso->riskFamilies()->attach($familia->id);

        $edicion = CourseEdition::create([
            'course_id' => $curso->id, 'code' => 'HI-CONTINUA', 'capacity' => 20, 'status' => 'abierta',
        ]);

        $quien = User::factory()->create(['status' => 'activo']);
        $master = User::factory()->create(['name' => 'Fablab Master', 'status' => 'activo']);

        Enrollment::create([
            'course_edition_id' => $edicion->id, 'user_id' => $quien->id, 'status' => 'aprobado',
            'certificate_code' => 'CYQV885SNE', 'completed_at' => now(), 'enrolled_at' => now(),
        ]);

        $certifab = Certifab::create([
            'user_id' => $quien->id, 'risk_family_id' => $familia->id, 'level' => 'kilo',
            'granted_by' => $master->id, 'granted_via' => 'curso', 'granted_at' => now(),
        ]);

        $this->assertSame('HI-CONTINUA', $certifab->vieneDe()?->edition?->code);

        // El curso dice que habilito; la habilitacion no repite el curso.
        $this->actingAs($quien)->get(route('home'))
            ->assertOk()
            ->assertSee('Te habilitó: Creality Hi')
            ->assertDontSee('por el curso')
            ->assertSee('CYQV885SNE')
            ->assertSee($certifab->public_code);
    }

    /** Una habilitación reconocida a mano no inventa un curso. */
    public function test_la_habilitacion_sin_curso_dice_por_donde_llego(): void
    {
        $area = Area::create(['slug' => 'laser', 'name' => 'Láser']);
        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'laser', 'name' => 'Corte láser',
            'required_course_level' => 'kilo', 'requires_companion' => false,
        ]);
        $quien = User::factory()->create(['status' => 'activo']);

        $certifab = Certifab::create([
            'user_id' => $quien->id, 'risk_family_id' => $familia->id, 'level' => 'kilo',
            'granted_via' => 'experiencia', 'granted_at' => now(),
        ]);

        $this->assertNull($certifab->vieneDe());

        $this->actingAs($quien)->get(route('home'))
            ->assertOk()
            ->assertSee('Por experiencia reconocida')
            ->assertDontSee('por el curso');
    }
}
