<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tres arreglos de la guía, de los que se notan al usarla (§10).
 *
 * La respuesta nace a media página y el navegador devolvía a quien preguntó
 * arriba del todo: escribías, pulsabas, la página parpadeaba y volvía a verse
 * igual. El botón no cambiaba mientras la IA pensaba —unos segundos— así que
 * parecía que no había funcionado, y volver a pulsarlo costaba otra llamada.
 * Y el pie decía «las tarjetas de arriba» cuando están abajo.
 */
class LaGuiaRespondeDondeSePreguntoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['fabos.ia.activa' => true, 'fabos.ia.clave' => 'prueba', 'fabos.ia.max_por_dia' => 50]);
    }

    private function contesta(string $camino = 'asesoria'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode([
                'camino' => $camino, 'porque' => 'Por esto y lo otro.',
            ])]],
        ])]);
    }

    /** Se vuelve a la guía, no al principio de la página. */
    public function test_la_respuesta_deja_a_la_vista_la_guia(): void
    {
        $this->contesta();

        $this->post(route('publico.reservas.guia'), ['necesidad' => 'Necesito hacer un modelo 3D'])
            ->assertRedirect(route('publico.reservas') . '#guia');
    }

    /** Y cuando no se pudo responder, también: el aviso está ahí. */
    public function test_el_aviso_de_error_tambien_se_deja_a_la_vista(): void
    {
        config(['fabos.ia.activa' => false]);

        $this->post(route('publico.reservas.guia'), ['necesidad' => 'Necesito hacer un modelo 3D'])
            ->assertRedirect(route('publico.reservas') . '#guia')
            ->assertSessionHasErrors('necesidad');
    }

    /** El salto no deja la caja debajo de la barra fija. */
    public function test_el_salto_deja_sitio_para_la_barra(): void
    {
        $this->get('/reservas')->assertOk()->assertSee('.guia{scroll-margin-top', false);
    }

    /**
     * El botón avisa de que está pensando.
     *
     * Con `aria-busy` y no `disabled`: un botón deshabilitado se cae del
     * orden de tabulación y quien navega con teclado pierde el sitio justo
     * cuando está esperando.
     */
    public function test_el_boton_se_queda_pensando(): void
    {
        $this->get('/reservas')
            ->assertOk()
            ->assertSee('class="btn decirme"', false)
            ->assertSee("aria-busy', 'true'", false)
            ->assertSee('Pensando…', false)
            ->assertSee('guia-gira', false);
    }

    /** Las tarjetas están debajo, y el pie ya no dice lo contrario. */
    public function test_el_pie_manda_hacia_abajo(): void
    {
        $this->contesta();

        $this->followingRedirects()
            ->post(route('publico.reservas.guia'), ['necesidad' => 'Necesito hacer un modelo 3D'])
            ->assertOk()
            ->assertSee('las tarjetas de abajo dicen qué hace cada camino')
            ->assertDontSee('las tarjetas de arriba');
    }
}
