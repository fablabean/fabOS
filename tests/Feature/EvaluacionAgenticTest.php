<?php

namespace Tests\Feature;

use App\Models\EvaluacionBootcamp;
use App\Models\ParticipanteEnVivo;
use App\Support\EvaluacionAgentic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluacionAgenticTest extends TestCase
{
    use RefreshDatabase;

    public function test_son_diez_preguntas_con_cuatro_opciones_y_una_correcta(): void
    {
        $preguntas = EvaluacionAgentic::preguntas();
        $this->assertCount(10, $preguntas);
        foreach ($preguntas as $p) {
            $this->assertSame(['a', 'b', 'c', 'd'], array_keys($p['opciones']));
            $this->assertArrayHasKey($p['correcta'], $p['opciones']);
            $this->assertContains($p['dia'], [1, 2, 3]);
        }
    }

    public function test_la_pagina_es_libre_y_no_trae_la_clave(): void
    {
        $r = $this->get('/evaluacion/agentic')->assertOk()
            ->assertSee('Pregunta 10 de 10', false)
            ->assertSee(EvaluacionAgentic::preguntas()[0]['pregunta'], false);

        // Ni la explicación ni la marca de la correcta llegan al navegador.
        $r->assertDontSee(EvaluacionAgentic::preguntas()[0]['explicacion'], false);
        foreach (EvaluacionAgentic::preguntas() as $p) {
            $r->assertDontSee($p['explicacion'], false);
        }
    }

    public function test_califica_en_el_servidor_y_guarda_el_resultado(): void
    {
        $p = ParticipanteEnVivo::nuevo('agentic', 'agentic-dia-3', 'Ana');
        $correctas = array_column(EvaluacionAgentic::preguntas(), 'correcta');
        $respuestas = $correctas;
        $respuestas[0] = $correctas[0] === 'a' ? 'b' : 'a'; // una mal

        $this->postJson('/evaluacion/agentic', ['nombre' => '  Ana   Pérez ', 'codigo' => strtolower($p->codigo), 'respuestas' => $respuestas])
            ->assertOk()
            ->assertJsonPath('aciertos', 9)
            ->assertJsonPath('total', 10)
            ->assertJsonPath('nota', '4,5')
            ->assertJsonPath('detalle.0.bien', false)
            ->assertJsonPath('detalle.0.correcta', $correctas[0]);

        $e = EvaluacionBootcamp::sole();
        $this->assertSame('Ana Pérez', $e->nombre);
        $this->assertSame($p->codigo, $e->codigo);
        $this->assertSame(9, $e->aciertos);
    }

    public function test_un_id_que_no_existe_no_se_guarda_y_sin_responder_cuenta_como_error(): void
    {
        $this->postJson('/evaluacion/agentic', ['nombre' => 'Luis', 'codigo' => 'ZZZZ', 'respuestas' => ['a']])
            ->assertOk()->assertJsonPath('codigo', null);

        $this->assertNull(EvaluacionBootcamp::sole()->codigo);
        $this->assertCount(10, EvaluacionBootcamp::sole()->respuestas);
    }

    public function test_pide_el_nombre(): void
    {
        $this->postJson('/evaluacion/agentic', ['nombre' => '', 'respuestas' => ['a']])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->assertSame(0, EvaluacionBootcamp::count());
    }
}
