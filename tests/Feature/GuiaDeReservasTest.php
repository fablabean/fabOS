<?php

namespace Tests\Feature;

use App\Services\Ia\GuiaDeReservas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La guía de reservas (§10): una pregunta, un camino.
 *
 * Lo que se defiende: que devuelva uno de los cuatro caminos con su enlace,
 * que lo ajeno al laboratorio sea «ninguno», que la respuesta venga siempre
 * por el esquema y no por prosa, que no se pague dos veces la misma pregunta,
 * y que apagada la IA la caja no exista.
 */
class GuiaDeReservasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['fabos.ia.activa' => true, 'fabos.ia.clave' => 'sk-prueba', 'fabos.ia.max_por_dia' => 50]);
    }

    private function responde(string $camino, string $porque = 'Porque sí.'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode(['camino' => $camino, 'porque' => $porque])]],
            ]),
        ]);
    }

    public function test_dice_el_camino_con_su_enlace_y_su_porque(): void
    {
        $this->responde('asesoria', 'Nunca has usado la láser: alguien del equipo te acompaña.');

        $r = app(GuiaDeReservas::class)->recomendar('Quiero hacer un trofeo en acrílico pero nunca he usado la láser');

        $this->assertSame('asesoria', $r['camino']);
        $this->assertSame('Asesoría', $r['titulo']);
        $this->assertSame(route('publico.reservas', ['modo' => 'asesoria']), $r['url']);
        $this->assertStringContainsString('te acompaña', $r['porque']);

        // Con el esquema fijo y lo escrito marcado como dato.
        Http::assertSent(fn ($req) => $req['output_config']['format']['type'] === 'json_schema'
            && str_contains($req['messages'][0]['content'], '<necesidad>')
            && $req['max_tokens'] <= 400);
    }

    public function test_lo_que_no_va_del_laboratorio_es_ninguno_sin_enlace(): void
    {
        $this->responde('ninguno', 'Aquí solo orientamos sobre cómo usar el laboratorio.');

        $r = app(GuiaDeReservas::class)->recomendar('Cuéntame un chiste sobre programadores');

        $this->assertSame('ninguno', $r['camino']);
        $this->assertNull($r['url']);
    }

    public function test_un_camino_que_no_existe_se_trata_como_ninguno(): void
    {
        // Aunque el modelo se saliera del esquema, aquí no hay quinto camino.
        $this->responde('chat');

        $this->assertSame('ninguno', app(GuiaDeReservas::class)->recomendar('Hola, ¿cómo estás hoy?')['camino']);
    }

    public function test_la_misma_pregunta_no_se_paga_dos_veces(): void
    {
        $this->responde('herramientas');
        $guia = app(GuiaDeReservas::class);

        $guia->recomendar('Necesito un taladro para un montaje');
        $guia->recomendar('  necesito un TALADRO para un montaje ');

        Http::assertSentCount(1);
        $this->assertSame(49, $guia->quedanHoy());
    }

    public function test_con_el_tope_diario_agotado_no_pregunta(): void
    {
        $this->responde('espacio');
        config(['fabos.ia.max_por_dia' => 0]);

        $this->assertNull(app(GuiaDeReservas::class)->recomendar('Necesito el taller para una clase'));
        Http::assertNothingSent();
    }

    public function test_si_la_api_falla_devuelve_nulo_y_no_rompe(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('caído', 500)]);

        $this->assertNull(app(GuiaDeReservas::class)->recomendar('Quiero imprimir una pieza en 3D'));
    }

    // ---------------------------------------------------------------- la página

    public function test_la_caja_esta_en_reservas_y_responde_en_la_misma_pagina(): void
    {
        $this->responde('proyecto', 'Quieres el resultado, no operar: se cotiza y te responden.');

        $this->get('/reservas')->assertOk()->assertSee('Escribe qué necesitas');

        $this->post(route('publico.reservas.guia'), ['necesidad' => 'Necesito 20 letreros en acrílico para mi empresa'])
            ->assertRedirect(route('publico.reservas'))
            ->assertSessionHas('guia');

        $this->get('/reservas')
            ->assertOk()
            ->assertSee('Fabricación y acompañamiento técnico →')
            ->assertSee('se cotiza y te responden')
            ->assertSee(route('proyectos.solicitar'), false);
    }

    public function test_con_dos_palabras_no_se_pregunta(): void
    {
        $this->responde('asesoria');

        $this->post(route('publico.reservas.guia'), ['necesidad' => 'hola'])->assertSessionHasErrors('necesidad');

        Http::assertNothingSent();
    }

    public function test_apagada_la_ia_la_caja_no_existe(): void
    {
        config(['fabos.ia.activa' => false]);

        $this->get('/reservas')->assertOk()->assertDontSee('Escribe qué necesitas');
    }
}
