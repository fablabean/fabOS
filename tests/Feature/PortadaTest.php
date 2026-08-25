<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** El banner de la portada (§3, portal público). */
class PortadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_banner_rota_entre_todas_las_laminas(): void
    {
        $respuesta = $this->get(route('publico.home'))->assertOk();

        foreach (config('fabos.hero') as $lamina) {
            $respuesta->assertSee($lamina['rotulo']);
            $respuesta->assertSee($lamina['texto']);
            $respuesta->assertSee($lamina['imagen'], escape: false);
        }
    }

    public function test_la_primera_lamina_se_ve_sin_javascript(): void
    {
        // Si la rotación no arranca —bloqueo de scripts, error de red—, la
        // portada tiene que seguir diciendo qué es este sitio.
        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('class="lamina activa"', escape: false)
            ->assertSee('class="diapo activa"', escape: false);
    }

    public function test_las_ilustraciones_existen_de_verdad(): void
    {
        foreach (config('fabos.hero') as $lamina) {
            $this->assertFileExists(
                public_path($lamina['imagen']),
                "Falta la ilustración {$lamina['imagen']} del banner"
            );
        }
    }

    public function test_el_logo_del_sitio_esta_en_su_sitio(): void
    {
        $this->assertFileExists(public_path(config('fabos.lab.logo')));

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('marca-sitio', escape: false);
    }

    /**
     * La marca es una palabra, no dos.
     *
     * Con `display:flex` y `gap`, «fab» y <em>OS</em> eran DOS elementos y el
     * hueco se metia entre ellos: la marca se leia «fab OS».
     */
    public function test_la_marca_no_se_parte_en_dos(): void
    {
        foreach ([
            'resources/views/layouts/publico.blade.php',
            'resources/views/layouts/app.blade.php',
            'resources/views/layouts/shell.blade.php',
        ] as $vista) {
            $fuente = file_get_contents(base_path($vista));

            $this->assertStringContainsString(
                '<span class="palabra">fab<em>OS</em></span>',
                $fuente,
                "{$vista} deja «fab» y «OS» sueltos dentro de un contenedor flex, y el gap los separa.",
            );
        }
    }
}
