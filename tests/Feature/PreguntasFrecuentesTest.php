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

    public function test_correrla_dos_veces_no_duplica(): void
    {
        User::create(['name' => 'Fablab Master', 'email' => 'os@fablabean.com', 'status' => 'activo']);

        $this->sembrar();
        $this->sembrar();

        $this->assertSame(31, Question::count());
    }
}
