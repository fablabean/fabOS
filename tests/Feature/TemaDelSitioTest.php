<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Claro, oscuro o el del sistema, también en el sitio (§3).
 *
 * El panel lo tenía y el sitio no: fuera sólo se seguía el modo del sistema
 * operativo, sin forma de contradecirlo. Quien trabaja de día en un equipo
 * configurado en oscuro —o al revés— no tenía nada que tocar.
 */
class TemaDelSitioTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_selector_esta_en_el_sitio_publico(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-tema="light"', false)
            ->assertSee('data-tema="dark"', false)
            ->assertSee('data-tema="system"', false);
    }

    /**
     * Y lo ve quien llega sin cuenta, que es quien más lo necesita.
     *
     * A esa persona el panel no va a resolvérselo nunca: no entra.
     */
    public function test_se_ve_sin_haber_entrado(): void
    {
        $this->assertGuest();

        $this->get('/')->assertOk()->assertSee('aria-label="Tema del sitio"', false);
    }

    /**
     * Elegir «claro» le gana a un sistema en oscuro.
     *
     * Es la pieza que hace que la opción sirva de algo. Sin el `:not`, la
     * consulta de medios gana igual y el botón de claro no hace nada en el
     * único equipo donde hace falta.
     */
    public function test_lo_elegido_le_gana_al_sistema(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(':root:not([data-theme="light"])', $html);
        $this->assertStringContainsString(':root[data-theme="dark"]', $html);
    }

    /**
     * El tema se aplica antes de pintar.
     *
     * Si el guion corriera al final, la página se pintaría con el tema del
     * sistema y cambiaría al de la persona un instante después: el destello
     * blanco al abrir de noche, que es lo que hace que esto se sienta roto.
     */
    public function test_el_tema_se_aplica_antes_de_pintar(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $guion = strpos($html, "localStorage.getItem('fabos-tema')");
        $cuerpo = strpos($html, '<body');

        $this->assertNotFalse($guion, 'el guion del tema no está en la página');
        $this->assertLessThan($cuerpo, $guion, 'el guion del tema tiene que ir en el <head>');
    }

    /** La marca sigue al tema elegido, no sólo al del sistema. */
    public function test_la_marca_sigue_al_tema_elegido(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/larga-oscura.png', 'oscura');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');

        $html = $this->get('/')->assertOk()->getContent();

        // Qué versión se ve lo dicen las variables del tema, no una consulta
        // de medios propia: así la elección manual se resuelve una sola vez.
        $this->assertStringContainsString('display:var(--marca-clara,flex)', $html);
        $this->assertStringContainsString('display:var(--marca-oscura,none)', $html);
        $this->assertStringContainsString('--marca-clara:none; --marca-oscura:flex', $html);
    }

    /**
     * Con la barra de color fijo, la marca no depende del tema.
     *
     * Ese color es el mismo para todo el mundo, así que la versión ya está
     * decidida. Si heredara las variables del tema, alguien con el sistema en
     * oscuro vería desaparecer la marca de una barra puesta clara a mano.
     */
    public function test_con_barra_fija_la_marca_no_depende_del_tema(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_BARRA, '#F6F6F2', 'comunicaciones');

        $this->get('/')
            ->assertOk()
            ->assertSee('class="larga fija"', false)
            ->assertDontSee('class="larga clara"', false);
    }
}
