<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Las preguntas frecuentes sobre cómo funciona el laboratorio (§10). */
class PreguntasFrecuentesTest extends TestCase
{
    use RefreshDatabase;

    private function sembrar(): void
    {
        foreach (['2026_09_26_150000_preguntas_frecuentes_de_fabos', '2026_09_26_170000_mas_preguntas_frecuentes'] as $archivo) {
            (require database_path("migrations/{$archivo}.php"))->up();
        }

        // En una base nueva la marca la pone la migración que corre después.
        Question::whereIn('user_id', User::where('email', 'os@fablabean.com')->select('id'))
            ->update(['frecuente' => true]);
    }

    public function test_se_publican_respondidas_por_la_cuenta_del_laboratorio(): void
    {
        $lab = User::create(['name' => 'Fablab Master', 'email' => 'os@fablabean.com', 'status' => 'activo']);

        $this->sembrar();

        $this->assertSame(31, Question::where('user_id', $lab->id)->where('status', 'respondida')->count());
        $this->assertSame(31, Question::whereHas('respuestasPublicadas')->count());

        $this->get(route('preguntas.index'))->assertOk()->assertSee('¿Cómo reservo una máquina?');
        $this->get(route('preguntas.show', 'que-pasa-si-llego-tarde'))->assertOk()->assertSee('20 minutos');
        $this->get(route('preguntas.show', 'como-reservo-un-recorrido'))->assertOk()->assertSee('grupos de 15');
    }

    /**
     * Van en General (y la de horas incluidas en Impresión 3D), y cada área
     * sale como filtro solo si tiene preguntas; el paginador y el botón, con
     * los estilos del sitio.
     */
    public function test_se_filtran_por_area_y_el_paginador(): void
    {
        \App\Models\Area::create(['slug' => 'general', 'name' => 'General']);
        \App\Models\Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
        $vacia = \App\Models\Area::create(['slug' => 'vacia', 'name' => 'Área sin preguntas']);

        User::create(['name' => 'Fablab Master', 'email' => 'os@fablabean.com', 'status' => 'activo']);
        $this->sembrar();
        (require database_path('migrations/2026_09_26_190000_preguntas_frecuentes_con_area.php'))->up();

        $general = \App\Models\Area::where('slug', 'general')->value('id');

        $this->assertSame(30, Question::where('area_id', $general)->count());
        $this->assertSame('impresion-3d', Question::where('slug', 'que-son-las-horas-incluidas')->first()->area->slug);

        $this->get(route('preguntas.index'))
            ->assertOk()
            ->assertSee('General')
            ->assertSee('Impresión 3D')
            ->assertDontSee('Área sin preguntas')
            ->assertSee('Siguientes →')
            ->assertDontSee('Previous')
            ->assertSee('class="btn"', false);

        $this->get(route('preguntas.index', ['area' => $general]))
            ->assertOk()
            ->assertSee('¿Cómo reservo una máquina?')
            ->assertDontSee('¿Qué son las horas incluidas?');
    }

    public function test_correrla_dos_veces_no_duplica(): void
    {
        User::create(['name' => 'Fablab Master', 'email' => 'os@fablabean.com', 'status' => 'activo']);

        $this->sembrar();
        $this->sembrar();

        $this->assertSame(31, Question::count());
    }
}
