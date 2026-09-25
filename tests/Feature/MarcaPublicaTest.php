<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La marca, en una página que se pasa por un enlace (§3).
 *
 * Quien diseña el afiche de un evento, el periodista que escribe la nota, la
 * empresa que nos pone en su web: todos piden lo mismo y todos lo piden por
 * mensaje. La respuesta era un correo con adjuntos, y lo que llegaba al otro
 * lado era la versión que tuviera a mano quien contestó —a veces el logo de
 * hace dos años, casi siempre un PNG recortado de una captura—.
 */
class MarcaPublicaTest extends TestCase
{
    use RefreshDatabase;

    private function conMarca(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/compacta.png', 'compacta');
        Storage::disk('public')->put('marca/larga-oscura.png', 'oscura');
        Storage::disk('public')->put('marca/variaciones/logo-vertical.svg', '<svg/>');

        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO, 'marca/compacta.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');
        Setting::put(Settings::MARCA_VARIACIONES, ['marca/variaciones/logo-vertical.svg'], 'comunicaciones');
    }

    /** Se abre sin cuenta: a quien se le pasa el enlace no tiene una. */
    public function test_se_abre_sin_cuenta(): void
    {
        $this->conMarca();
        $this->assertGuest();

        $this->get(route('marca.publica'))
            ->assertOk()
            ->assertSee('El logo de ' . config('fabos.lab.name'));
    }

    /**
     * Sale de lo mismo que se administra, no de una lista aparte.
     *
     * Es el punto entero: una segunda lista que hubiera que mantener al día
     * sería el problema que esto viene a resolver, con un paso más.
     */
    public function test_ensena_lo_que_se_subio_en_el_panel(): void
    {
        $this->conMarca();

        $this->get(route('marca.publica'))
            ->assertOk()
            ->assertSee('marca/larga.png', false)
            ->assertSee('marca/compacta.png', false)
            ->assertSee('marca/larga-oscura.png', false)
            ->assertSee('marca/variaciones/logo-vertical.svg', false);
    }

    /** Y lo que cambie en el panel cambia aquí, sin tocar nada más. */
    public function test_cambiar_el_logo_cambia_la_pagina(): void
    {
        $this->conMarca();

        $this->get(route('marca.publica'))->assertSee('marca/larga.png', false);

        Storage::disk('public')->put('marca/nueva.png', 'nueva');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/nueva.png', 'comunicaciones');

        $this->get(route('marca.publica'))
            ->assertSee('marca/nueva.png', false)
            ->assertDontSee('marca/larga.png', false);
    }

    /**
     * La versión de fondo oscuro se enseña sobre fondo oscuro.
     *
     * No es decoración: sobre blanco no se ve, y es la forma más rápida de que
     * alguien se lleve la equivocada creyendo que el archivo está roto.
     */
    public function test_cada_version_se_ve_sobre_su_fondo(): void
    {
        $this->conMarca();

        $this->get(route('marca.publica'))
            ->assertOk()
            ->assertSee('pieza sobre-oscuro', false);
    }

    // ----------------------------------------------------------- descargar todo

    public function test_se_baja_todo_en_un_archivo(): void
    {
        $this->conMarca();

        $respuesta = $this->get(route('marca.publica.zip'))->assertOk();
        $respuesta->assertHeader('content-type', 'application/zip');

        $zip = new \ZipArchive();
        $archivo = tempnam(sys_get_temp_dir(), 'prueba');
        file_put_contents($archivo, $respuesta->streamedContent());
        $zip->open($archivo);

        $dentro = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $dentro[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($archivo);

        sort($dentro);

        /*
         * Con nombre en palabras, no con el identificador del disco.
         *
         * El identificador es correcto dentro del disco —evita que dos subidas
         * se pisen— e inservible en la carpeta de descargas de otra persona,
         * que es donde va a acabar: cuatro archivos llamados «01M3CZ8GED…svg»
         * y ninguna forma de saber cuál es cuál. Las variaciones ya traen
         * nombre propio y se quedan como están.
         */
        $marca = \Illuminate\Support\Str::slug((string) config('fabos.lab.name'));

        $this->assertSame([
            "{$marca}-compacta.png",
            "{$marca}-horizontal-sobre-oscuro.png",
            "{$marca}-horizontal.png",
            'logo-vertical.svg',
        ], $dentro);
    }

    /** Sin marca subida la página se abre igual, y lo dice. */
    public function test_sin_nada_subido_no_revienta(): void
    {
        Storage::fake('public');

        $this->get(route('marca.publica'))
            ->assertOk()
            ->assertSee('Todavía no hay archivos publicados');

        $this->get(route('marca.publica.zip'))->assertOk();
    }

    /** Y se llega desde el pie del sitio, que es donde se busca. */
    public function test_el_pie_del_sitio_lleva_a_la_marca(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('marca.publica'), false)
            ->assertSee('La marca');
    }
}
