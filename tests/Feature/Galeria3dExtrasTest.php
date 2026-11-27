<?php

namespace Tests\Feature;

use App\Support\Galeria3d;
use App\Support\Galeria3dContadores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que se le sumó a la Galería 3D: me gusta, vistas y descargas; la
 * calculadora «Imprimir»; los filtros por tipo y material con orden; y
 * compartir.
 */
class Galeria3dExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function contar(string $slug, string $accion)
    {
        return $this->postJson(route('galeria.contar', $slug), ['accion' => $accion]);
    }

    public function test_una_vista_cuenta_una_vez_por_navegador(): void
    {
        $this->contar('cubo', 'vista')->assertOk()->assertJson(['vistas' => 1]);
        $this->contar('cubo', 'vista')->assertOk()->assertJson(['vistas' => 1]);
        $this->assertSame(1, Galeria3dContadores::de('cubo')['vistas']);

        // otro navegador (otra sesión) sí suma
        $this->flushSession();
        $this->contar('cubo', 'vista')->assertOk()->assertJson(['vistas' => 2]);
    }

    public function test_me_gusta_se_pone_y_se_quita_sin_bajar_de_cero(): void
    {
        $this->contar('pez', 'me-gusta')->assertOk()->assertJson(['me_gusta' => 1, 'meGusta' => true]);
        $this->contar('pez', 'me-gusta')->assertOk()->assertJson(['me_gusta' => 1]);   // tocar dos veces no suma dos
        $this->contar('pez', 'ya-no')->assertOk()->assertJson(['me_gusta' => 0, 'meGusta' => false]);
        $this->contar('pez', 'ya-no')->assertOk()->assertJson(['me_gusta' => 0]);
    }

    public function test_cada_descarga_suma(): void
    {
        $this->contar('tornillo', 'descarga');
        $this->contar('tornillo', 'descarga')->assertOk()->assertJson(['descargas' => 2]);
    }

    public function test_contar_rechaza_figuras_y_acciones_que_no_existen(): void
    {
        $this->contar('no-existe', 'vista')->assertNotFound();
        $this->contar('cubo', 'borrar')->assertUnprocessable();
        $this->assertSame([], Galeria3dContadores::todos());
    }

    public function test_la_pagina_muestra_las_cifras_y_recuerda_el_me_gusta(): void
    {
        $this->contar('cubo', 'me-gusta');
        $this->contar('cubo', 'descarga');

        $this->get(route('galeria.show', 'cubo'))
            ->assertOk()
            ->assertSee('id="me-gusta" aria-pressed="true"', false)
            ->assertSee('id="descargas-num">1<', false)
            ->assertSee('data-me-gusta="1"', false);
    }

    public function test_la_ficha_trae_la_calculadora_de_impresion(): void
    {
        $this->get(route('galeria.show', 'trofeo'))
            ->assertOk()
            ->assertSee('id="panel-imprimir"', false)
            ->assertSee('id="calc-material"', false)
            ->assertSee('Resina (impresora de resina)')
            ->assertSee('"materialImpresion":"resina"', false);
    }

    public function test_el_costo_solo_se_muestra_si_el_laboratorio_fijo_el_precio(): void
    {
        $this->get(route('galeria.show', 'cubo'))->assertSee('"precio_gramo":null', false);
        config(['galeria3d.impresion.materiales.pla.precio_gramo' => 250]);
        $this->get(route('galeria.show', 'cubo'))->assertSee('"precio_gramo":250', false);
    }

    public function test_etiquetas_de_tipo_y_material_sacadas_de_cada_modelo(): void
    {
        $m = Galeria3d::modelo('trofeo');
        $this->assertArrayHasKey('resina', Galeria3d::etiquetas($m));
        $this->assertArrayHasKey('vaso', Galeria3d::etiquetas(Galeria3d::modelo('jarron')));
        $this->assertArrayHasKey('petg', Galeria3d::etiquetas(Galeria3d::modelo('tornillo')));
        $this->assertArrayHasKey('animada', Galeria3d::etiquetas(Galeria3d::modelo('pez')));

        // Los filtros de la colección son solo «Todas» e «Impresión 3D»; las
        // etiquetas sirven para la búsqueda y para la calculadora.
        $this->get(route('galeria.index'))
            ->assertOk()
            ->assertDontSee('data-filtro=', false)
            ->assertSee('id="orden"', false)
            ->assertSee('<option value="populares">Más populares</option>', false);
    }

    public function test_compartir_usa_la_direccion_publica(): void
    {
        config(['galeria3d.url_publica' => 'https://fablab.ean.edu.co']);
        $this->get(route('galeria.show', 'cubo'))
            ->assertOk()
            ->assertSee('id="compartir-whatsapp"', false)
            ->assertSee('"compartir":"https:\/\/fablab.ean.edu.co\/galeria-3d\/cubo"', false);
    }

    /** «¿Cómo se imprime?»: el botón del visor y su panel de capas. */
    public function test_el_visor_trae_capa_por_capa(): void
    {
        $this->get(route('galeria.show', 'jarron'))
            ->assertOk()
            ->assertSee('data-accion="capas"', false)
            ->assertSee('id="capas" role="group" aria-label="Capa por capa" hidden', false)
            ->assertSee('id="capas-barra"', false);
        $js = file_get_contents(public_path('3d/visor.js'));
        $this->assertStringContainsString('class CapaPorCapa', $js);
        $this->assertStringContainsString('clipShadows', $js);
    }

    /** Comparar el tamaño: el botón del visor y los cinco objetos conocidos. */
    public function test_el_visor_trae_comparar_el_tamano(): void
    {
        $r = $this->get(route('galeria.show', 'cubo'))->assertOk()
            ->assertSee('data-accion="comparar"', false)
            ->assertSee('id="comparar" role="group" aria-label="Comparar el tamaño" hidden', false);
        foreach (['moneda', 'tarjeta', 'celular', 'lata', 'persona'] as $objeto) {
            $r->assertSee('data-objeto="' . $objeto . '"', false);
        }
        $js = file_get_contents(public_path('3d/visor.js'));
        $this->assertStringContainsString('class Comparador', $js);
        $this->assertStringContainsString('function objetoParaComparar', $js);
    }

    public function test_por_el_enlace_del_celular_tambien_se_cuenta(): void
    {
        $this->postJson('https://abc-def.trycloudflare.com/galeria-3d/cubo/contar', ['accion' => 'me-gusta'])
            ->assertOk()->assertJson(['me_gusta' => 1]);
    }
}
