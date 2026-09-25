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
