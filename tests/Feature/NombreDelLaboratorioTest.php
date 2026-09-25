<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\LabSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El nombre, escrito como lo dice la marca (§3, §19).
 *
 * «Fablab Ean» en el sitio y FABLAB EAN en el logo: la misma marca contada de
 * dos formas en la misma pantalla. El logo manda —es lo que está dibujado— y
 * el texto se alinea con él.
 */
class NombreDelLaboratorioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El nombre se pone aquí y no se hereda de la migración.
     *
     * La migración lo deja puesto en la instalación de verdad, pero en las
     * pruebas ese dato vive fuera de la transacción de cada test y cualquier
     * otra clase que toque `lab.name` —la de instalación lo hace— cambia lo
     * que esta encuentre. Una prueba que depende del orden en que corren las
     * demás no prueba nada: falla el día equivocado y por el motivo
     * equivocado.
     *
     * Y `aplicar()` a mano porque el proveedor corre antes que las
     * migraciones: en producción esto pasa solo al arrancar.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('lab.name', 'FABLAB EAN', 'laboratorio');
        Setting::put('lab.short_name', 'FABLAB EAN', 'laboratorio');

        LabSettings::aplicar();
    }

    /** Lo guardado pisa a `.env`, que es lo que hace administrable el nombre. */
    public function test_lo_guardado_pisa_al_entorno(): void
    {
        $this->assertSame('FABLAB EAN', config('fabos.lab.name'));
        $this->assertSame('FABLAB EAN', config('fabos.lab.short_name'));
    }

    /**
     * Y sigue siendo administrable: un nombre puesto a mano no se pisa.
     *
     * Es la diferencia entre poner un valor inicial y decidir por el
     * laboratorio. La migración corrige la grafía de fábrica; lo que alguien
     * escriba después manda.
     */
    public function test_lo_que_se_escriba_en_el_panel_manda(): void
    {
        Setting::put('lab.name', 'Fablab del Caribe', 'laboratorio');

        LabSettings::aplicar();

        $this->assertSame('Fablab del Caribe', config('fabos.lab.name'));
    }

    // ------------------------------------------------------------ cómo se pinta

    /**
     * FABLAB en fino y EAN en negra, como lo dibuja el logo.
     *
     * Sólo al pintarlo: el nombre guardado sigue siendo texto plano. Meter el
     * estilo dentro del ajuste lo arrastraría al asunto de un correo, al
     * nombre de un archivo y al título de la pestaña, donde no hay negrita que
     * valga y lo único que llegaría sería la basura que la marcara.
     */
    public function test_la_ultima_palabra_va_en_negra(): void
    {
        $this->get('/marca')
            ->assertOk()
            ->assertSee('<span class="fina">FABLAB</span> <span class="gruesa">EAN</span>', false);
    }

    /** El título de la pestaña se queda en texto plano, sin marcas. */
    public function test_el_titulo_de_la_pestana_no_lleva_marcas(): void
    {
        $html = $this->get('/marca')->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/s', $html, $coincide);

        $this->assertStringContainsString('FABLAB EAN', $coincide[1] ?? '');
        $this->assertStringNotContainsString('span', $coincide[1] ?? '');
    }

    /** Un nombre de una sola palabra no se parte por la mitad. */
    public function test_un_nombre_de_una_palabra_se_pinta_entero(): void
    {
        Setting::put('lab.name', 'Fablab', 'laboratorio');
        LabSettings::aplicar();

        $this->get('/marca')
            ->assertOk()
            ->assertSee('<span class="gruesa">Fablab</span>', false)
            ->assertDontSee('<span class="fina">', false);
    }
}
