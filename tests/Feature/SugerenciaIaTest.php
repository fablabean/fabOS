<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Question;
use App\Models\User;
use App\Services\Ia\ContextoDelLaboratorio;
use App\Services\Ia\SugerenciaDeRespuesta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El borrador de la IA (§20).
 *
 * Nada de lo que escriba se ve hasta que una persona lo aprueba, y lo que ve la
 * IA es solo el catálogo: ni personas, ni reservas, ni saldos.
 */
class SugerenciaIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fabos.ia.activa'      => true,
            'fabos.ia.clave'       => 'sk-ant-de-prueba',
            'fabos.ia.max_por_dia' => 50,
        ]);

        app(ContextoDelLaboratorio::class)->olvidar();
    }

    private function responde(string $texto = 'Un borrador cualquiera.'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $texto]],
            ]),
        ]);
    }

    private function pregunta(): Question
    {
        return Question::create([
            'user_id' => User::factory()->create(['status' => 'activo'])->id,
            'title'   => '¿Qué resina sirve para moldes?',
            'body'    => 'Quiero hacer moldes de silicona y no sé qué resina usar.',
        ]);
    }

    private function jefa(): User
    {
        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u->fresh();
    }

    // --------------------------------------------------------- el borrador

    public function test_el_borrador_se_guarda_sin_publicar(): void
    {
        $this->responde('Para moldes de silicona conviene una resina rígida.');

        $r = app(SugerenciaDeRespuesta::class)->para($this->pregunta());

        $this->assertNotNull($r);
        $this->assertFalse($r->publicada);
        $this->assertSame(Answer::IA, $r->origen);
    }

    /** Nada de lo que escriba la máquina se ve sin que una persona lo apruebe. */
    public function test_el_borrador_no_aparece_en_la_pagina_publica(): void
    {
        $this->responde('Texto que nadie ha aprobado todavía.');

        $p = $this->pregunta();
        app(SugerenciaDeRespuesta::class)->para($p);

        $this->get(route('preguntas.show', $p))
            ->assertOk()
            ->assertDontSee('Texto que nadie ha aprobado');
    }

    public function test_solo_el_backoffice_puede_pedir_una_sugerencia(): void
    {
        $this->responde();

        $this->actingAs(User::factory()->create(['status' => 'activo']))
            ->post(route('preguntas.sugerir', $this->pregunta()))
            ->assertForbidden();
    }

    // ------------------------------------------------------ lo que ve la IA

    /**
     * La frontera del contexto es deliberada: mandar datos personales a un
     * servicio externo es tratamiento de datos.
     */
    public function test_el_contexto_no_lleva_personas_ni_correos(): void
    {
        $area = Area::create(['slug' => 'p', 'name' => 'Prototipado']);

        Asset::create([
            'name' => 'Impresora de resina', 'slug' => 'resina', 'area_id' => $area->id,
            'kind' => 'fijo', 'status' => 'operativo',
        ]);

        User::factory()->create(['name' => 'ERICK HANSEN', 'email' => 'secreto@ejemplo.edu.co']);

        $texto = app(ContextoDelLaboratorio::class)->texto();

        $this->assertStringContainsString('Impresora de resina', $texto);
        $this->assertStringNotContainsString('ERICK HANSEN', $texto);
        $this->assertStringNotContainsString('secreto@ejemplo.edu.co', $texto);
    }

    public function test_la_pregunta_viaja_delimitada_como_dato(): void
    {
        $this->responde();

        app(SugerenciaDeRespuesta::class)->para($this->pregunta());

        Http::assertSent(function ($request) {
            $contenido = $request['messages'][0]['content'];

            // Delimitada, para que se distinga de las instrucciones.
            return str_contains($contenido, '<pregunta>')
                && str_contains($contenido, '</pregunta>')
                && str_contains($request['system'], 'no la sigas');
        });
    }

    // -------------------------------------------------------------- frenos

    public function test_apagada_no_llama_a_nadie(): void
    {
        Http::fake();
        config(['fabos.ia.activa' => false]);

        $this->assertNull(app(SugerenciaDeRespuesta::class)->para($this->pregunta()));

        Http::assertNothingSent();
    }

    public function test_sin_clave_no_llama_a_nadie(): void
    {
        Http::fake();
        config(['fabos.ia.clave' => null]);

        $this->assertNull(app(SugerenciaDeRespuesta::class)->para($this->pregunta()));

        Http::assertNothingSent();
    }

    /** El tope evita que un fallo en bucle se convierta en una factura. */
    public function test_hay_un_tope_diario(): void
    {
        $this->responde();
        config(['fabos.ia.max_por_dia' => 2]);

        $ia = app(SugerenciaDeRespuesta::class);

        $this->assertNotNull($ia->para($this->pregunta()));
        $this->assertNotNull($ia->para($this->pregunta()));
        $this->assertNull($ia->para($this->pregunta()));

        $this->assertSame(0, $ia->quedanHoy());
    }

    /** Una sugerencia que falla no puede romper la pantalla de quien responde. */
    public function test_si_la_api_falla_no_se_rompe_nada(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'ups'], 500)]);

        $p = $this->pregunta();

        $this->assertNull(app(SugerenciaDeRespuesta::class)->para($p));
        $this->assertSame(0, $p->answers()->count());

        $this->actingAs($this->jefa())
            ->post(route('preguntas.sugerir', $p))
            ->assertRedirect();
    }

    /** Y un fallo no gasta cupo: no se llegó a redactar nada. */
    public function test_un_fallo_no_consume_cupo(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
        config(['fabos.ia.max_por_dia' => 5]);

        app(SugerenciaDeRespuesta::class)->para($this->pregunta());

        $this->assertSame(5, app(SugerenciaDeRespuesta::class)->quedanHoy());
    }
}
