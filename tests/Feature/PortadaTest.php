<?php

namespace Tests\Feature;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** El banner de la portada (§3, portal público). */
class PortadaTest extends TestCase
{
    use RefreshDatabase;

    private function portada(): string
    {
        return $this->get(route('publico.home'))->assertOk()->getContent();
    }

    /** Deja en la tabla solo las láminas que interesan a la prueba. */
    private function soloEstas(array ...$laminas): void
    {
        Banner::query()->delete();

        foreach ($laminas as $i => $lamina) {
            Banner::create($lamina + [
                'position' => $i,
                'titulo'   => 'Lámina ' . $i,
            ]);
        }
    }

    public function test_el_banner_rota_entre_todas_las_laminas(): void
    {
        $respuesta = $this->get(route('publico.home'))->assertOk();

        foreach (Banner::paraLaPortada() as $lamina) {
            $respuesta->assertSee($lamina->rotuloVisible());

            if ($lamina->texto) {
                $respuesta->assertSee($lamina->texto);
            }
        }
    }

    public function test_la_primera_lamina_se_ve_sin_javascript(): void
    {
        // Si la rotación no arranca —bloqueo de scripts, error de red—, la
        // portada tiene que seguir diciendo qué es este sitio.
        $html = $this->portada();

        $this->assertMatchesRegularExpression('/class="lamina activa\b/', $html);
        $this->assertMatchesRegularExpression('/class="diapo [^"]*\bactiva\b/', $html);
    }

    /**
     * El editor manda sobre la configuración.
     *
     * Lo contrario —que la configuración pisara a lo editado— convertiría el
     * editor en un adorno, y nadie se enteraría hasta publicar algo y ver que
     * la portada sigue diciendo lo de siempre.
     */
    public function test_lo_que_se_edita_es_lo_que_se_ve(): void
    {
        $this->soloEstas([
            'titulo' => 'Nos vemos en *LIBERA*',
            'texto'  => 'Pasa por el stand y fabrica algo con nosotros.',
            'rotulo' => 'Estamos en la feria',
        ]);

        $html = $this->portada();

        $this->assertStringContainsString('Pasa por el stand y fabrica algo con nosotros.', $html);
        $this->assertStringNotContainsString('Aquí se fabrica lo que', $html);
    }

    /** Con la tabla vacía se enseñan las de fábrica: nunca una portada muda. */
    public function test_sin_laminas_propias_se_usan_las_de_fabrica(): void
    {
        Banner::query()->delete();

        $html = $this->portada();

        foreach (config('fabos.hero') as $lamina) {
            $this->assertStringContainsString($lamina['texto'], $html);
            $this->assertStringContainsString($lamina['imagen'], $html);
        }
    }

    /**
     * La vigencia es el motivo de que esto exista: lo que anuncia un evento se
     * apaga solo el día que el evento pasa.
     */
    public function test_una_lamina_apagada_o_fuera_de_fecha_no_sale(): void
    {
        $this->soloEstas(
            ['titulo' => 'La que se ve', 'texto' => 'Esta sí sale'],
            ['titulo' => 'Apagada', 'texto' => 'Apagada a mano', 'is_active' => false],
            ['titulo' => 'Caducada', 'texto' => 'La feria del semestre pasado',
                'ends_at' => Carbon::now()->subDay()],
            ['titulo' => 'Futura', 'texto' => 'La convocatoria del lunes',
                'starts_at' => Carbon::now()->addWeek()],
        );

        $html = $this->portada();

        $this->assertStringContainsString('Esta sí sale', $html);
        $this->assertStringNotContainsString('Apagada a mano', $html);
        $this->assertStringNotContainsString('La feria del semestre pasado', $html);
        $this->assertStringNotContainsString('La convocatoria del lunes', $html);
    }

    /**
     * El título se escribe en una caja de texto del panel: los asteriscos son
     * la única marca que se interpreta, y todo lo demás sale como letras.
     */
    public function test_el_titulo_resalta_con_asteriscos_y_escapa_lo_demas(): void
    {
        $this->soloEstas(['titulo' => 'Nos vemos en *LIBERA* <script>alert(1)</script>']);

        $html = $this->portada();

        $this->assertStringContainsString('<em>LIBERA</em>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** El video se acompaña de su cartel: es lo que se ve mientras carga. */
    public function test_el_video_de_fondo_va_con_su_cartel(): void
    {
        $this->soloEstas([
            'titulo'      => 'Con video',
            'fondo_tipo'  => 'video',
            'fondo_path'  => 'banners/prueba.mp4',
            'poster_path' => 'banners/prueba.webp',
        ]);

        $html = $this->portada();

        $this->assertStringContainsString('banners/prueba.mp4', $html);
        $this->assertStringContainsString('poster="' . asset('storage/banners/prueba.webp') . '"', $html);

        // Sin `autoplay`: lo arranca el script, y solo el de la lámina visible.
        $this->assertStringNotContainsString('autoplay', $html);
    }

    /** Sin botones propios salen los de siempre; con ellos, los suyos. */
    public function test_los_botones_de_la_lamina_pisan_a_los_de_siempre(): void
    {
        $this->soloEstas([
            'titulo'       => 'Con botón propio',
            'accion_texto' => 'Cómo llegar a la feria',
            'accion_url'   => 'https://libera.example.co',
        ]);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Cómo llegar a la feria')
            ->assertDontSee('Proponer un proyecto');
    }

    public function test_las_ilustraciones_de_fabrica_existen_de_verdad(): void
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
