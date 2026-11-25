<?php

namespace Tests\Feature;

use App\Http\Controllers\AsistenteAgenticController;
use App\Models\ParticipanteEnVivo;
use App\Models\Setting;
use App\Services\Ia\AccesoAlAsistente;
use App\Services\Ia\AsistenteDelBootcamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El asistente del Bootcamp AGENTIC: habla con el servidor de IA del FabLab,
 * entrega la respuesta por partes y no deja viajar una llave de API.
 * Solo entra quien tenga su ID del bootcamp, con su tiempo al día.
 */
class AsistenteAgenticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://pgx.test:11434']);
        $this->ana = ParticipanteEnVivo::nuevo('agentic', 'agentic-dia-2', 'Ana Ruiz');
        $this->withSession([AsistenteAgenticController::SESION => $this->ana->codigo]);
    }

    private ParticipanteEnVivo $ana;

    private function ollamaResponde(): void
    {
        Http::fake(['pgx.test:11434/api/chat' => Http::response(
            json_encode(['message' => ['content' => '¿Qué ']]) . "\n"
            . json_encode(['message' => ['content' => 'intentaste?']]) . "\n"
            . json_encode(['done' => true]) . "\n",
        )]);
    }

    public function test_la_pagina_abre_con_el_id_y_muestra_sus_instrucciones(): void
    {
        $this->get('/asistente/agentic')
            ->assertOk()
            ->assertSee('te ayuda a pensar', false)
            ->assertSee('No haces el trabajo por la persona')
            ->assertSee($this->ana->codigo)
            ->assertSee('Tienes 60 min hoy');
    }

    public function test_sin_id_no_hay_chat_y_se_entra_con_uno_valido(): void
    {
        $this->flushSession();
        $this->get('/asistente/agentic')->assertOk()->assertSee('Entra con tu')->assertDontSee('te ayuda a pensar', false);
        $this->postJson('/asistente/agentic/mensaje', ['mensajes' => [['role' => 'user', 'content' => 'hola']]])
            ->assertStatus(403)->assertJson(['sin_id' => true]);

        // Un ID inventado, o de otro curso, no entra.
        ParticipanteEnVivo::create(['codigo' => 'ZZZZ', 'grupo' => 'otro', 'sala' => 'x', 'nombre' => 'Otro']);
        $this->post('/asistente/agentic/entrar', ['codigo' => 'ZZZZ'])->assertSessionHas('error_id');
        $this->post('/asistente/agentic/entrar', ['codigo' => strtolower($this->ana->codigo)])
            ->assertRedirect('/asistente/agentic')
            ->assertSessionHas(AsistenteAgenticController::SESION, $this->ana->codigo);
    }

    public function test_cada_id_tiene_su_hora_al_dia_y_el_profesor_lo_apaga(): void
    {
        $this->ollamaResponde();
        $hola = ['mensajes' => [['role' => 'user', 'content' => 'hola']]];

        $this->postJson('/asistente/agentic/mensaje', $hola)->assertOk()->streamedContent();
        $acceso = app(AccesoAlAsistente::class);
        $this->assertSame(1, $acceso->hoy($this->ana->codigo)['mensajes']);
        $this->assertSame(60, $acceso->hoy($this->ana->codigo)['quedan']);

        // Pasó la hora: ese ID no sigue hoy; mañana sí.
        $this->travel(61)->minutes();
        $this->postJson('/asistente/agentic/mensaje', $hola)->assertStatus(403)->assertJson(['agotado' => true]);
        $this->get('/asistente/agentic')->assertSee('Se acabó tu hora de hoy');
        $acceso->reiniciarHoy($this->ana->codigo);
        $this->postJson('/asistente/agentic/mensaje', $hola)->assertOk();

        // Apagado, nadie lo usa.
        Setting::put(AccesoAlAsistente::ACTIVO, false, 'envivo');
        $this->postJson('/asistente/agentic/mensaje', $hola)->assertStatus(403)->assertJsonFragment(['error' => 'El asistente está apagado por ahora. Quien dicta el bootcamp lo vuelve a prender.']);
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
