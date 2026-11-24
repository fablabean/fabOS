<?php

namespace Tests\Feature;

use App\Services\Ia\AsistenteDelBootcamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El asistente del Bootcamp AGENTIC: habla con el servidor de IA del FabLab,
 * entrega la respuesta por partes y no deja viajar una llave de API.
 */
class AsistenteAgenticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://pgx.test:11434']);
    }

    private function ollamaResponde(): void
    {
        Http::fake(['pgx.test:11434/api/chat' => Http::response(
            json_encode(['message' => ['content' => '¿Qué ']]) . "\n"
            . json_encode(['message' => ['content' => 'intentaste?']]) . "\n"
            . json_encode(['done' => true]) . "\n",
        )]);
    }

    public function test_la_pagina_abre_sin_cuenta_y_muestra_sus_instrucciones(): void
    {
        $this->get('/asistente/agentic')
            ->assertOk()
            ->assertSee('te ayuda a pensar', false)
            ->assertSee('No haces el trabajo por la persona');
    }

    public function test_responde_por_partes_con_las_instrucciones_y_el_nivel(): void
    {
        $this->ollamaResponde();

        $r = $this->postJson('/asistente/agentic/mensaje', [
            'mensajes' => [['role' => 'user', 'content' => 'Hazme el programa completo']],
            'nivel' => 'inter',
        ]);

        $r->assertOk();
        $cuerpo = $r->streamedContent();
        $this->assertStringContainsString('"texto":"¿Qué "', $cuerpo);
        $this->assertStringContainsString('"texto":"intentaste?"', $cuerpo);
        $this->assertStringContainsString('"fin":true', $cuerpo);

        Http::assertSent(function (Request $q) {
            $m = $q['messages'];

            return $q['stream'] === true
                && $m[0]['role'] === 'system'
                && str_contains($m[0]['content'], 'No haces el trabajo por la persona')
                && str_contains($m[0]['content'], 'intermedio')
                && $m[1]['content'] === 'Hazme el programa completo';
        });
    }

    public function test_una_llave_pegada_no_llega_al_modelo(): void
    {
        $this->ollamaResponde();

        $this->postJson('/asistente/agentic/mensaje', [
            'mensajes' => [['role' => 'user', 'content' => 'mi llave es sk-ant-api03-abcdefghijklmnopqrstuvwxyz123456']],
        ])->streamedContent();

        Http::assertSent(fn (Request $q) => ! str_contains(json_encode($q['messages']), 'sk-ant-api03')
            && str_contains(json_encode($q['messages']), 'LLAVE TAPADA'));
        $this->assertTrue(AsistenteDelBootcamp::pareceLlave('AIzaSyA1234567890abcdefghijklmnopqrstu'));
    }

    public function test_el_ultimo_mensaje_tiene_que_ser_de_la_persona(): void
    {
        $this->postJson('/asistente/agentic/mensaje', [
            'mensajes' => [['role' => 'assistant', 'content' => 'hola']],
        ])->assertStatus(422);

        $this->postJson('/asistente/agentic/mensaje', ['mensajes' => []])->assertStatus(422);
    }

    public function test_si_el_servidor_falla_lo_dice_sin_romperse(): void
    {
        Http::fake(['pgx.test:11434/api/chat' => Http::response('caído', 500)]);

        $cuerpo = $this->postJson('/asistente/agentic/mensaje', [
            'mensajes' => [['role' => 'user', 'content' => 'hola']],
        ])->streamedContent();

        $this->assertStringContainsString('no respondió', $cuerpo);
    }
}
