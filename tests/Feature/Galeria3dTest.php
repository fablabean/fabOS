<?php

namespace Tests\Feature;

use App\Services\Buscadores\MapaDelSitio;
use App\Support\Galeria3d;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La Galería 3D.
 *
 * Lo que se defiende: que esté en el menú justo entre Proyectos y Alianzas
 * sin mover nada más, que cada modelo de la configuración tenga sus archivos
 * y no esté roto, que la página y cada ficha carguen sin cuenta, que lo que no
 * existe dé 404 y que los buscadores la conozcan.
 */
class Galeria3dTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Galeria3d::olvidar();
    }

    public function test_el_menu_la_pone_entre_proyectos_y_alianzas(): void
    {
        $html = $this->get('/galeria-3d')->assertOk()->getContent();

        preg_match('/<div class="menu-enlaces"[^>]*>(.*?)<\/div>/s', $html, $menu);
        preg_match_all('/<a [^>]*>\s*(?:<svg.*?<\/svg>)?\s*([^<]+?)\s*<\/a>/s', $menu[1], $enlaces);

        $this->assertSame(
            ['Reservas', 'Formación', 'Proyectos', 'Galería 3D', 'Alianzas', 'Tienda', 'Preguntas'],
            array_slice(array_map('trim', $enlaces[1]), 0, 7),
        );
        $this->assertStringContainsString('href="' . route('galeria.index') . '"', $menu[1]);
    }

    public function test_el_menu_tambien_la_trae_en_las_demas_paginas(): void
    {
        $this->get('/alianzas')->assertOk()->assertSee('>Galería 3D</a>', false);
    }

    public function test_cada_modelo_configurado_tiene_sus_cuatro_archivos(): void
    {
        foreach (array_keys(config('galeria3d.modelos')) as $slug) {
            foreach (["{$slug}.glb", "{$slug}-ar.glb", "{$slug}.usdz", "{$slug}.webp"] as $archivo) {
                $this->assertFileExists(public_path('3d/modelos/' . $archivo), "Falta {$archivo}: ejecuta herramientas/galeria-3d/construir.py");
            }
        }
        $this->assertCount(count(config('galeria3d.modelos')), Galeria3d::modelos());
    }

    public function test_los_archivos_son_lo_que_dicen_ser(): void
    {
        foreach (Galeria3d::modelos() as $m) {
            foreach (['glb', 'glb_ar'] as $clave) {
                $ruta = public_path(parse_url($m['urls'][$clave], PHP_URL_PATH));
                $cabecera = file_get_contents($ruta, false, null, 0, 12);
                $this->assertSame('glTF', substr($cabecera, 0, 4), "{$ruta} no es un .glb");
                $this->assertSame(2, unpack('V', substr($cabecera, 4, 4))[1], 'glTF 2.0');
                $this->assertSame(filesize($ruta), unpack('V', substr($cabecera, 8, 4))[1], "{$ruta} está truncado");
            }
            // el de la web va comprimido; el de Android, sin extensiones obligatorias
            $this->assertStringContainsString('EXT_meshopt_compression', file_get_contents(public_path(parse_url($m['urls']['glb'], PHP_URL_PATH))));
            $this->assertStringNotContainsString('"extensionsRequired"', file_get_contents(public_path(parse_url($m['urls']['glb_ar'], PHP_URL_PATH))));

            $usdz = public_path(parse_url($m['urls']['usdz'], PHP_URL_PATH));
            $this->assertSame("PK\x03\x04", file_get_contents($usdz, false, null, 0, 4), "{$usdz} no es un .usdz");
        }
    }

    public function test_los_modelos_pesan_lo_razonable_para_un_telefono(): void
    {
        foreach (Galeria3d::modelos() as $m) {
            $this->assertLessThan(1024 * 1024, $m['bytes']['glb'], "{$m['slug']}.glb pasa de 1 MB");
            $this->assertLessThan(60000, $m['triangulos'], "{$m['slug']} tiene demasiados triángulos");
        }
    }

    public function test_la_pagina_carga_sin_cuenta_con_el_visor_y_la_coleccion(): void
    {
        $r = $this->get('/galeria-3d')->assertOk();

        $r->assertSee('id="visor"', false)
            ->assertSee('type="importmap"', false)
            ->assertSee('3d/visor.js', false)
            ->assertSee('id="galeria-datos"', false);

        foreach (Galeria3d::modelos() as $m) {
            $r->assertSee(route('galeria.show', $m['slug']), false);
            $r->assertSee($m['nombre']);
        }
    }

    public function test_cada_modelo_tiene_su_ficha(): void
    {
        $m = Galeria3d::modelos()->first();

        $this->get(route('galeria.show', $m['slug']))
            ->assertOk()
            ->assertSee('<h1 id="titulo"', false)
            ->assertSee($m['nombre'])
            ->assertSee($m['urls']['usdz'], false);
    }

    public function test_solo_hay_filtro_de_todas_e_impresion_3d(): void
    {
        $r = $this->get(route('galeria.index'))->assertOk();

        $r->assertSee('class="chip" data-categoria=""', false)
            ->assertSee('class="chip" data-categoria="Impresión 3D"', false);
        foreach (['Corte láser', 'Fresado CNC', 'Electrónica', 'Diseño de producto'] as $sinBoton) {
            $r->assertDontSee('class="chip" data-categoria="' . $sinBoton . '"', false);
        }
    }

    public function test_la_galeria_es_solo_de_impresion_3d(): void
    {
        $modelos = Galeria3d::modelos();

        $this->assertGreaterThanOrEqual(10, $modelos->count());
        $this->assertSame(['impresion-3d'], $modelos->pluck('categoria')->unique()->values()->all());

        $r = $this->get(route('galeria.index'))->assertOk();
        foreach (['Pez articulado', 'Tornillo y tuerca M20', 'Así se imprime una pieza', 'Soporte para celular', 'Piezas de ajedrez', 'Litofanía de Bogotá'] as $nuevo) {
            $r->assertSee($nuevo);
        }
        foreach (['Caja con uniones dentadas', 'Taburete de ensamble sin tornillos', 'Placa electrónica de práctica'] as $retirado) {
            $r->assertDontSee($retirado);
        }
        // Al subir un modelo desde el panel solo se ofrece Impresión 3D.
        $this->assertSame(['impresion-3d' => 'Impresión 3D'], config('galeria3d.categorias'));
    }

    public function test_la_ficha_ofrece_step_stl_glb_y_fbx(): void
    {
        $m = Galeria3d::modelos()->first();

        $this->get(route('galeria.show', $m['slug']))
            ->assertOk()
            ->assertSeeInOrder(['data-formato="step"', 'data-formato="stl"', 'id="descarga-glb"', 'data-formato="fbx"'], false)
            ->assertSee('download="' . $m['slug'] . '.glb"', false)
            ->assertDontSee('Descargar .usdz')
            ->assertDontSee('id="descarga-usdz"', false);

        // El módulo que genera STEP, STL y FBX está donde el visor lo busca.
        $this->assertFileExists(public_path('3d/exportar.js'));
    }

    public function test_lo_que_no_existe_da_404(): void
    {
        $this->get('/galeria-3d/no-existe')->assertNotFound();
        $this->get('/galeria-3d/no-existe/qr.svg')->assertNotFound();
    }

    public function test_el_qr_lleva_al_modelo_en_modo_realidad_aumentada(): void
    {
        $slug = Galeria3d::modelos()->keys()->first();

        $this->get(route('galeria.qr', $slug))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertSee('<svg', false);
    }

    public function test_un_modelo_sin_archivos_no_se_ensena(): void
    {
        config(['galeria3d.modelos.fantasma' => ['nombre' => 'Fantasma', 'categoria' => 'diseno']]);
        Galeria3d::olvidar();

        $this->assertNull(Galeria3d::modelo('fantasma'));
        $this->get('/galeria-3d/fantasma')->assertNotFound();
    }

    public function test_los_buscadores_la_conocen(): void
    {
        $urls = app(MapaDelSitio::class)->paginas()->pluck('url');

        $this->assertContains(route('galeria.index'), $urls);
        foreach (Galeria3d::modelos() as $m) {
            $this->assertContains(route('galeria.show', $m['slug']), $urls);
        }
    }
}
