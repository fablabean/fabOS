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
     * Tienen su filtro, «Cómo funciona», y los de área solo salen si hay
     * preguntas en esa área; el paginador y el botón, con los estilos del sitio.
     */
    public function test_el_filtro_como_funciona_y_el_paginador(): void
    {
        $lab = User::create(['name' => 'Fablab Master', 'email' => 'os@fablabean.com', 'status' => 'activo']);
        $this->sembrar();

        \App\Models\Area::create(['slug' => 'vacia', 'name' => 'Área sin preguntas']);
        Question::create(['user_id' => $lab->id, 'title' => 'Una de otra cosa', 'body' => 'x']);

        $this->get(route('preguntas.index', ['tema' => 'frecuentes']))
            ->assertOk()
            ->assertSee('Cómo funciona')
            ->assertDontSee('Una de otra cosa')
            ->assertDontSee('Área sin preguntas')
            ->assertSee('Siguientes →')
            ->assertDontSee('Previous')
            ->assertSee('class="btn"', false);
    }

    public function test_correrla_dos_veces_no_duplica(): void
    {
        User::create(['name' => 'Fablab Master', 'email' => 'os@fablabean.com', 'status' => 'activo']);

        $this->sembrar();
        $this->sembrar();

        $this->assertSame(31, Question::count());
    }
}
