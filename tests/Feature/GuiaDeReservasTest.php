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
            && $req['max_tokens'] <= 600 && $req['thinking']['type'] === 'disabled');
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

    // -------------------------------------------------- lo que nos preguntan

    /**
     * Cada consulta queda anotada con lo que escribieron y lo que se les dijo.
     *
     * Es lo más valioso que produce la guía: la gente dice con sus palabras
     * qué quiere hacer -no lo que el catálogo le ofrece- y eso dice qué cursos
     * faltan y qué máquina nadie encuentra. Se perdía al cerrar la pestaña.
     */
    public function test_la_consulta_queda_anotada_con_su_sugerencia(): void
    {
        $this->responde('asesoria', 'Nunca has usado la láser.');

        app(GuiaDeReservas::class)->recomendar('Quiero hacer un trofeo en acrilico');

        $anotada = \App\Models\ConsultaDeGuia::firstOrFail();

        $this->assertSame('Quiero hacer un trofeo en acrilico', $anotada->texto);
        $this->assertSame('asesoria', $anotada->camino);
        $this->assertStringContainsString('láser', $anotada->porque);
        $this->assertFalse($anotada->de_memoria);
        $this->assertNull($anotada->user_id, 'quien escribe sin cuenta queda sin identificar');
    }

    /** Con sesión, queda de quién fue: sirve para devolver la llamada. */
    public function test_con_sesion_se_sabe_quien_pregunto(): void
    {
        $this->responde('herramientas');
        $quien = \App\Models\User::create(['name' => 'Ana', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        $this->actingAs($quien);
        app(GuiaDeReservas::class)->recomendar('Me prestan un taladro para un montaje');

        $this->assertSame($quien->id, \App\Models\ConsultaDeGuia::firstOrFail()->user_id);
    }

    /**
     * La repetida también cuenta, y se marca.
     *
     * La pregunta se hizo igual; contarla solo cuando cuesta diría que nos
     * consultan menos de lo que nos consultan. Aparte, para que el gasto siga
     * cuadrando con las llamadas a la API.
     */
    public function test_la_pregunta_repetida_cuenta_y_se_marca(): void
    {
        $this->responde('espacio');
        $guia = app(GuiaDeReservas::class);

        $guia->recomendar('Necesito el taller para una clase');
        $guia->recomendar('necesito el TALLER para una clase');

        $this->assertSame(2, \App\Models\ConsultaDeGuia::count());
        Http::assertSentCount(1);
        $this->assertTrue(\App\Models\ConsultaDeGuia::latest('id')->first()->de_memoria);
    }

    /** Lo que no va del laboratorio también se anota: dice qué se esperaba. */
    public function test_lo_ajeno_tambien_queda_anotado(): void
    {
        $this->responde('ninguno', 'Aquí solo orientamos sobre el laboratorio.');

        app(GuiaDeReservas::class)->recomendar('Cuéntame un chiste sobre programadores');

        $this->assertSame('ninguno', \App\Models\ConsultaDeGuia::firstOrFail()->camino);
    }

    /** Y si falla, no se anota nada: no hubo sugerencia que guardar. */
    public function test_un_fallo_no_deja_consulta(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'x']], 500)]);

        app(GuiaDeReservas::class)->recomendar('Quiero imprimir una pieza en 3D');

        $this->assertSame(0, \App\Models\ConsultaDeGuia::count());
    }

    public function test_el_panel_ensena_lo_que_preguntan(): void
    {
        $this->responde('asesoria', 'Nunca has usado la láser.');
        app(GuiaDeReservas::class)->recomendar('Quiero hacer un trofeo en acrilico');

        $jefa = \App\Models\User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $jefa->assignRole(\Spatie\Permission\Models\Role::findOrCreate(\App\Models\User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get(\App\Filament\Pages\GuiaDeReservas::getUrl())
            ->assertOk()
            ->assertSee('Lo que nos preguntan')
            ->assertSee('Quiero hacer un trofeo en acrilico')
            // El número va en negrita, así que el recuento se lee partido.
            ->assertSeeInOrder(['>1<', 'consulta en total'], false);
    }

    // ------------------------------------------------------ por qué se cayó

    /**
     * El laboratorio se entera de por qué dejó de funcionar.
     *
     * Pasó de verdad: la cuenta se quedó sin saldo y la caja siguió diciendo
     * «no pudimos leerlo ahora» durante un día entero. Eso es lo correcto para
     * quien escribe —nadie de fuera tiene que enterarse de cómo pagamos la
     * API—, pero dejaba al laboratorio sin más señal que un archivo de log.
     */
    public function test_sin_saldo_lo_dice_con_todas_las_letras(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.'],
        ], 400)]);

        $guia = app(GuiaDeReservas::class);

        $this->assertNull($guia->recomendar('Quiero cortar unas piezas en acrílico'));

        $fallo = $guia->ultimoFallo();

        $this->assertNotNull($fallo);
        $this->assertStringContainsString('sin saldo', $fallo['motivo']);
        $this->assertStringContainsString('Plans & Billing', $fallo['motivo']);
    }

    public function test_cada_error_se_cuenta_en_castellano(): void
    {
        // Un solo doble, que cambia de respuesta: registrar `Http::fake` dos
        // veces no lo reemplaza, lo suma, y gana la regla que se puso primero.
        $estado = 200;
        Http::fake(function () use (&$estado) {
            return Http::response(['error' => ['type' => 'x']], $estado);
        });

        $casos = [
            [401, 'La clave de la API no vale'],
            [404, 'El modelo configurado no existe'],
            [429, 'Demasiadas preguntas seguidas'],
            [529, 'sobrecargada'],
        ];

        $guia = app(GuiaDeReservas::class);

        foreach ($casos as $i => [$codigo, $esperado]) {
            $estado = $codigo;

            // Texto distinto cada vez: la misma pregunta se contesta de la
            // memoria y no llegaría a la API.
            $guia->recomendar('Necesito un taladro para el montaje ' . $i);

            $this->assertStringContainsString($esperado, $guia->ultimoFallo()['motivo'], 'error ' . $codigo);
        }
    }

    /** Y cuando vuelve a funcionar, el aviso se va solo. */
    public function test_al_volver_a_responder_el_aviso_desaparece(): void
    {
        $estado = 500;
        $cuerpo = ['error' => ['type' => 'x']];

        Http::fake(function () use (&$estado, &$cuerpo) {
            return Http::response($cuerpo, $estado);
        });

        $guia = app(GuiaDeReservas::class);
        $guia->recomendar('Necesito el taller para una clase');

        $this->assertNotNull($guia->ultimoFallo());

        $estado = 200;
        $cuerpo = [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['camino' => 'espacio', 'porque' => 'Es una sala.'])]],
        ];

        $guia->recomendar('Necesito el taller para una clase de quince personas');

        $this->assertNull($guia->ultimoFallo());
    }

    public function test_el_panel_dice_por_que_se_cayo(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'Your credit balance is too low to access the Anthropic API.'],
        ], 400)]);

        app(GuiaDeReservas::class)->recomendar('Quiero cortar unas piezas en acrílico');

        $jefa = \App\Models\User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $jefa->assignRole(\Spatie\Permission\Models\Role::findOrCreate(\App\Models\User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get(\App\Filament\Pages\GuiaDeReservas::getUrl())
            ->assertOk()
            ->assertSee('El último intento falló')
            ->assertSee('sin saldo');
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
    public function test_la_respuesta_resalta_la_tarjeta_del_camino(): void
    {
        $this->responde('herramientas', 'Necesitas herramientas sueltas.');
        $this->post(route('publico.reservas.guia'), ['necesidad' => 'Me prestan un taladro para un montaje']);

        // Herramientas y espacio comparten tarjeta, y solo esa lleva la marca.
        $this->get('/reservas')
            ->assertOk()
            ->assertSee('class="camino recomendado" id="camino-espacio"', false)
            ->assertSee('class="camino " id="camino-asesoria"', false);
    }

    public function test_la_caja_esta_tambien_en_la_portada_y_responde_en_reservas(): void
    {
        $this->responde('asesoria');

        $this->get('/')->assertOk()->assertSee('Escribe qué necesitas');

        $this->post(route('publico.reservas.guia'), ['necesidad' => 'Nunca he usado la láser y quiero cortar algo'])
            ->assertRedirect(route('publico.reservas'));
    }

    public function test_el_banner_solo_sale_con_imagen(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->get('/reservas')->assertOk()->assertDontSee('class="mapa"', false);

        \Illuminate\Support\Facades\Storage::disk('public')->put('guia/mapa.png', 'png');
        \App\Models\Setting::put(\App\Support\Settings::GUIA_IMAGEN, 'guia/mapa.png', 'comunicaciones');
        \App\Models\Setting::put(\App\Support\Settings::GUIA_TEXTO, 'Mira el mapa y elige.', 'comunicaciones');

        $this->get('/reservas')
            ->assertOk()
            ->assertSee('class="mapa"', false)
            ->assertSee('storage/guia/mapa.png', false)
            ->assertSee('Mira el mapa y elige.');
    }
}
