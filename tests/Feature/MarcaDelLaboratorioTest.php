<?php

namespace Tests\Feature;

use App\Filament\Pages\Marca;
use App\Models\Setting;
use App\Models\User;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El logo, administrable, y en los documentos que se mandan (§3, §11).
 *
 * Estaba en un archivo del repositorio, así que cambiarlo exigía un
 * despliegue: una marca se retoca, y quien la tiene no es quien tiene acceso
 * al servidor. Y no salía en los PDF, que son justo los papeles que acaban
 * delante de quien decide.
 */
class MarcaDelLaboratorioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($u);
        $factores->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ------------------------------------------------------------ el ajuste

    public function test_sin_logo_propio_vale_el_que_trae_el_sistema(): void
    {
        Storage::fake('public');

        $this->assertNull(Settings::logo());

        // Y para el PDF, incrustado: el del archivo de configuración.
        $this->assertStringStartsWith('data:image/png;base64,', (string) Settings::logoParaPdf());
    }

    public function test_el_logo_subido_manda_sobre_el_del_archivo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/ean.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        Setting::put(Settings::MARCA_LOGO, 'marca/ean.svg', 'comunicaciones');

        $this->assertSame('marca/ean.svg', Settings::logo());
        // Por la extensión, que un SVG suele llegar como «text/plain» y en el
        // PDF no se pintaría.
        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) Settings::logoParaPdf());
    }

    /** Un ajuste que apunta a un archivo que ya no está no rompe la página. */
    public function test_un_logo_que_ya_no_existe_se_ignora(): void
    {
        Storage::fake('public');
        Setting::put(Settings::MARCA_LOGO, 'marca/borrado.png', 'comunicaciones');

        $this->assertNull(Settings::logo());
        $this->assertStringStartsWith('data:image/png;base64,', (string) Settings::logoParaPdf());
    }

    // ------------------------------------------------------------ la pantalla

    public function test_se_sube_desde_comunicaciones_y_reemplaza_al_anterior(): void
    {
        Storage::fake('public');
        $this->admin();

        Storage::disk('public')->put('marca/viejo.png', 'png');
        Setting::put(Settings::MARCA_LOGO, 'marca/viejo.png', 'comunicaciones');

        $pantalla = Livewire::test(Marca::class);

        // El campo llega con el que ya había (el componente lo guarda en un
        // array con su propia clave, no como una cadena suelta).
        $this->assertContains('marca/viejo.png', (array) $pantalla->get('datos.logo'));

        $pantalla
            ->fillForm(['logo' => [UploadedFile::fake()->image('nuevo.png', 400, 120)]])
            ->call('save');

        $guardado = Settings::logo();

        $this->assertNotNull($guardado);
        $this->assertNotSame('marca/viejo.png', $guardado);

        // El viejo se va: un disco lleno de logos que ya nadie usa no hay
        // forma de limpiarlo después sin adivinar cuál es cuál.
        Storage::disk('public')->assertMissing('marca/viejo.png');
        Storage::disk('public')->assertExists($guardado);
    }

    public function test_la_pagina_es_del_backoffice(): void
    {
        $this->get(Marca::getUrl())->assertRedirect();

        $this->admin();
        $this->get(Marca::getUrl())->assertOk()->assertSee('El logo');
    }

    // ---------------------------------------------------------------- dónde sale

    public function test_el_sitio_publico_usa_el_logo_subido(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/ean.png', 'png');
        Setting::put(Settings::MARCA_LOGO, 'marca/ean.png', 'comunicaciones');

        $this->get('/')
            ->assertOk()
            ->assertSee('marca/ean.png', false)
            ->assertDontSee('img/fablabean.png', false);
    }

    /**
     * Y el icono de la pestaña, que iba por su cuenta.
     *
     * El fallo, tal cual salió: el logo subido se veía en la barra del sitio y
     * en los PDF, pero el favicon apuntaba siempre a los archivos de
     * `public/img`. Quien cambiaba la marca la veía cambiada arriba y seguía
     * viendo la vieja en la pestaña, en la misma pantalla.
     */
    public function test_el_icono_de_la_pestana_tambien_es_el_subido(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/ean.svg', '<svg/>');
        Setting::put(Settings::MARCA_LOGO, 'marca/ean.svg', 'comunicaciones');

        $this->get('/')
            ->assertOk()
            // Con su tipo: un SVG anunciado como «text/plain» el navegador lo
            // descarta sin decir nada y la pestaña se queda como estaba.
            ->assertSee('type="image/svg+xml"', false)
            ->assertSee('marca/ean.svg', false)
            // Y el de antes ya no está compitiendo por el mismo sitio.
            ->assertDontSee('img/favicon-32.png', false);
    }

    /** Sin nada subido, los iconos de siempre: no se queda sin icono. */
    public function test_sin_logo_subido_siguen_los_iconos_de_siempre(): void
    {
        Storage::fake('public');

        $this->get('/')
            ->assertOk()
            ->assertSee('img/favicon-32.png', false)
            ->assertSee('favicon.ico', false);
    }

    /** El panel es el mismo caso: tenía su propio camino al archivo fijo. */
    public function test_el_panel_usa_el_logo_subido(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/ean.svg', '<svg/>');
        Setting::put(Settings::MARCA_LOGO, 'marca/ean.svg', 'comunicaciones');

        $marca = Settings::logoParaLaWeb();

        $this->assertStringContainsString('marca/ean.svg', $marca['url']);
        $this->assertSame('image/svg+xml', $marca['tipo']);
        $this->assertTrue($marca['svg']);

        $panel = \Filament\Facades\Filament::getPanel('admin');

        $this->assertStringContainsString('marca/ean.svg', (string) $panel->getBrandLogo());
        $this->assertStringContainsString('marca/ean.svg', (string) $panel->getFavicon());
    }

    // ------------------------------------------------------- las dos versiones

    /**
     * Cada versión donde le sienta la forma.
     *
     * Una marca suele venir en dos: la horizontal, con el nombre dentro, y la
     * compacta, que es el símbolo solo. Con una sola casilla había que elegir,
     * y la elegida quedaba mal en la mitad de los sitios: la larga aplastada
     * en el cuadrado del móvil, o la compacta perdida en una cabecera ancha.
     */
    public function test_la_larga_manda_en_el_pdf_y_la_compacta_en_la_pestana(): void
    {
        // En PNG a propósito: un SVG se inserta en línea y su ruta no llega al
        // HTML, así que no se podría comprobar que cada hueco lleva la suya.
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/compacta.png', 'compacta');
        Setting::put(Settings::MARCA_LOGO, 'marca/compacta.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');

        // La pestaña es un cuadrado de dieciséis píxeles: la compacta.
        $this->assertStringContainsString('marca/compacta.png', Settings::logoParaLaWeb()['url']);

        // La cabecera de un documento es ancha y baja: la larga.
        $this->assertStringContainsString(base64_encode('larga'), (string) Settings::logoParaPdf());

        // Y la barra lleva las dos, para que el CSS elija según el ancho.
        $this->get('/')
            ->assertOk()
            ->assertSee('marca/larga.png', false)
            ->assertSee('marca/compacta.png', false);
    }

    /** Con una sola subida, esa vale para todo: media marca es peor. */
    public function test_con_una_sola_version_esa_sale_en_todas_partes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'png');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');

        $this->assertStringContainsString('marca/larga.png', Settings::logoParaLaWeb()['url']);

        $this->get('/')
            ->assertOk()
            ->assertSee('marca/larga.png', false)
            ->assertDontSee('img/fablabean.png', false);
    }

    /**
     * El alto se manda y el ancho sale solo.
     *
     * Al revés no funciona: una marca horizontal y una cuadrada no comparten
     * ancho, y fijarlo aplastaba una de las dos.
     */
    public function test_el_alto_se_manda_y_el_ancho_sale_solo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'png');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_ALTO, 54, 'comunicaciones');

        $this->get('/')
            ->assertOk()
            ->assertSee('--marca-alto:54px', false)
            ->assertSee('height:var(--marca-alto)', false)
            ->assertSee('width:auto', false);
    }

    /** Un alto absurdo no rompe la barra: se recorta a lo razonable. */
    public function test_un_alto_absurdo_se_recorta(): void
    {
        Setting::put(Settings::MARCA_ALTO, 4000, 'comunicaciones');
        $this->assertSame(120, Settings::altoDeLaMarca());

        Setting::put(Settings::MARCA_ALTO, 0, 'comunicaciones');
        $this->assertSame(Settings::ALTO_POR_DEFECTO, Settings::altoDeLaMarca());
    }

    /** El nombre al lado es opcional: una marca larga ya lo lleva dentro. */
    public function test_el_nombre_al_lado_se_puede_apagar(): void
    {
        $this->get('/')->assertOk()->assertSee(config('fabos.lab.name'), false);

        Setting::put(Settings::MARCA_CON_TEXTO, false, 'comunicaciones');

        $this->get('/')->assertOk()->assertDontSee('class="palabra"', false);
    }

    /**
     * El mismo archivo en las dos casillas no se borra solo.
     *
     * Subir uno que sirve para las dos cosas es legítimo, y borrarlo al
     * guardar la otra casilla dejaría las dos rotas.
     */
    public function test_el_archivo_compartido_no_se_borra(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/unico.svg', '<svg/>');
        Setting::put(Settings::MARCA_LOGO, 'marca/unico.svg', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/unico.svg', 'comunicaciones');

        $this->admin();

        // Las dos casillas se llenan solas al montar, con lo que hay guardado:
        // pasarles una cadena a mano no es como le llega el estado a un
        // FileUpload y la comprobación no valdría.
        Livewire::test(Marca::class)
            ->set('datos.alto', 40)
            ->set('datos.con_texto', false)
            ->call('save');

        Storage::disk('public')->assertExists('marca/unico.svg');
        $this->assertSame(40, Settings::altoDeLaMarca());
        $this->assertFalse(Settings::marcaConTexto());
    }

    // ------------------------------------------------------------ fondo oscuro

    /**
     * Las cuatro combinaciones van en la página y el CSS elige.
     *
     * Un logo está dibujado para un fondo: el mismo archivo sobre el contrario
     * se pierde, y aclararlo con un filtro le quita los colores.
     */
    public function test_las_dos_versiones_llevan_su_variante_oscura(): void
    {
        Storage::fake('public');

        foreach (['larga', 'compacta', 'larga-oscura', 'compacta-oscura'] as $cual) {
            Storage::disk('public')->put("marca/{$cual}.png", $cual);
        }

        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO, 'marca/compacta.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_OSCURO, 'marca/compacta-oscura.png', 'comunicaciones');

        $this->get('/')
            ->assertOk()
            ->assertSee('class="larga clara"', false)
            ->assertSee('class="compacta clara"', false)
            ->assertSee('class="larga oscura"', false)
            ->assertSee('class="compacta oscura"', false)
            ->assertSee('marca/larga-oscura.png', false)
            ->assertSee('marca/compacta-oscura.png', false);
    }

    /**
     * Falta la compacta oscura: se usa la larga oscura, no la compacta clara.
     *
     * El orden del respaldo importa. Una larga oscura apretada en el móvil se
     * ve mal pero se ve; una compacta clara sobre fondo oscuro no se ve en
     * absoluto. Entre pasarlo mal y desaparecer, pasarlo mal.
     */
    public function test_el_respaldo_se_queda_en_la_familia_oscura(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/compacta.png', 'compacta');
        Storage::disk('public')->put('marca/larga-oscura.png', 'oscura');
        Setting::put(Settings::MARCA_LOGO, 'marca/compacta.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');
        Setting::put(Settings::MARCA_BARRA, '#171A15', 'comunicaciones');

        // Barra oscura y sin compacta oscura: el hueco del móvil lo llena la
        // larga oscura. Se mira el hueco y no la página entera, porque el
        // icono de la pestaña tiene su propia cadena —no le afecta el modo—
        // y ahí sí sale la compacta clara, que es lo correcto.
        $oscura = Storage::disk('public')->url('marca/larga-oscura.png');

        $this->get('/')
            ->assertOk()
            ->assertSee('class="compacta clara"><img src="' . $oscura, false)
            ->assertSee('class="larga clara"><img src="' . $oscura, false);
    }

    /** Y sin ninguna variante oscura, la clara: es lo que había. */
    public function test_sin_variante_oscura_vale_la_clara(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');

        $this->assertNull(Settings::logoLargoOscuro());

        // Las cuatro ranuras existen igual, todas con el mismo archivo: la
        // marca no puede desaparecer en modo oscuro por no haber subido otra.
        $this->get('/')
            ->assertOk()
            ->assertSee('class="larga oscura"', false)
            ->assertSee('marca/larga.png', false);
    }

    /**
     * Con un color fijo en la barra manda ese color, no el modo del sistema.
     *
     * Es el cruce que había que resolver: un color puesto a mano es el mismo
     * para todo el mundo. Sin esto, una barra en negro enseñaría el logo claro
     * sólo a quien tenga el sistema en modo oscuro, y el negro sobre negro al
     * resto.
     */
    public function test_la_barra_oscura_fija_impone_la_version_oscura(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/larga-oscura.png', 'oscura');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');
        Setting::put(Settings::MARCA_BARRA, '#171A15', 'comunicaciones');

        $this->assertSame('oscuro', Settings::modoDeLaBarra());

        // Una sola pareja, y es la oscura: dejar las dos y que decidiera el
        // CSS por el modo del sistema es justo el fallo que esto evita.
        $oscura = Storage::disk('public')->url('marca/larga-oscura.png');
        $clara = Storage::disk('public')->url('marca/larga.png');

        $this->get('/')
            ->assertOk()
            ->assertSee('class="larga clara"><img src="' . $oscura, false)
            ->assertDontSee('class="larga clara"><img src="' . $clara, false)
            // Y no se manda la otra pareja: con el color fijo no hay nada que
            // decidir en el navegador.
            ->assertDontSee('class="larga oscura"', false);
    }

    /** Y una barra clara fija impone la clara, por el mismo motivo. */
    public function test_la_barra_clara_fija_impone_la_version_clara(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/larga-oscura.png', 'oscura');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');
        Setting::put(Settings::MARCA_BARRA, '#F6F6F2', 'comunicaciones');

        $this->assertSame('claro', Settings::modoDeLaBarra());

        $this->get('/')
            ->assertOk()
            ->assertSee('marca/larga.png', false)
            ->assertDontSee('marca/larga-oscura.png', false);
    }

    /** Sin color fijo, manda el sistema de quien mira. */
    public function test_sin_color_fijo_manda_el_sistema(): void
    {
        $this->assertSame('auto', Settings::modoDeLaBarra());
    }

    /** El PDF se queda en la clara: el papel es blanco. */
    public function test_el_pdf_no_usa_la_version_oscura(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/larga-oscura.png', 'oscura');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO_OSCURO, 'marca/larga-oscura.png', 'comunicaciones');

        $this->assertStringContainsString(base64_encode('larga'), (string) Settings::logoParaPdf());
        $this->assertStringNotContainsString(base64_encode('oscura'), (string) Settings::logoParaPdf());
    }

    // ----------------------------------------------------- el icono de la pestaña

    /**
     * El icono tiene su propio archivo, y manda sobre los otros dos.
     *
     * Un favicon no es un logo pequeño: se ve a dieciséis píxeles, donde un
     * trazo fino desaparece y dos colores parecidos se funden en uno. Lo que
     * funciona ahí suele ser otro dibujo, no la marca encogida.
     */
    public function test_el_icono_propio_manda_sobre_las_dos_versiones(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/compacta.png', 'compacta');
        Storage::disk('public')->put('marca/icono.png', 'icono');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO, 'marca/compacta.png', 'comunicaciones');
        Setting::put(Settings::MARCA_FAVICON, 'marca/icono.png', 'comunicaciones');

        $this->assertStringContainsString('marca/icono.png', Settings::logoParaLaWeb()['url']);

        // Y no se cuela en la barra ni en el PDF, que no son su sitio.
        $this->assertStringContainsString(base64_encode('larga'), (string) Settings::logoParaPdf());
        $this->get('/')->assertOk()->assertDontSee('class="larga"><img src="' . Storage::disk('public')->url('marca/icono.png'), false);
    }

    /** Sin icono propio, la compacta; sin compacta, la larga. */
    public function test_el_icono_cae_a_la_compacta_y_luego_a_la_larga(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/larga.png', 'larga');
        Storage::disk('public')->put('marca/compacta.png', 'compacta');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/larga.png', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO, 'marca/compacta.png', 'comunicaciones');

        $this->assertStringContainsString('marca/compacta.png', Settings::logoParaLaWeb()['url']);

        Setting::put(Settings::MARCA_LOGO, '', 'comunicaciones');

        $this->assertStringContainsString('marca/larga.png', Settings::logoParaLaWeb()['url']);
    }

    /**
     * Un archivo que deja de usarse se va; uno que sigue en otra casilla, no.
     *
     * El disco se limpia comparando contra las tres casillas a la vez y no de
     * una en una: el mismo archivo en dos sitios es legítimo, y borrarlo al
     * guardar la otra dejaría las dos rotas.
     */
    public function test_el_disco_se_limpia_sin_llevarse_lo_compartido(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/compartido.svg', '<svg/>');
        Storage::disk('public')->put('marca/sobra.svg', '<svg/>');
        Setting::put(Settings::MARCA_LOGO, 'marca/compartido.svg', 'comunicaciones');
        Setting::put(Settings::MARCA_LOGO_LARGO, 'marca/compartido.svg', 'comunicaciones');
        Setting::put(Settings::MARCA_FAVICON, 'marca/sobra.svg', 'comunicaciones');

        $this->admin();

        Livewire::test(Marca::class)
            ->set('datos.favicon', null)
            ->call('save');

        // El compartido sigue en dos casillas: se queda.
        Storage::disk('public')->assertExists('marca/compartido.svg');
        // El que se quitó no lo usa nadie: se va.
        Storage::disk('public')->assertMissing('marca/sobra.svg');
    }

    // ------------------------------------------------------ el color de la barra

    /**
     * Se elige el fondo y el texto sale de él.
     *
     * Es lo que evita el fallo clásico: un fondo oscuro elegido con gusto y,
     * encima, los enlaces grises de siempre, ilegibles. La barra quedaría de
     * adorno y nadie podría usarla.
     */
    public function test_sobre_un_fondo_oscuro_se_escribe_en_claro(): void
    {
        Setting::put(Settings::MARCA_BARRA, '#171A15', 'comunicaciones');

        $this->assertTrue(Settings::colorDeLaBarra()['oscuro']);

        $this->get('/')
            ->assertOk()
            ->assertSee('--barra-fondo:#171A15', false)
            ->assertSee('--barra-ink:#F5F6F0', false);
    }

    public function test_sobre_un_fondo_claro_se_escribe_en_oscuro(): void
    {
        Setting::put(Settings::MARCA_BARRA, '#F6F6F2', 'comunicaciones');

        $this->assertFalse(Settings::colorDeLaBarra()['oscuro']);

        $this->get('/')->assertOk()->assertSee('--barra-ink:#191A16', false);
    }

    /**
     * El peso de cada canal no es el mismo.
     *
     * Un promedio simple da por claro un azul intenso —#0000FF— y encima se
     * escribiría en negro, que no se lee. La luminancia de la WCAG pesa el
     * verde mucho y el azul casi nada, que es como lo ve el ojo.
     */
    public function test_un_azul_intenso_cuenta_como_oscuro(): void
    {
        Setting::put(Settings::MARCA_BARRA, '#0000FF', 'comunicaciones');
        $this->assertTrue(Settings::colorDeLaBarra()['oscuro']);

        // Y un amarillo, que tiene el mismo promedio, no.
        Setting::put(Settings::MARCA_BARRA, '#FFFF00', 'comunicaciones');
        $this->assertFalse(Settings::colorDeLaBarra()['oscuro']);
    }

    /** Sin color elegido no se pinta nada: manda el tema, como hasta ahora. */
    public function test_sin_color_elegido_manda_el_tema(): void
    {
        $this->assertNull(Settings::colorDeLaBarra());

        $this->get('/')->assertOk()->assertDontSee('--barra-fondo', false);
    }

    /** Y un valor que no es un color se ignora en vez de romper la barra. */
    public function test_un_color_invalido_se_ignora(): void
    {
        foreach (['azul', '#FFF', 'rgb(0,0,0)', '#GGGGGG', ''] as $basura) {
            Setting::put(Settings::MARCA_BARRA, $basura, 'comunicaciones');
            $this->assertNull(Settings::colorDeLaBarra(), "«{$basura}» no debería pasar");
        }
    }

    /**
     * Y el PDF de la propuesta sigue saliendo con el logo dentro.
     *
     * Es la comprobación que importa de verdad: una imagen incrustada que el
     * generador no sepa pintar no deja un hueco, deja un error y ningún PDF.
     */
    public function test_la_propuesta_en_pdf_se_genera_con_el_logo_dentro(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('marca/ean.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));
        Setting::put(Settings::MARCA_LOGO, 'marca/ean.png', 'comunicaciones');

        $proyecto = \App\Services\Projects\ProjectService::class;
        $proyecto = app($proyecto)->registrarIdea(['name' => 'Señalética', 'summary' => 'Diez piezas.']);

        $admin = $this->admin();
        $proyecto->update(['lead_id' => $admin->id]);

        $respuesta = $this->get(route('proyectos.propuesta.pdf', $proyecto))->assertOk();

        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }
}
